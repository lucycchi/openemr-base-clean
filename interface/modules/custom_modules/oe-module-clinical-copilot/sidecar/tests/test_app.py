"""HTTP surface: contract rejection, idempotency, and the eval endpoints'
fixture-path guard. The model is never called (documents are junk bytes, so
the extractor fails before proposing)."""

from __future__ import annotations

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
