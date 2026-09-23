"""HTTP surface: contract rejection, idempotency, and the eval endpoints'
fixture-path guard. The model is never called (documents are junk bytes, so
the extractor fails before proposing).

These tests speak to the sidecar the way PHP does, over HTTP, but without
a server: FastAPI's TestClient calls the application in-process and returns
the response object. What they pin is the contract PHP relies on: which
status codes and bodies come back, that a bad document fails alone, that a
retried request is answered from cache, and that every log line of a run
carries the correlation id operators search by.

Reading the test code: "monkeypatch" is pytest's tool for temporarily
changing an environment variable or a module attribute; the change is
undone when the test ends. A fixture with `yield` runs its setup before
the test and the lines after `yield` as cleanup."""

from __future__ import annotations

import json
import logging
import os
from pathlib import Path

import pytest
from fastapi.testclient import TestClient


# One test client for the whole file. The two environment variables are set
# BEFORE the app module is imported, because app.py reads them at import
# time: the /eval/* endpoints only exist when COPILOT_EVAL_ENDPOINTS is "1",
# and FIXTURES_ROOT is fixed then. The explicit FIXTURES_ROOT assignment
# covers the case where another test file imported app.py first.
@pytest.fixture(scope="module")
def client(tmp_path_factory) -> TestClient:
    fixtures = tmp_path_factory.mktemp("fixtures")
    # A file that starts like a PDF but is not one: the parser will reject it.
    (fixtures / "junk.pdf").write_bytes(b"%PDF-1.4 not really")
    os.environ["COPILOT_EVAL_ENDPOINTS"] = "1"
    os.environ["COPILOT_FIXTURES_DIR"] = str(fixtures)
    from copilot_sidecar import app as app_module

    app_module.FIXTURES_ROOT = Path(fixtures).resolve()
    return TestClient(app_module.app)


# A valid run.request body. Any field passed as a keyword overrides the
# default, so a test can break exactly one thing. bytes_base64 is the same
# "%PDF-1.4 not really" junk, base64-encoded, so the document is unreadable
# and no model call can ever happen.
def request(**over) -> dict:
    base = {"mode": "extract", "correlation_id": "abcdefgh-0001", "facts_hash": "0" * 64, "question": None,
            "documents": [{"document_id": 1, "doc_type": "lab_pdf", "status": "stored", "sha3_512": "a" * 128, "bytes_base64": "JVBERi0xLjQgbm90IHJlYWxseQ=="}]}
    base.update(over)
    return base


# Pins: /health answers 200 and names the parser stack. Liveness only: it
# says the process answers; /ready is where dependencies are checked.
def test_health(client: TestClient) -> None:
    r = client.get("/health")
    assert r.status_code == 200 and r.json()["parser"] == "pymupdf+tesseract"


class _Lines(logging.Handler):
    """Formats each record as the sidecar writes it, at emit time, while the
    request's correlation id is still bound (caplog would format afterwards).

    A logging "handler" receives every log record the application emits.
    This one runs the sidecar's own JSON formatter on the spot and keeps the
    parsed result, so a test sees exactly the line an operator would see.
    Only the sidecar's own loggers ("copilot.*") are kept; library noise is
    not."""

    def __init__(self) -> None:
        super().__init__()
        from copilot_sidecar.logging_setup import AllowlistJsonFormatter

        self.setFormatter(AllowlistJsonFormatter())
        self.lines: list[dict] = []

    def emit(self, record: logging.LogRecord) -> None:
        if record.name.startswith("copilot."):
            self.lines.append(json.loads(self.format(record)))


# Attaches the capturing handler for one test and removes it afterwards,
# handing the test the list that fills up as the request runs.
@pytest.fixture
def lines() -> list[dict]:
    handler = _Lines()
    logging.getLogger().addHandler(handler)
    yield handler.lines
    logging.getLogger().removeHandler(handler)


# Pins the correlation-id requirement end to end: a run's route hops, its
# per-document outcome and its summary line all carry the body's id. A
# failure means an operator could not trace one clinician's request through
# the sidecar's log.
def test_every_sidecar_log_line_of_a_run_carries_its_correlation_id(client: TestClient, lines: list[dict]) -> None:
    """Requirement: the id appears in every log entry related to the request,
    so a run is reconstructible from the sidecar's log alone (route hops,
    per-document outcome, the run line)."""
    r = client.post("/run", json=request(correlation_id="abcdefgh-logs"))
    assert r.status_code == 200
    events = [line["event"] for line in lines]
    assert "handoff" in events and "run" in events and "extract failed" in events
    assert all(line["correlation_id"] == "abcdefgh-logs" for line in lines), lines


# Pins: even when the body is too broken to contain an id, the rejection is
# logged under the id PHP put in the X-Correlation-Id header, so a rejected
# request is still traceable.
def test_rejected_request_logs_under_the_header_id(client: TestClient, lines: list[dict]) -> None:
    """A body the contract rejects still logs under the caller's id, taken from X-Correlation-Id."""
    r = client.post("/run", json={"mode": "extract"}, headers={"X-Correlation-Id": "abcdefgh-hdr"})
    assert r.status_code == 422
    assert lines and all(line["correlation_id"] == "abcdefgh-hdr" for line in lines)


# Pins the sidecar.health.response contract: exactly these four keys and no
# dependency information. Liveness must stay cheap and never fail because
# a dependency is down; that is /ready's job.
def test_health_is_liveness_only(client: TestClient) -> None:
    r = client.get("/health")
    assert r.status_code == 200
    assert set(r.json()) == {"status", "prompt_version", "model", "parser"}


# Pins the happy path of /ready: with a key set, every required dependency
# reports "ok" and the optional reranker reports one of its two states.
# The key is set with monkeypatch so the test leaves the environment as it
# found it. A false "not ready" would make PHP's readiness check (ready.php
# probes this endpoint) report the sidecar down when nothing is wrong.
def test_ready_checks_every_local_dependency(client: TestClient, monkeypatch) -> None:
    monkeypatch.setenv("OPENAI_API_KEY", "sk-test")
    r = client.get("/ready")
    body = r.json()
    assert r.status_code == 200, body
    assert body["status"] == "ready"
    assert body["dependencies"] == {"contracts": "ok", "loinc_map": "ok", "corpus_index": "ok", "tesseract": "ok", "openai_key": "ok"}
    assert body["optional"]["cohere_rerank"] in ("configured", "not configured")


# Pins the failure path of /ready: 503, status not_ready, the failing
# dependency named with a fixed phrase, and no traceback or file path in the
# body (an exception's message never leaves the process). A false "ready"
# would let PHP send runs to a sidecar that cannot complete them.
def test_ready_is_503_when_a_required_dependency_is_missing(client: TestClient, monkeypatch, tmp_path: Path) -> None:
    """An empty contracts directory: the proposal contract cannot load, so the
    sidecar must not report ready (a run would fail at the model call)."""
    from copilot_sidecar import contracts as contracts_module

    monkeypatch.setenv("OPENAI_API_KEY", "sk-test")
    # Point the contract loader at an empty directory. contracts.load
    # remembers every file it has loaded (lru_cache), so the memory is cleared
    # before the request and again afterwards so later tests see real files.
    monkeypatch.setattr(contracts_module, "CONTRACTS_DIR", tmp_path)
    contracts_module.load.cache_clear()
    try:
        r = client.get("/ready")
        assert r.status_code == 503
        assert r.json()["status"] == "not_ready"
        assert r.json()["dependencies"]["contracts"] == "contracts unavailable"
        assert "Traceback" not in r.text and str(tmp_path) not in r.text
    finally:
        contracts_module.load.cache_clear()


# Pins: no OpenAI key means not ready, with the exact phrase ops looks for.
# Without a key no extraction can call the model at all.
def test_ready_reports_a_missing_openai_key(client: TestClient, monkeypatch) -> None:
    monkeypatch.delenv("OPENAI_API_KEY", raising=False)
    r = client.get("/ready")
    assert r.status_code == 503
    assert r.json()["dependencies"]["openai_key"] == "openai key not configured"


# Pins the run.error contract for bad input: a body that fails validation
# (unknown mode, malformed hash) is 422 with {correlation_id, code}, and a
# body that is not JSON at all is 400. PHP maps these codes to its own
# error responses, so the shape must not drift.
def test_bad_request_is_a_run_error(client: TestClient) -> None:
    r = client.post("/run", json=request(mode="route"))
    assert r.status_code == 422
    assert r.json() == {"correlation_id": "abcdefgh-0001", "code": "bad_request"}
    r = client.post("/run", json=request(facts_hash="short"))
    assert r.status_code == 422
    r = client.post("/run", content=b"{not json")
    assert r.status_code == 400


# Pins the "one bad document never fails the run" rule: the run is 200, the
# document is marked failed with reason "unreadable", the handoff log shows
# the extractor was tried once and reported failure, and no model tokens
# were spent. A failure would turn one bad scan into a lost request.
def test_unreadable_document_fails_that_document_not_the_run(client: TestClient) -> None:
    r = client.post("/run", json=request())
    assert r.status_code == 200
    body = r.json()
    assert body["extractions"][0]["status"] == "failed"
    assert body["extractions"][0]["failure_reason"] == "unreadable"
    assert [h["reason"] for h in body["handoffs"]] == ["stored_document", "worker_failed", "no_question"]
    assert body["usage"] == []


# Pins idempotency: PHP may retry a request after a timeout, and the retry
# must return the identical response without repeating the work (or the
# model spend). The cache key includes the document hashes, so the same id
# with a changed document is treated as a new run.
def test_same_correlation_id_and_documents_is_served_from_cache(client: TestClient) -> None:
    req = request(correlation_id="abcdefgh-idem")
    a = client.post("/run", json=req).json()
    b = client.post("/run", json=req).json()
    assert a == b
    # A different document hash under the same id is a different run.
    req["documents"][0]["sha3_512"] = "b" * 128
    c = client.post("/run", json=req).json()
    # A fresh run (not cached): the same route, timings may differ.
    assert [(h["from"], h["to"], h["reason"]) for h in c["handoffs"]] == [(h["from"], h["to"], h["reason"]) for h in a["handoffs"]]


# Pins the eval endpoint's path guard: a fixture name that climbs out of the
# fixtures directory is refused, a missing file is 404, and a present but
# unreadable file goes through the normal failed-extraction path. The
# endpoint is test-only, but it reads files by a caller-supplied name, so
# it must never be usable to read arbitrary files off the container.
def test_eval_anchor_refuses_paths_outside_the_fixtures_dir(client: TestClient) -> None:
    proposal = {"patient_name_on_report": None, "collection_date": "2026-09-15", "reported_date": None, "lab_name": None, "results": []}
    r = client.post("/eval/anchor", json={"fixture": "../../etc/passwd", "doc_type": "lab_pdf", "proposal": proposal})
    assert r.status_code in (400, 404)
    r = client.post("/eval/anchor", json={"fixture": "missing.pdf", "doc_type": "lab_pdf", "proposal": proposal})
    assert r.status_code == 404
    r = client.post("/eval/anchor", json={"fixture": "junk.pdf", "doc_type": "lab_pdf", "proposal": proposal})
    assert r.status_code == 200 and r.json()["status"] == "failed"
