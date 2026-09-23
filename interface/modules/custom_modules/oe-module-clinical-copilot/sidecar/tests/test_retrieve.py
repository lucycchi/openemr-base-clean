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
