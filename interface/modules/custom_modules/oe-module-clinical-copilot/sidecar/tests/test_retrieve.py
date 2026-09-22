"""Hybrid retrieval over the committed index: offline (query embeddings come
from the eval fixtures), no API. Checks that the right guideline document
tops each on-corpus question, that an off-corpus question returns nothing,
that chunk ids are stable, and that rerank is skipped without a key."""

from __future__ import annotations

import json
import os
from pathlib import Path

import numpy as np
import pytest

from copilot_sidecar import retrieve

QUERIES = Path(os.environ.get("COPILOT_QUERIES_DIR", "/queries"))


@pytest.fixture(scope="module")
def index() -> retrieve.Index:
    return retrieve.index()


def load(qid: str) -> tuple[str, np.ndarray]:
    q = json.loads((QUERIES / f"{qid}.json").read_text())
    return q["query"], np.array(q["embedding"], dtype=np.float32)


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


@pytest.mark.skipif(not QUERIES.exists(), reason="eval query embeddings not mounted")
def test_off_corpus_question_returns_nothing(index) -> None:
    question, vec = load("off-corpus-car")
    chunks, _ = retrieve.retrieve(question, query_vec=vec, embed_query=False)
    assert chunks == []


def test_chunk_ids_are_stable_and_index_matches_corpus(index) -> None:
    fresh = retrieve.chunk_corpus()
    assert [c.chunk_id for c in fresh] == [c.chunk_id for c in index.chunks], "rebuild the index: corpus changed"
    assert len(fresh) >= 20


def test_tokenizer_drops_stop_words() -> None:
    assert retrieve.tokenize("How do I start a statin?") == ["start", "statin"]
