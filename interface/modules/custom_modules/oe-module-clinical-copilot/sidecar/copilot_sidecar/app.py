"""FastAPI surface of the sidecar.

POST /run       the graph (contracts run.request / run.response / run.error)
GET  /health    liveness, model and parser versions (contract sidecar.health.response)
GET  /ready     readiness: every local dependency a run needs, checked for real
                (contract sidecar.ready.response); 503 when one is missing
POST /eval/anchor   test-only (COPILOT_EVAL_ENDPOINTS=1): a fixture path
                    and a recorded proposal through anchor.py, no model call

The sidecar holds no PHI at rest: documents arrive as bytes in the request
and leave as extractions in the response; the idempotency cache keeps
responses (no bytes) in memory for ten minutes keyed by correlation id
and document hash. Logs carry only allowlisted fields (see logging_setup).

The path of one /run request:

  PHP -> POST /run -> read JSON, bind correlation id -> RunRequest validation
      -> idempotency cache hit? return it
      -> graph.run on a worker thread (extract and/or retrieve)
      -> RunResponse JSON -> store in cache -> PHP

Notes for readers new to Python and FastAPI:

* `@app.get("/health")` written above a function is a decorator: it
  registers that function as the handler for GET /health. What the function
  returns becomes the HTTP response body.
* `async def` marks a function the server may pause while it waits (for the
  request body, for a worker thread) and serve other requests meanwhile;
  `await` is each pause point. A plain `def` handler is run by FastAPI on a
  worker thread instead, which is fine for the short checks here.
* Incoming JSON is checked against the Pydantic models in schemas.py, which
  mirror the contract files; a request that breaks a rule is answered with a
  fixed error code and never reaches the graph.
"""

from __future__ import annotations

import hashlib
import json
import logging
import os
import shutil
import time
from pathlib import Path

from fastapi import FastAPI, HTTPException, Request
from fastapi.responses import JSONResponse
from pydantic import BaseModel, ValidationError
from starlette.concurrency import run_in_threadpool

from . import anchor, contracts, extractor, graph
from . import retrieve as retrieve_module
from .llm import PROMPT_VERSION
from .logging_setup import bind_correlation_id, setup_logging
from .schemas import IntakeFormProposal, LabReportProposal, RunError, RunRequest, RunResponse, SidecarHealth, SidecarReady

# Module-level statements run once, when the process imports this file:
# logging is configured before the first log line, and `app` is the object
# uvicorn serves. docs_url=None switches off the interactive API pages, so
# the contract files stay the only description of the API.
setup_logging()
log = logging.getLogger("copilot.app")
app = FastAPI(title="clinical-copilot-sidecar", docs_url=None, redoc_url=None)


@app.middleware("http")
async def correlate(request: Request, call_next):
    """Binds the caller's X-Correlation-Id before anything else runs, so even a
    request rejected as malformed logs under the caller's id; /run rebinds to
    the body's id (the contract's authority) once the body has parsed.

    A middleware wraps every request: this code runs first, then `call_next`
    hands the request to the matching endpoint and returns its response.
    bind_correlation_id stores the id in a context variable, a value that
    follows this one request through the code without being passed as an
    argument, so every log line it produces carries the id."""
    bind_correlation_id(request.headers.get("x-correlation-id", ""))
    return await call_next(request)

# ---- Idempotency cache ----------------------------------------------------
# PHP may send the same run twice (a timed-out HTTP call that is retried, a
# double click). Each repeat would pay for the model calls again and could
# come back with a slightly different extraction. So a finished response is
# remembered for ten minutes under a key built from the request itself, and
# a repeat inside that window gets the identical response without running
# anything. The cache is a plain in-memory dictionary, key -> (time stored,
# response JSON). It holds no document bytes and dies with the process.
IDEMPOTENCY_TTL_S = 600
_cache: dict[str, tuple[float, dict]] = {}


def _cache_key(req: RunRequest) -> str:
    """SHA-256 over what makes two requests the same work: the correlation
    id, the mode, the question, and each document's id, status and content
    hash. The file bytes are not hashed again; the sha3_512 PHP computed
    stands in for them. facts_hash is not part of the key."""
    h = hashlib.sha256()
    h.update(req.correlation_id.encode())
    h.update(req.mode.encode())
    h.update((req.question or "").encode())
    for d in req.documents:
        h.update(f"{d.document_id}:{d.status}:{d.sha3_512}".encode())
    return h.hexdigest()


def _error(correlation_id: str, code: str, status: int) -> JSONResponse:
    """The run.error body: an id and a fixed code, never a message, so no
    internal detail reaches the caller. `status` is the HTTP status code."""
    return JSONResponse(status_code=status, content=RunError(correlation_id=correlation_id, code=code).model_dump())


# ---- Operator endpoints ----------------------------------------------------
# Two different questions an operator (or Docker, or PHP's ready.php) asks:
#   /health  "Is the process alive?" Answers 200 whenever it can answer at
#            all and checks nothing. This is the liveness probe, so a slow or
#            missing dependency never gets the container killed and restarted
#            in a loop.
#   /ready   "Could a run succeed right now?" Exercises every local
#            dependency and answers 503 until all are usable, so traffic is
#            not sent to a sidecar that is missing its contracts, its corpus
#            index or its OCR binary.


@app.get("/health")
def health() -> dict:
    """Liveness: the process answers. Nothing is checked. The body reports
    the prompt version, model name and parser so an operator can confirm
    which build is running."""
    return SidecarHealth(prompt_version=PROMPT_VERSION, model=os.environ.get("OPENAI_MODEL", "gpt-4o-mini"), parser="pymupdf+tesseract").model_dump()


def _readiness() -> tuple[bool, dict[str, str]]:
    """Each required dependency is exercised the way a run exercises it: the
    contract file is loaded, the LOINC map parsed, the retrieval index read
    and checked against its chunk list, the tesseract binary looked up, the
    OpenAI key looked up. No network: the provider is probed by PHP's
    ready.php once for the whole service. Reasons are fixed strings; an
    exception's message never leaves the process."""
    # A table of name -> function that takes no arguments. `lambda:` is an
    # unnamed one-line function; calling it performs the check, and each
    # check signals failure by raising an exception.
    checks = {
        "contracts": lambda: contracts.load(contracts.PROPOSAL_CONTRACT["lab_pdf"]),
        "loinc_map": anchor.load_loinc_map,
        "corpus_index": retrieve_module.index,
        # shutil.which looks for the program on PATH and returns None when it
        # is absent. The `(_ for _ in ()).throw(...)` part is a Python trick
        # to raise an exception from inside a lambda (which cannot contain a
        # `raise` statement), so a missing binary fails like the other checks.
        "tesseract": lambda: shutil.which("tesseract") or (_ for _ in ()).throw(RuntimeError("tesseract missing")),
    }
    deps: dict[str, str] = {}
    for name, check in checks.items():
        try:
            check()
            deps[name] = "ok"
        except Exception:
            # The exception is deliberately discarded: its text could name a
            # file path, and the contract wants a fixed reason string.
            deps[name] = f"{name} unavailable"
    # Presence only: whether the key works is PHP's ready.php's job.
    deps["openai_key"] = "ok" if os.environ.get("OPENAI_API_KEY") else "openai key not configured"
    return all(v == "ok" for v in deps.values()), deps


@app.get("/ready")
def ready() -> JSONResponse:
    """Readiness: 200 when every required dependency is usable, 503 otherwise.
    Cohere rerank is reported under `optional`: a run works without it."""
    ok, deps = _readiness()
    body = SidecarReady(
        status="ready" if ok else "not_ready",
        dependencies=deps,  # type: ignore[arg-type]
        optional={"cohere_rerank": "configured" if os.environ.get("COHERE_API_KEY") else "not configured"},  # type: ignore[arg-type]
        time=time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
    )
    return JSONResponse(status_code=200 if ok else 503, content=body.model_dump())


@app.post("/run")
async def run(request: Request) -> JSONResponse:
    """The main endpoint: validate, consult the idempotency cache, run the
    graph off the event loop, cache and return the response. Every failure
    path answers in the run.error shape with a fixed code."""
    # A monotonic clock only moves forward (never adjusted like wall time), so
    # differences from it are safe to report as durations.
    started = time.monotonic()
    # The body is read by hand rather than declared as a typed parameter so
    # that a malformed body and a contract violation are both answered in the
    # run.error shape, under the caller's correlation id when one is readable.
    try:
        body = await request.json()
    except Exception:
        log.info("run rejected", extra={"code": "bad_request"})
        return _error("unknown", "bad_request", 400)
    # The body's correlation id outranks the header's: rebind as soon as it is
    # readable so the validation failure below, if any, logs under it.
    if isinstance(body, dict) and isinstance(body.get("correlation_id"), str):
        bind_correlation_id(body["correlation_id"])
    try:
        req = RunRequest.model_validate(body)
    except ValidationError:
        log.info("run rejected", extra={"code": "bad_request"})
        return _error(str(body.get("correlation_id", "unknown")) if isinstance(body, dict) else "unknown", "bad_request", 422)

    # Idempotency: a repeat of a run finished less than ten minutes ago gets
    # the stored response back without running anything (see the cache note).
    key = _cache_key(req)
    now = time.monotonic()
    hit = _cache.get(key)
    if hit and now - hit[0] < IDEMPOTENCY_TTL_S:
        log.info("run served from cache", extra={"mode": req.mode})
        return JSONResponse(content=hit[1])

    try:
        # The graph is synchronous (model calls, OCR, retrieval). Running it on
        # the thread pool keeps the event loop free, so concurrent runs overlap
        # instead of queueing behind each other: the 2026-09-23 load baseline
        # showed one blocked loop turning ten concurrent 12 s extractions into
        # 60 s timeouts and ten 2.7 s retrievals into a 19 s follow-up p50.
        # Each invocation carries its own state; the compiled graph is shared
        # read-only. Context variables (the correlation id) propagate to the
        # worker thread.
        #
        # In plain words: the event loop is the one thread that takes turns
        # serving every request. A plain function call from an `async def`
        # handler would hold that thread for the whole graph run (many
        # seconds), and every other request would wait. run_in_threadpool
        # hands the call to a separate worker thread and `await` lets the
        # loop serve others until that thread is done.
        state = await run_in_threadpool(graph.run, req.mode, req.correlation_id, req.facts_hash, req.question, req.documents)
    except Exception as exc:  # never leak a traceback; the code is the message
        log.error("run failed", extra={"mode": req.mode, "code": "internal", "exception_class": type(exc).__name__, "ms": int((time.monotonic() - started) * 1000)})
        return _error(req.correlation_id, "internal", 500)
    # Build the contract response from the graph's final state. by_alias=True
    # writes handoffs with the key "from" (the Python attribute is from_);
    # json.loads turns the text back into plain data for JSONResponse and
    # for the cache.
    resp = RunResponse(correlation_id=req.correlation_id, extractions=state["extractions"], chunks=state["chunks"], handoffs=state["handoffs"], usage=state["usage"])
    payload = json.loads(resp.model_dump_json(by_alias=True))
    log.info("run", extra={"mode": req.mode, "hops": len(state["handoffs"]), "count": len(state["extractions"]) + len(state["chunks"]), "ms": int((time.monotonic() - started) * 1000)})
    # Store the response, then drop every entry older than the TTL. The sweep
    # runs on writes only, so an idle process keeps stale entries until the
    # next run; they are small.
    _cache[key] = (now, payload)
    for k in [k for k, (t, _) in _cache.items() if now - t >= IDEMPOTENCY_TTL_S]:
        _cache.pop(k, None)
    return JSONResponse(content=payload)


# ---- Test-only endpoints ---------------------------------------------------
# These exist for the eval harness (tools/, tests/, the module's eval cases)
# and are registered only when the process starts with
# COPILOT_EVAL_ENDPOINTS=1: the `if` further down wraps every route
# definition, so on a deployment the routes do not exist at all (a request
# gets 404), not merely disabled. They read fixture files by a name relative
# to FIXTURES_ROOT and refuse any path that resolves outside that directory.
# The request models below are defined unconditionally; they are harmless
# on their own.

FIXTURES_ROOT = Path(os.environ.get("COPILOT_FIXTURES_DIR", "/fixtures")).resolve()


class AnchorEvalRequest(BaseModel):
    """A fixture file plus a recorded model reply, so anchoring can be
    exercised without a model call."""
    fixture: str  # file name relative to FIXTURES_ROOT
    doc_type: str  # "lab_pdf" or anything else, which is treated as intake_form
    proposal: dict  # the recorded model reply, as plain JSON data
    document_id: int = 1
    question: str | None = None  # /eval/phi only: also run the retrieval leg (keyword leg, no embeddings call)


class RetrieveEvalRequest(BaseModel):
    query: str
    embedding: list[float] | None = None  # committed query embedding; absent means call the embeddings API


class RouteEvalDocument(BaseModel):
    """A document description without bytes: routing never reads the file."""
    document_id: int = 1
    doc_type: str  # deliberately not the enum: routing must refuse unsupported types itself
    status: str
    sha3_512: str = "0" * 128
    bytes_base64: str | None = None


class RouteEvalRequest(BaseModel):
    mode: str
    question: str | None = None
    documents: list[RouteEvalDocument] = []


if os.environ.get("COPILOT_EVAL_ENDPOINTS") == "1":

    @app.post("/eval/anchor")
    def eval_anchor(req: AnchorEvalRequest) -> dict:
        """Anchors a recorded proposal against a fixture PDF and returns the
        extraction, so anchoring accuracy can be scored with no model call."""
        # .resolve() collapses any ".." in the name; a path whose parents do
        # not include the fixtures directory has escaped it and is refused.
        path = (FIXTURES_ROOT / req.fixture).resolve()
        if FIXTURES_ROOT not in path.parents and path != FIXTURES_ROOT:
            raise HTTPException(400, "fixture outside the fixtures directory")
        if not path.is_file():
            raise HTTPException(404, "fixture not found")
        model = LabReportProposal if req.doc_type == "lab_pdf" else IntakeFormProposal
        proposal = model.model_validate(req.proposal)
        outcome = extractor.extract(req.document_id, req.doc_type, path.read_bytes(), "eval-anchor", proposal=proposal)
        return json.loads(outcome.extraction.model_dump_json())

    @app.post("/eval/anchor-absent")
    def eval_anchor_absent(req: AnchorEvalRequest) -> dict:
        """Anchors a proposal whose items the document does not contain: every
        one must come back unverified. The harness uses this to prove the
        anchor step refuses invented values, not only that it accepts real ones."""
        path = (FIXTURES_ROOT / req.fixture).resolve()
        if FIXTURES_ROOT not in path.parents or not path.is_file():
            raise HTTPException(404, "fixture not found")
        model = LabReportProposal if req.doc_type == "lab_pdf" else IntakeFormProposal
        outcome = extractor.extract(req.document_id, req.doc_type, path.read_bytes(), "eval-anchor-absent", proposal=model.model_validate(req.proposal))
        ext = outcome.extraction.extraction
        if ext is None:
            return {"status": outcome.extraction.status, "anchored": None}
        # Collect the names of every value that did anchor; the harness expects
        # this list to be empty for a fixture that contains none of them.
        if req.doc_type == "lab_pdf":
            anchored = [r.analyte for r in ext.results if r.citation.anchored]
        else:
            anchored = [m.name for m in ext.medications if m.citation.anchored] + [a.substance for a in ext.allergies if a.citation.anchored] + [f.condition for f in ext.family_history if f.citation.anchored]
            if ext.chief_concern is not None and ext.chief_concern.citation.anchored:
                anchored.append(ext.chief_concern.value)
        return {"status": outcome.extraction.status, "anchored": anchored}

    @app.post("/eval/retrieve")
    def eval_retrieve(req: RetrieveEvalRequest) -> dict:
        """Hybrid retrieval with a supplied query embedding (offline) or a live one."""
        # Imports inside a function run when it is first called; these names
        # are needed only here.
        import numpy as np

        from . import retrieve as retrieve_module

        # A supplied embedding becomes the query vector and no embeddings call
        # is made; with none supplied, embed_query=True asks the API for one.
        vec = np.array(req.embedding, dtype=np.float32) if req.embedding else None
        chunks, usage = retrieve_module.retrieve(req.query, query_vec=vec, embed_query=vec is None)
        return {"chunks": [c.model_dump() for c in chunks], "usage": [u.model_dump() for u in usage], "reranked": any(u.kind == "rerank" for u in usage)}

    @app.post("/eval/route")
    def eval_route(req: RouteEvalRequest) -> dict:
        """The real graph with stubbed workers: returns the handoff log so the
        harness can score routing without a model or a parser."""
        g = graph.build_graph(graph.stub_extract, graph.stub_retrieve)
        state = graph.run(req.mode, "eval-route-000", "0" * 64, req.question, req.documents, graph=g)  # type: ignore[arg-type]
        return {"handoffs": [h.model_dump(by_alias=True) for h in state["handoffs"]], "extractions": len(state["extractions"]), "chunks": len(state["chunks"])}

    @app.post("/eval/phi")
    def eval_phi(req: AnchorEvalRequest) -> dict:
        """Runs an extraction (recorded proposal when given, else the real model)
        while capturing every log line the sidecar emits, and returns the
        lines. The harness scans them for the fixture's identifiers and for
        fields outside the allowlist (no_phi_in_logs)."""
        from .logging_setup import ALLOWED, AllowlistJsonFormatter

        path = (FIXTURES_ROOT / req.fixture).resolve()
        if FIXTURES_ROOT not in path.parents or not path.is_file():
            raise HTTPException(404, "fixture not found")
        lines: list[str] = []
        raw_keys: set[str] = set()

        # A logging handler is an object the logging system calls with every
        # record; this one, defined inside the function, appends the formatted
        # line to `lines` and notes every non-standard attribute name on the
        # record. The throwaway LogRecord("x", ...) is built only to learn
        # which attribute names every record has by default, so the rest can
        # be recognised as fields a log call attached with extra={...}.
        class Capture(logging.Handler):
            def emit(self, record: logging.LogRecord) -> None:
                raw_keys.update(k for k in record.__dict__ if k not in logging.LogRecord("x", 0, "", 0, "", None, None).__dict__ and k not in ("message", "asctime"))
                lines.append(AllowlistJsonFormatter().format(record))

        handler = Capture()
        root = logging.getLogger()
        root.addHandler(handler)
        # A correlation id derived from the fixture name (not from the fixture
        # contents), so the lines can be matched to this call.
        cid = "eval-phi-" + hashlib.sha256(req.fixture.encode()).hexdigest()[:8]
        bind_correlation_id(cid)
        # try/finally: the handler is removed even if the extraction raises,
        # so a failed eval does not leave the capture attached to the process.
        try:
            model = LabReportProposal if req.doc_type == "lab_pdf" else IntakeFormProposal
            # An empty proposal dict means "call the real model".
            proposal = model.model_validate(req.proposal) if req.proposal else None
            outcome = extractor.extract(req.document_id, req.doc_type, path.read_bytes(), cid, proposal=proposal)
            if req.question:
                from . import retrieve as retrieve_module

                # Keyword leg only (embed_query=False): the point is to see what
                # retrieval logs, not to spend an embeddings call.
                retrieve_module.retrieve(req.question, embed_query=False)
        finally:
            root.removeHandler(handler)
        return {"correlation_id": cid, "status": outcome.extraction.status, "lines": lines, "extra_keys_seen": sorted(raw_keys), "allowlist": sorted(ALLOWED)}

    @app.post("/eval/extract")
    def eval_extract(req: AnchorEvalRequest) -> dict:
        """Live variant: the real parser and the real model on a fixture; returns the extraction and the raw proposal so it can be recorded as model.json."""
        path = (FIXTURES_ROOT / req.fixture).resolve()
        if FIXTURES_ROOT not in path.parents or not path.is_file():
            raise HTTPException(404, "fixture not found")
        outcome = extractor.extract(req.document_id, req.doc_type, path.read_bytes(), "eval-extract")
        return {"extraction": json.loads(outcome.extraction.model_dump_json()), "proposal": json.loads(outcome.proposal_raw) if outcome.proposal_raw else None, "usage": [u.model_dump() for u in outcome.usage]}
