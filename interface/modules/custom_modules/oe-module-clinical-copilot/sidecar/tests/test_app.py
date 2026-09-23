"""HTTP surface: contract rejection, idempotency, and the eval endpoints'
fixture-path guard. The model is never called (documents are junk bytes, so
the extractor fails before proposing)."""

from __future__ import annotations

import json
import logging
import os
from pathlib import Path

import pytest
from fastapi.testclient import TestClient


@pytest.fixture(scope="module")
def client(tmp_path_factory) -> TestClient:
    fixtures = tmp_path_factory.mktemp("fixtures")
    (fixtures / "junk.pdf").write_bytes(b"%PDF-1.4 not really")
    os.environ["COPILOT_EVAL_ENDPOINTS"] = "1"
    os.environ["COPILOT_FIXTURES_DIR"] = str(fixtures)
    from copilot_sidecar import app as app_module

    app_module.FIXTURES_ROOT = Path(fixtures).resolve()
    return TestClient(app_module.app)


def request(**over) -> dict:
    base = {"mode": "extract", "correlation_id": "abcdefgh-0001", "facts_hash": "0" * 64, "question": None,
            "documents": [{"document_id": 1, "doc_type": "lab_pdf", "status": "stored", "sha3_512": "a" * 128, "bytes_base64": "JVBERi0xLjQgbm90IHJlYWxseQ=="}]}
    base.update(over)
    return base


def test_health(client: TestClient) -> None:
    r = client.get("/health")
    assert r.status_code == 200 and r.json()["parser"] == "pymupdf+tesseract"


class _Lines(logging.Handler):
    """Formats each record as the sidecar writes it, at emit time, while the
    request's correlation id is still bound (caplog would format afterwards)."""

    def __init__(self) -> None:
        super().__init__()
        from copilot_sidecar.logging_setup import AllowlistJsonFormatter

        self.setFormatter(AllowlistJsonFormatter())
        self.lines: list[dict] = []

    def emit(self, record: logging.LogRecord) -> None:
        if record.name.startswith("copilot."):
            self.lines.append(json.loads(self.format(record)))


@pytest.fixture
def lines() -> list[dict]:
    handler = _Lines()
    logging.getLogger().addHandler(handler)
    yield handler.lines
    logging.getLogger().removeHandler(handler)


def test_every_sidecar_log_line_of_a_run_carries_its_correlation_id(client: TestClient, lines: list[dict]) -> None:
    """Requirement: the id appears in every log entry related to the request,
    so a run is reconstructible from the sidecar's log alone (route hops,
    per-document outcome, the run line)."""
    r = client.post("/run", json=request(correlation_id="abcdefgh-logs"))
    assert r.status_code == 200
    events = [line["event"] for line in lines]
    assert "handoff" in events and "run" in events and "extract failed" in events
    assert all(line["correlation_id"] == "abcdefgh-logs" for line in lines), lines


def test_rejected_request_logs_under_the_header_id(client: TestClient, lines: list[dict]) -> None:
    """A body the contract rejects still logs under the caller's id, taken from X-Correlation-Id."""
    r = client.post("/run", json={"mode": "extract"}, headers={"X-Correlation-Id": "abcdefgh-hdr"})
    assert r.status_code == 422
    assert lines and all(line["correlation_id"] == "abcdefgh-hdr" for line in lines)


def test_health_is_liveness_only(client: TestClient) -> None:
    r = client.get("/health")
    assert r.status_code == 200
    assert set(r.json()) == {"status", "prompt_version", "model", "parser"}


def test_ready_checks_every_local_dependency(client: TestClient, monkeypatch) -> None:
    monkeypatch.setenv("OPENAI_API_KEY", "sk-test")
    r = client.get("/ready")
    body = r.json()
    assert r.status_code == 200, body
    assert body["status"] == "ready"
    assert body["dependencies"] == {"contracts": "ok", "loinc_map": "ok", "corpus_index": "ok", "tesseract": "ok", "openai_key": "ok"}
    assert body["optional"]["cohere_rerank"] in ("configured", "not configured")


def test_ready_is_503_when_a_required_dependency_is_missing(client: TestClient, monkeypatch, tmp_path: Path) -> None:
    """An empty contracts directory: the proposal contract cannot load, so the
    sidecar must not report ready (a run would fail at the model call)."""
    from copilot_sidecar import contracts as contracts_module

    monkeypatch.setenv("OPENAI_API_KEY", "sk-test")
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


def test_ready_reports_a_missing_openai_key(client: TestClient, monkeypatch) -> None:
    monkeypatch.delenv("OPENAI_API_KEY", raising=False)
    r = client.get("/ready")
    assert r.status_code == 503
    assert r.json()["dependencies"]["openai_key"] == "openai key not configured"


def test_bad_request_is_a_run_error(client: TestClient) -> None:
    r = client.post("/run", json=request(mode="route"))
    assert r.status_code == 422
    assert r.json() == {"correlation_id": "abcdefgh-0001", "code": "bad_request"}
    r = client.post("/run", json=request(facts_hash="short"))
    assert r.status_code == 422
    r = client.post("/run", content=b"{not json")
    assert r.status_code == 400


def test_unreadable_document_fails_that_document_not_the_run(client: TestClient) -> None:
    r = client.post("/run", json=request())
    assert r.status_code == 200
    body = r.json()
    assert body["extractions"][0]["status"] == "failed"
    assert body["extractions"][0]["failure_reason"] == "unreadable"
    assert [h["reason"] for h in body["handoffs"]] == ["stored_document", "worker_failed", "no_question"]
    assert body["usage"] == []


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


def test_eval_anchor_refuses_paths_outside_the_fixtures_dir(client: TestClient) -> None:
    proposal = {"patient_name_on_report": None, "collection_date": "2026-09-15", "reported_date": None, "lab_name": None, "results": []}
    r = client.post("/eval/anchor", json={"fixture": "../../etc/passwd", "doc_type": "lab_pdf", "proposal": proposal})
    assert r.status_code in (400, 404)
    r = client.post("/eval/anchor", json={"fixture": "missing.pdf", "doc_type": "lab_pdf", "proposal": proposal})
    assert r.status_code == 404
    r = client.post("/eval/anchor", json={"fixture": "junk.pdf", "doc_type": "lab_pdf", "proposal": proposal})
    assert r.status_code == 200 and r.json()["status"] == "failed"
