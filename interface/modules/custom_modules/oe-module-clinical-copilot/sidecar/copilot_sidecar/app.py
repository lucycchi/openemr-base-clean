"""FastAPI surface of the sidecar.

POST /run       the graph (contracts run.request / run.response / run.error)
GET  /health    liveness, model and parser versions
POST /eval/anchor   test-only (COPILOT_EVAL_ENDPOINTS=1): a fixture path
                    and a recorded proposal through anchor.py, no model call

The sidecar holds no PHI at rest: documents arrive as bytes in the request
and leave as extractions in the response; the idempotency cache keeps
responses (no bytes) in memory for ten minutes keyed by correlation id
and document hash. Logs carry only allowlisted fields (see logging_setup).
"""

from __future__ import annotations

import hashlib
import json
import os
import time
from pathlib import Path

from fastapi import FastAPI, HTTPException, Request
from fastapi.responses import JSONResponse
from pydantic import BaseModel, ValidationError

from . import extractor, graph
from .llm import PROMPT_VERSION
from .logging_setup import setup_logging
from .schemas import IntakeFormProposal, LabReportProposal, RunError, RunRequest, RunResponse

setup_logging()
app = FastAPI(title="clinical-copilot-sidecar", docs_url=None, redoc_url=None)

IDEMPOTENCY_TTL_S = 600
_cache: dict[str, tuple[float, dict]] = {}


def _cache_key(req: RunRequest) -> str:
    h = hashlib.sha256()
    h.update(req.correlation_id.encode())
    h.update(req.mode.encode())
    h.update((req.question or "").encode())
    for d in req.documents:
        h.update(f"{d.document_id}:{d.status}:{d.sha3_512}".encode())
    return h.hexdigest()


def _error(correlation_id: str, code: str, status: int) -> JSONResponse:
    return JSONResponse(status_code=status, content=RunError(correlation_id=correlation_id, code=code).model_dump())


@app.get("/health")
def health() -> dict:
    return {"status": "ok", "prompt_version": PROMPT_VERSION, "model": os.environ.get("OPENAI_MODEL", "gpt-4o-mini"), "parser": "pymupdf+tesseract"}


@app.post("/run")
async def run(request: Request) -> JSONResponse:
    try:
        body = await request.json()
    except Exception:
        return _error("unknown", "bad_request", 400)
    try:
        req = RunRequest.model_validate(body)
    except ValidationError:
        return _error(str(body.get("correlation_id", "unknown")) if isinstance(body, dict) else "unknown", "bad_request", 422)

    key = _cache_key(req)
    now = time.monotonic()
    hit = _cache.get(key)
    if hit and now - hit[0] < IDEMPOTENCY_TTL_S:
        return JSONResponse(content=hit[1])

    try:
        state = graph.run(req.mode, req.correlation_id, req.facts_hash, req.question, req.documents)
    except Exception:  # never leak a traceback; the code is the message
        return _error(req.correlation_id, "internal", 500)
    resp = RunResponse(correlation_id=req.correlation_id, extractions=state["extractions"], chunks=state["chunks"], handoffs=state["handoffs"], usage=state["usage"])
    payload = json.loads(resp.model_dump_json(by_alias=True))
    _cache[key] = (now, payload)
    for k in [k for k, (t, _) in _cache.items() if now - t >= IDEMPOTENCY_TTL_S]:
        _cache.pop(k, None)
    return JSONResponse(content=payload)


# ---- Test-only endpoints ---------------------------------------------------

FIXTURES_ROOT = Path(os.environ.get("COPILOT_FIXTURES_DIR", "/fixtures")).resolve()


class AnchorEvalRequest(BaseModel):
    fixture: str
    doc_type: str
    proposal: dict
    document_id: int = 1


class RetrieveEvalRequest(BaseModel):
    query: str
    embedding: list[float] | None = None  # committed query embedding; absent means call the embeddings API


class RouteEvalDocument(BaseModel):
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
        path = (FIXTURES_ROOT / req.fixture).resolve()
        if FIXTURES_ROOT not in path.parents and path != FIXTURES_ROOT:
            raise HTTPException(400, "fixture outside the fixtures directory")
        if not path.is_file():
            raise HTTPException(404, "fixture not found")
        model = LabReportProposal if req.doc_type == "lab_pdf" else IntakeFormProposal
        proposal = model.model_validate(req.proposal)
        outcome = extractor.extract(req.document_id, req.doc_type, path.read_bytes(), "eval-anchor", proposal=proposal)
        return json.loads(outcome.extraction.model_dump_json())

    @app.post("/eval/retrieve")
    def eval_retrieve(req: RetrieveEvalRequest) -> dict:
        """Hybrid retrieval with a supplied query embedding (offline) or a live one."""
        import numpy as np

        from . import retrieve as retrieve_module

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

    @app.post("/eval/extract")
    def eval_extract(req: AnchorEvalRequest) -> dict:
        """Live variant: the real parser and the real model on a fixture; returns the extraction and the raw proposal so it can be recorded as model.json."""
        path = (FIXTURES_ROOT / req.fixture).resolve()
        if FIXTURES_ROOT not in path.parents or not path.is_file():
            raise HTTPException(404, "fixture not found")
        outcome = extractor.extract(req.document_id, req.doc_type, path.read_bytes(), "eval-extract")
        return {"extraction": json.loads(outcome.extraction.model_dump_json()), "proposal": json.loads(outcome.proposal_raw) if outcome.proposal_raw else None, "usage": [u.model_dump() for u in outcome.usage]}
