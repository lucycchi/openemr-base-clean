"""The evidence_retriever worker: hybrid retrieval over the guideline corpus.

Corpus: corpus/*.md, one document per guideline summary, chunked by "##"
section (the heading path is the chunk's section). Index: BM25 over
tokens (keyword leg) and text-embedding-3-small vectors (dense leg),
built once by tools/build_index.py and committed under corpus/index/, so a
container start and the offline eval cases never call the embeddings API.

Query: BM25 scores and cosine scores are fused by reciprocal rank fusion
(k=60) into a candidate list; candidates that neither leg found relevant
(no BM25 hit and cosine below FLOOR) are dropped, so an off-corpus question
returns nothing rather than the least-bad chunk. The top RRF candidates are
reranked with Cohere Rerank when COHERE_API_KEY is set; otherwise the RRF
order stands and the response's usage records no rerank. Only the top 5
chunks go to the answer model, each with its citation fields.
"""

from __future__ import annotations

import hashlib
import json
import logging
import os
import re
import time
from dataclasses import dataclass
from pathlib import Path

import numpy as np
from rank_bm25 import BM25Okapi

from .llm import correlation_options
from .logging_setup import correlation_id
from .schemas import Chunk, Usage

log = logging.getLogger("copilot.retrieve")

CORPUS_DIR = Path(os.environ.get("COPILOT_CORPUS_DIR") or Path(__file__).resolve().parents[1] / "corpus")
INDEX_DIR = CORPUS_DIR / "index"
EMBED_MODEL = "text-embedding-3-small"
RERANK_MODEL = "rerank-v3.5"
RRF_K = 60
CANDIDATES = 20
TOP = 5
FLOOR = 0.25   # cosine at or above which the dense leg alone is enough
WEAK = 0.15    # cosine a keyword hit must reach to count (a lone shared word is not relevance)
STRONG_BM25 = 8.0  # or a keyword score this high (several content words) stands on its own


@dataclass(frozen=True)
class IndexedChunk:
    chunk_id: str
    source_id: str
    section: str
    text: str


STOPWORDS = frozenset("""a an and are as at be by for from has have how i in is it its of on or should that the this to was we what when which with will do does my your can not no if than then there these those into about after before over under""".split())


def tokenize(text: str) -> list[str]:
    """Lower-cased word tokens without stop words, so a question made only of
    function words ("how do I ...") cannot score against every chunk."""
    return [t for t in re.findall(r"[a-z0-9][a-z0-9.%/-]*", text.lower()) if t not in STOPWORDS and len(t) > 1]


def chunk_corpus(corpus_dir: Path = CORPUS_DIR) -> list[IndexedChunk]:
    manifest = json.loads((corpus_dir / "manifest.json").read_text())
    chunks: list[IndexedChunk] = []
    for doc in manifest["documents"]:
        text = (corpus_dir / doc["file"]).read_text()
        title = ""
        section = ""
        buf: list[str] = []

        def flush() -> None:
            body = "\n".join(buf).strip()
            if body and section:
                ordinal = sum(1 for c in chunks if c.source_id == doc["source_id"])
                cid = hashlib.sha256(f"{doc['source_id']}|{section}|{ordinal}".encode()).hexdigest()[:12]
                chunks.append(IndexedChunk(cid, doc["source_id"], f"{title} > {section}" if title else section, body))
            buf.clear()

        for line in text.splitlines():
            if line.startswith("# "):
                title = line[2:].strip()
            elif line.startswith("## "):
                flush()
                section = line[3:].strip()
            else:
                buf.append(line)
        flush()
    return chunks


def embed(texts: list[str]) -> tuple[np.ndarray, Usage]:
    from openai import OpenAI

    client = OpenAI(timeout=30.0, max_retries=1)
    started = time.monotonic()
    resp = client.embeddings.create(model=EMBED_MODEL, input=texts, **correlation_options())
    vecs = np.array([d.embedding for d in resp.data], dtype=np.float32)
    vecs /= np.linalg.norm(vecs, axis=1, keepdims=True) + 1e-9
    usage = Usage(model=EMBED_MODEL, kind="embedding", input=int(resp.usage.prompt_tokens), output=0)
    log.info("model_call", extra={"model": EMBED_MODEL, "kind": "embedding", "count": len(texts), "input": usage.input, "ms": int((time.monotonic() - started) * 1000)})
    return vecs, usage


class Index:
    def __init__(self, chunks: list[IndexedChunk], vectors: np.ndarray) -> None:
        self.chunks = chunks
        self.vectors = vectors
        self.bm25 = BM25Okapi([tokenize(c.section + " " + c.text) for c in chunks])

    @classmethod
    def load(cls, index_dir: Path = INDEX_DIR) -> "Index":
        meta = json.loads((index_dir / "chunks.json").read_text())
        chunks = [IndexedChunk(**c) for c in meta["chunks"]]
        vectors = np.load(index_dir / "embeddings.npy")
        if vectors.shape[0] != len(chunks):
            raise RuntimeError("index out of date: rebuild with tools/build_index.py")
        return cls(chunks, vectors)

    def candidates(self, query: str, query_vec: np.ndarray | None) -> list[tuple[IndexedChunk, float, float, float]]:
        """(chunk, rrf, bm25, cosine) for the fused top candidates that pass the relevance floor."""
        bm = np.array(self.bm25.get_scores(tokenize(query)), dtype=np.float32)
        cos = self.vectors @ query_vec if query_vec is not None else np.zeros(len(self.chunks), dtype=np.float32)
        rrf = np.zeros(len(self.chunks), dtype=np.float32)
        for scores in (bm, cos):
            order = np.argsort(-scores)
            for rank, idx in enumerate(order):
                if scores[idx] > 0:
                    rrf[idx] += 1.0 / (RRF_K + rank + 1)
        out = []
        for idx in np.argsort(-rrf)[:CANDIDATES]:
            if rrf[idx] <= 0:
                continue
            relevant = cos[idx] >= FLOOR or (bm[idx] > 0 and cos[idx] >= WEAK) or bm[idx] >= STRONG_BM25
            if not relevant:
                continue  # neither leg thinks this chunk is relevant
            out.append((self.chunks[idx], float(rrf[idx]), float(bm[idx]), float(cos[idx])))
        return out


_index: Index | None = None


def index() -> Index:
    global _index
    if _index is None:
        _index = Index.load()
    return _index


def rerank(query: str, cands: list[tuple[IndexedChunk, float, float, float]]) -> tuple[list[tuple[IndexedChunk, float]], list[Usage]]:
    """Cohere Rerank when a key is configured; otherwise RRF order, no usage."""
    if not cands:
        return [], []
    key = os.environ.get("COHERE_API_KEY", "")
    if not key:
        return [(c, rrf) for c, rrf, _, _ in cands][:TOP], []
    import cohere

    client = cohere.ClientV2(api_key=key, timeout=20)
    docs = [f"{c.section}\n{c.text}" for c, _, _, _ in cands]
    started = time.monotonic()
    cid = correlation_id()
    options = {"additional_headers": {"X-Correlation-Id": cid}} if cid else None
    resp = client.rerank(model=RERANK_MODEL, query=query, documents=docs, top_n=TOP, request_options=options)
    ranked = [(cands[r.index][0], float(r.relevance_score)) for r in resp.results]
    log.info("model_call", extra={"model": RERANK_MODEL, "kind": "rerank", "count": len(docs), "ms": int((time.monotonic() - started) * 1000)})
    return ranked, [Usage(model=RERANK_MODEL, kind="rerank", input=1, output=0)]


def retrieve(question: str, query_vec: np.ndarray | None = None, embed_query: bool = True) -> tuple[list[Chunk], list[Usage]]:
    """Top chunks for a question. query_vec lets the eval harness pass a
    committed embedding so offline cases never call the embeddings API."""
    usage: list[Usage] = []
    started = time.monotonic()
    if query_vec is None and embed_query and os.environ.get("OPENAI_API_KEY"):
        vecs, u = embed([question])
        query_vec, usage = vecs[0], [u]
    cands = index().candidates(question, query_vec)
    ranked, rerank_usage = rerank(question, cands)
    usage.extend(rerank_usage)
    chunks = [Chunk(chunk_id=c.chunk_id, source_id=c.source_id, section=c.section, quote=c.text, score=round(score, 4)) for c, score in ranked[:TOP]]
    # Counts and the ranking kind only: never the question or a chunk's text.
    log.info("retrieved", extra={"count": len(chunks), "kind": "rerank" if rerank_usage else "rrf", "ms": int((time.monotonic() - started) * 1000)})
    return chunks, usage
