"""Pins the keyless eval requests (copilot_sidecar/keys.py).

The deterministic eval cases must never call a model provider, even on a dev
stack whose sidecar holds real keys: before this, cases 29-31 and 57 reranked
through Cohere on every push from a machine with COHERE_API_KEY in its .env.
The harness sends X-Eval-Keyless: 1 on those requests; these tests prove the
header turns the keys off for that request only, and only while the test-only
eval endpoints are enabled.

A fake cohere module stands in for the real client and records every rerank
call, so nothing here touches the network.
"""

from __future__ import annotations

import json
import os
import sys
import types
from pathlib import Path

import pytest
from fastapi.testclient import TestClient

from copilot_sidecar import keys

QUERIES = Path(os.environ.get("COPILOT_QUERIES_DIR", "/queries"))


@pytest.fixture()
def cohere_calls(monkeypatch) -> list[str]:
    """A Cohere key in the environment and a fake cohere module that records each rerank."""
    calls: list[str] = []

    class FakeClient:
        def __init__(self, **kwargs):
            pass

        def rerank(self, *, query, documents, top_n, **kwargs):
            calls.append(query)
            results = [types.SimpleNamespace(index=i, relevance_score=1.0 - i / 100) for i in range(min(top_n, len(documents)))]
            return types.SimpleNamespace(results=results)

    monkeypatch.setenv("COHERE_API_KEY", "test-key")
    # No OpenAI key: the query below carries its committed vector, and nothing may embed.
    monkeypatch.delenv("OPENAI_API_KEY", raising=False)
    monkeypatch.setitem(sys.modules, "cohere", types.SimpleNamespace(ClientV2=FakeClient))
    return calls


@pytest.fixture()
def client(monkeypatch) -> TestClient:
    monkeypatch.setenv("COPILOT_EVAL_ENDPOINTS", "1")
    from copilot_sidecar import app as app_module

    return TestClient(app_module.app)


def _retrieve_body() -> dict:
    q = json.loads((QUERIES / "statin-ldl-190.json").read_text())
    return {"query": q["query"], "embedding": q["embedding"]}


def test_model_key_is_empty_only_during_a_keyless_request(monkeypatch) -> None:
    monkeypatch.setenv("COHERE_API_KEY", "test-key")
    keys.bind_keyless(True)
    try:
        assert keys.model_key("COHERE_API_KEY") == ""
    finally:
        keys.bind_keyless(False)
    assert keys.model_key("COHERE_API_KEY") == "test-key"


def test_a_keyless_eval_request_never_reranks(client: TestClient, cohere_calls: list[str]) -> None:
    resp = client.post("/eval/retrieve", json=_retrieve_body(), headers={"X-Eval-Keyless": "1"})
    assert resp.status_code == 200
    assert resp.json()["reranked"] is False
    assert resp.json()["usage"] == []
    assert cohere_calls == []


def test_the_same_request_without_the_header_reranks(client: TestClient, cohere_calls: list[str]) -> None:
    resp = client.post("/eval/retrieve", json=_retrieve_body())
    assert resp.status_code == 200
    assert resp.json()["reranked"] is True
    assert len(cohere_calls) == 1


def test_the_header_is_ignored_when_eval_endpoints_are_off(client: TestClient, cohere_calls: list[str], monkeypatch) -> None:
    # The route was registered at import; the middleware reads the flag per request.
    monkeypatch.setenv("COPILOT_EVAL_ENDPOINTS", "0")
    resp = client.post("/eval/retrieve", json=_retrieve_body(), headers={"X-Eval-Keyless": "1"})
    assert resp.status_code == 200
    assert resp.json()["reranked"] is True
    assert len(cohere_calls) == 1
