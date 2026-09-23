"""Hybrid retrieval over the committed index: offline (query embeddings come
from the eval fixtures), no API. Checks that the right guideline document
tops each on-corpus question, that an off-corpus question returns nothing,
that chunk ids are stable, and that rerank is skipped without a key.

Why offline: embedding a question normally costs an API call. The eval
queries were embedded once by tools/build_index.py and committed beside
queries.json, so these tests pass the saved vector in and never touch the
network. That is also why they are skipped when the queries directory is
not mounted: without the vectors there is nothing honest to test.

Clinically, the first two tests are the guard against a wrong citation: a
question about statins must surface the cholesterol guideline, not the
diabetes one, and a question the corpus cannot answer must return nothing
rather than the least-bad passage dressed up as evidence."""

from __future__ import annotations

import json
import os
from pathlib import Path

import numpy as np
import pytest

from copilot_sidecar import retrieve

# Where the committed query embeddings are mounted in the test container.
QUERIES = Path(os.environ.get("COPILOT_QUERIES_DIR", "/queries"))


# Loads the committed index once for this file (it is read-only, so sharing
# it is safe). Loading also checks the vector count matches the chunk count.
@pytest.fixture(scope="module")
def index() -> retrieve.Index:
    return retrieve.index()


# One saved query: its wording and its embedding vector, as build_index.py
# wrote them. The vector is what stands in for the API call.
def load(qid: str) -> tuple[str, np.ndarray]:
    q = json.loads((QUERIES / f"{qid}.json").read_text())
    return q["query"], np.array(q["embedding"], dtype=np.float32)


# Pins, for five on-corpus questions: the top chunk comes from the expected
# guideline, at most TOP chunks are returned, no usage is recorded (no
# embedding call, no rerank), and every chunk carries a quote, a section and
# a 12-character id. The COHERE key is removed first so the test exercises
# the fusion order alone, not the reranker. A failure means an answer
# could cite the wrong guideline.
@pytest.mark.skipif(not QUERIES.exists(), reason="eval query embeddings not mounted")
@pytest.mark.parametrize("qid,source", [
    ("statin-ldl-190", "acc-aha-2018-cholesterol"),
    ("a1c-target", "ada-2025-standards"),
    ("bp-when-to-treat", "acc-aha-2017-hypertension"),
    ("low-hemoglobin-workup", "anemia-adults-primary-care"),
    ("egfr-referral", "kdigo-2024-ckd"),
])
def test_on_corpus_question_tops_the_right_guideline(index, qid: str, source: str) -> None:
    os.environ.pop("COHERE_API_KEY", None)
    question, vec = load(qid)
    chunks, usage = retrieve.retrieve(question, query_vec=vec, embed_query=False)
    assert chunks and chunks[0].source_id == source, [c.source_id for c in chunks]
    assert len(chunks) <= retrieve.TOP
    assert usage == []  # no embedding call (vector supplied), no rerank (no key)
    assert all(c.quote and c.section and len(c.chunk_id) == 12 for c in chunks)


# Pins the relevance floor: a question about cars has no answer in a
# clinical guideline corpus, and retrieval must say so with an empty list.
# A failure means the answer model would be handed an irrelevant passage
# to cite, which is how a confident wrong answer starts.
@pytest.mark.skipif(not QUERIES.exists(), reason="eval query embeddings not mounted")
def test_off_corpus_question_returns_nothing(index) -> None:
    question, vec = load("off-corpus-car")
    chunks, _ = retrieve.retrieve(question, query_vec=vec, embed_query=False)
    assert chunks == []


# Pins that the committed index still describes the corpus on disk: chunking
# the Markdown afresh must give the same ids in the same order. This is the
# test that fails when someone edits a guideline without running
# tools/build_index.py; the fix is to rebuild and commit corpus/index/.
def test_chunk_ids_are_stable_and_index_matches_corpus(index) -> None:
    fresh = retrieve.chunk_corpus()
    assert [c.chunk_id for c in fresh] == [c.chunk_id for c in index.chunks], "rebuild the index: corpus changed"
    assert len(fresh) >= 20


# Pins the keyword leg's tokenizer: function words are dropped so a question
# made mostly of them cannot score against every chunk at once.
def test_tokenizer_drops_stop_words() -> None:
    assert retrieve.tokenize("How do I start a statin?") == ["start", "statin"]


# -- trigger queries: committed vectors, one batch per briefing ---------------

TRIGGERS = retrieve.INDEX_DIR / "trigger_queries.json"
RULES = Path(os.environ.get("COPILOT_CONTRACTS_DIR", "/contracts")) / "guideline_triggers.json"


def _no_embed(texts):
    raise AssertionError("the embeddings API must not be called for a committed trigger query")


@pytest.mark.skipif(not TRIGGERS.exists() or not RULES.exists(), reason="trigger query vectors or rules not present")
def test_retrieve_many_uses_committed_vectors_and_tops_each_rules_source(index, monkeypatch) -> None:
    from copilot_sidecar.schemas import TriggerQuery

    monkeypatch.delenv("COHERE_API_KEY", raising=False)
    monkeypatch.setattr(retrieve, "embed", _no_embed)
    rules = {r["id"]: r for r in json.loads(RULES.read_text())["rules"]}
    queries = [TriggerQuery(trigger_id=rid, query=r["query"]) for rid, r in rules.items()]
    evidence, usage = retrieve.retrieve_many(queries)
    assert usage == []
    assert [e.trigger_id for e in evidence] == list(rules)
    for e in evidence:
        assert e.chunks, f"{e.trigger_id} retrieved nothing"
        assert len(e.chunks) <= retrieve.PER_TRIGGER
        assert e.chunks[0].source_id == rules[e.trigger_id]["source"], (e.trigger_id, [c.source_id for c in e.chunks])
    ids = [c.chunk_id for e in evidence for c in e.chunks]
    assert len(ids) == len(set(ids)), "a chunk must appear under one trigger only"


def test_retrieve_many_off_corpus_query_returns_no_chunks(index, monkeypatch) -> None:
    from copilot_sidecar.schemas import TriggerQuery

    monkeypatch.delenv("OPENAI_API_KEY", raising=False)
    monkeypatch.delenv("COHERE_API_KEY", raising=False)
    evidence, usage = retrieve.retrieve_many([TriggerQuery(trigger_id="cars", query="best sports car tyres for the track")])
    assert [e.trigger_id for e in evidence] == ["cars"]
    assert evidence[0].chunks == []
    assert usage == []


@pytest.mark.skipif(not TRIGGERS.exists(), reason="trigger query vectors not present")
def test_committed_trigger_vectors_match_the_rules_file() -> None:
    committed = json.loads(TRIGGERS.read_text())
    rules = {r["id"]: r["query"] for r in json.loads(RULES.read_text())["rules"]} if RULES.exists() else {}
    assert committed["model"] == retrieve.EMBED_MODEL
    for rid, query in rules.items():
        assert rid in committed["queries"], f"rebuild the index: no vector for trigger {rid}"
        assert committed["queries"][rid]["query"] == query, f"rebuild the index: trigger {rid} query text changed"


# -- a failing reranker degrades to fused order, never to no evidence ----------

def test_rerank_failure_falls_back_to_rrf_order(index, monkeypatch) -> None:
    """A rate-limited or unreachable Cohere must not blank the evidence: the
    fused (RRF) order stands and no rerank usage is recorded."""
    import sys
    import types

    monkeypatch.setenv("COHERE_API_KEY", "test-key")

    class Broken:
        def __init__(self, **kwargs):
            pass

        def rerank(self, **kwargs):
            raise RuntimeError("429 Too Many Requests")

    monkeypatch.setitem(sys.modules, "cohere", types.SimpleNamespace(ClientV2=Broken))
    question, vec = load("statin-ldl-190") if QUERIES.exists() else ("statin for high LDL cholesterol", None)
    cands = index.candidates(question, vec)
    assert cands, "the fixture question must have candidates"
    ranked, usage = retrieve.rerank(question, cands)
    assert usage == []
    assert [c.chunk_id for c, _ in ranked] == [c.chunk_id for c, _, _, _ in cands][: retrieve.TOP]
