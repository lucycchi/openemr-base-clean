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

The same pipeline in plain words, for readers new to search:

  question -> tokenize        keyword leg: BM25 scores every chunk by how
                              many of the question's content words it
                              contains, weighting rare words more
           -> embed           dense leg: the question becomes a list of
                              numbers (a vector) whose direction captures
                              its meaning; "cosine" measures how closely a
                              chunk's vector points the same way (1.0 = same
                              meaning, 0 = unrelated)
           -> reciprocal rank fusion   each leg ranks the chunks; a chunk
                              earns 1/(60 + rank) from each leg and the sums
                              are ordered. Ranks, not raw scores, are used
                              because the two legs' scores are on different
                              scales. With k=60 the curve is flat: a chunk
                              that both legs rank in the middle outscores a
                              chunk only one leg ranks first
           -> relevance floor drop candidates neither leg really matched
           -> rerank          optional: Cohere reads the question and each
                              of the top 20 candidates together and scores
                              the fit directly
           -> top 5 Chunk objects, each with its source and section

Why not one leg alone: keywords miss synonyms ("high blood pressure" vs
"hypertension"); embeddings miss exact identifiers (a drug name, "A1c").
Fusing both, then applying a floor, is how the retriever both finds the
right passage and stays quiet when the corpus has nothing on the question.
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
from .schemas import Chunk, TriggerEvidence, TriggerQuery, Usage

log = logging.getLogger("copilot.retrieve")

# Corpus location: COPILOT_CORPUS_DIR when set (the Docker mount), otherwise
# the corpus/ directory beside this package in the source tree.
CORPUS_DIR = Path(os.environ.get("COPILOT_CORPUS_DIR") or Path(__file__).resolve().parents[1] / "corpus")
INDEX_DIR = CORPUS_DIR / "index"
EMBED_MODEL = "text-embedding-3-small"
RERANK_MODEL = "rerank-v3.5"
RRF_K = 60  # the constant k in 1/(k + rank)
CANDIDATES = 20  # how many fused candidates are kept for the floor and the rerank
TOP = 5  # how many chunks the answer model receives
PER_TRIGGER = 2  # brief mode: passages kept per fired trigger
# The relevance floor. A candidate survives when any one of these holds:
FLOOR = 0.25   # cosine at or above which the dense leg alone is enough
WEAK = 0.15    # cosine a keyword hit must reach to count (a lone shared word is not relevance)
STRONG_BM25 = 8.0  # or a keyword score this high (several content words) stands on its own


# One indexed passage. frozen=True: immutable once built.
@dataclass(frozen=True)
class IndexedChunk:
    chunk_id: str  # 12 hex characters, stable across rebuilds (see chunk_corpus)
    source_id: str  # which guideline document
    section: str  # "Title > Heading"
    text: str


# Function words that carry no topic on their own; removed before keyword
# scoring so they cannot make every chunk look like a match.
STOPWORDS = frozenset("""a an and are as at be by for from has have how i in is it its of on or should that the this to was we what when which with will do does my your can not no if than then there these those into about after before over under""".split())


def tokenize(text: str) -> list[str]:
    """Lower-cased word tokens without stop words, so a question made only of
    function words ("how do I ...") cannot score against every chunk.

    The pattern keeps letters and digits plus the characters found inside
    lab units and doses (".", "%", "/", "-"), so "mg/dl" and "a1c" stay
    whole. Single-character tokens are dropped."""
    return [t for t in re.findall(r"[a-z0-9][a-z0-9.%/-]*", text.lower()) if t not in STOPWORDS and len(t) > 1]


def chunk_corpus(corpus_dir: Path = CORPUS_DIR) -> list[IndexedChunk]:
    """Splits every corpus document into chunks, one per "## " section.
    Used by tools/build_index.py; at run time the chunks are read back
    from index/chunks.json instead."""
    # manifest.json lists the documents: each has a source_id and a file name.
    manifest = json.loads((corpus_dir / "manifest.json").read_text())
    chunks: list[IndexedChunk] = []
    for doc in manifest["documents"]:
        text = (corpus_dir / doc["file"]).read_text()
        title = ""
        section = ""
        buf: list[str] = []

        # flush closes off the section collected so far as one chunk. The id
        # is the first 12 hex characters of a hash of source, heading and the
        # chunk's ordinal within the document, so it stays the same on every
        # rebuild as long as the document is unchanged. A section with no
        # body, or text before the first heading, produces no chunk.
        def flush() -> None:
            body = "\n".join(buf).strip()
            if body and section:
                ordinal = sum(1 for c in chunks if c.source_id == doc["source_id"])
                cid = hashlib.sha256(f"{doc['source_id']}|{section}|{ordinal}".encode()).hexdigest()[:12]
                chunks.append(IndexedChunk(cid, doc["source_id"], f"{title} > {section}" if title else section, body))
            buf.clear()

        # "# " is the document title, "## " starts a new section, anything
        # else is body text of the current section.
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
    """Asks OpenAI for one embedding vector per text and returns them as a
    matrix (one row per text) plus the usage record."""
    from openai import OpenAI

    client = OpenAI(timeout=30.0, max_retries=1)
    started = time.monotonic()
    resp = client.embeddings.create(model=EMBED_MODEL, input=texts, **correlation_options())
    vecs = np.array([d.embedding for d in resp.data], dtype=np.float32)
    # Scale every vector to length 1 (the tiny 1e-9 avoids dividing by zero),
    # so that later a plain dot product between two vectors equals their
    # cosine similarity.
    vecs /= np.linalg.norm(vecs, axis=1, keepdims=True) + 1e-9
    usage = Usage(model=EMBED_MODEL, kind="embedding", input=int(resp.usage.prompt_tokens), output=0)
    log.info("model_call", extra={"model": EMBED_MODEL, "kind": "embedding", "count": len(texts), "input": usage.input, "ms": int((time.monotonic() - started) * 1000)})
    return vecs, usage


class Index:
    """The two legs over one chunk list: a BM25 keyword index built in
    memory at load time, and the committed embedding matrix."""
    def __init__(self, chunks: list[IndexedChunk], vectors: np.ndarray) -> None:
        self.chunks = chunks
        self.vectors = vectors
        # The heading is indexed with the body so a question that names the
        # topic ("statin therapy") matches the section about it.
        self.bm25 = BM25Okapi([tokenize(c.section + " " + c.text) for c in chunks])

    @classmethod
    def load(cls, index_dir: Path = INDEX_DIR) -> "Index":
        """Reads chunks.json and embeddings.npy from the committed index. The
        row-count check catches a corpus edited without rebuilding the index,
        which would otherwise pair chunks with the wrong vectors."""
        meta = json.loads((index_dir / "chunks.json").read_text())
        chunks = [IndexedChunk(**c) for c in meta["chunks"]]
        vectors = np.load(index_dir / "embeddings.npy")
        if vectors.shape[0] != len(chunks):
            raise RuntimeError("index out of date: rebuild with tools/build_index.py")
        return cls(chunks, vectors)

    def candidates(self, query: str, query_vec: np.ndarray | None) -> list[tuple[IndexedChunk, float, float, float]]:
        """(chunk, rrf, bm25, cosine) for the fused top candidates that pass the relevance floor."""
        # Keyword leg: one BM25 score per chunk, 0 when no query word appears.
        bm = np.array(self.bm25.get_scores(tokenize(query)), dtype=np.float32)
        # Dense leg: the cosine of the query vector with every chunk vector in
        # one matrix product (`@`); all zeros when there is no query vector,
        # which leaves the keyword leg to carry the search alone.
        cos = self.vectors @ query_vec if query_vec is not None else np.zeros(len(self.chunks), dtype=np.float32)
        # Fusion: for each leg, sort best-first (argsort of the negated
        # scores) and award 1/(k + rank) to every chunk that leg scored above
        # zero. rank counts from 0, so the best chunk in a leg earns 1/(k+1).
        rrf = np.zeros(len(self.chunks), dtype=np.float32)
        for scores in (bm, cos):
            order = np.argsort(-scores)
            for rank, idx in enumerate(order):
                if scores[idx] > 0:
                    rrf[idx] += 1.0 / (RRF_K + rank + 1)
        # Take the best CANDIDATES by fused score, skip any that neither leg
        # scored at all, then apply the floor: close in meaning, or a keyword
        # hit backed by at least weak meaning, or a strong keyword match.
        out = []
        for idx in np.argsort(-rrf)[:CANDIDATES]:
            if rrf[idx] <= 0:
                continue
            relevant = cos[idx] >= FLOOR or (bm[idx] > 0 and cos[idx] >= WEAK) or bm[idx] >= STRONG_BM25
            if not relevant:
                continue  # neither leg thinks this chunk is relevant
            out.append((self.chunks[idx], float(rrf[idx]), float(bm[idx]), float(cos[idx])))
        return out


# The loaded index, built on first use and shared by every request.
_index: Index | None = None


def index() -> Index:
    """Loads the index once per process. Also used by /ready as the
    corpus_index check: loading is the check."""
    global _index
    if _index is None:
        _index = Index.load()
    return _index


def rerank(query: str, cands: list[tuple[IndexedChunk, float, float, float]]) -> tuple[list[tuple[IndexedChunk, float]], list[Usage]]:
    """Cohere Rerank when a key is configured; otherwise RRF order, no usage.

    A reranker is a model that reads the question and one passage together
    and scores how well the passage answers it, which is more accurate than
    either leg but too slow to run over the whole corpus; that is why it
    sees only the fused candidates. Returns (chunk, score) pairs, best
    first, at most TOP of them."""
    if not cands:
        return [], []
    key = os.environ.get("COHERE_API_KEY", "")
    if not key:
        return [(c, rrf) for c, rrf, _, _ in cands][:TOP], []
    # Imported here so the Cohere library is loaded only when a key exists.
    import cohere

    client = cohere.ClientV2(api_key=key, timeout=20)
    # Each candidate is sent as its heading plus body.
    docs = [f"{c.section}\n{c.text}" for c, _, _, _ in cands]
    started = time.monotonic()
    # The correlation id travels as a request header, as with the OpenAI calls.
    cid = correlation_id()
    options = {"additional_headers": {"X-Correlation-Id": cid}} if cid else None
    resp = client.rerank(model=RERANK_MODEL, query=query, documents=docs, top_n=TOP, request_options=options)
    # r.index points back into `cands`; relevance_score is Cohere's 0..1 fit.
    ranked = [(cands[r.index][0], float(r.relevance_score)) for r in resp.results]
    log.info("model_call", extra={"model": RERANK_MODEL, "kind": "rerank", "count": len(docs), "ms": int((time.monotonic() - started) * 1000)})
    # Rerank is billed per search, not per token: recorded as 1 input unit.
    return ranked, [Usage(model=RERANK_MODEL, kind="rerank", input=1, output=0)]


def retrieve(question: str, query_vec: np.ndarray | None = None, embed_query: bool = True) -> tuple[list[Chunk], list[Usage]]:
    """Top chunks for a question. query_vec lets the eval harness pass a
    committed embedding so offline cases never call the embeddings API.

    The dense leg runs only when a query vector is supplied or can be
    fetched (embed_query=True and an OpenAI key present); otherwise the
    search is keyword-only and, because the cosine is then 0 everywhere,
    only chunks with a strong keyword score pass the floor."""
    usage: list[Usage] = []
    started = time.monotonic()
    if query_vec is None and embed_query and os.environ.get("OPENAI_API_KEY"):
        vecs, u = embed([question])
        query_vec, usage = vecs[0], [u]
    cands = index().candidates(question, query_vec)
    ranked, rerank_usage = rerank(question, cands)
    usage.extend(rerank_usage)
    # The contract Chunk carries the citation fields the answer model needs;
    # the score is rounded to four decimals.
    chunks = [Chunk(chunk_id=c.chunk_id, source_id=c.source_id, section=c.section, quote=c.text, score=round(score, 4)) for c, score in ranked[:TOP]]
    # Counts and the ranking kind only: never the question or a chunk's text.
    log.info("retrieved", extra={"count": len(chunks), "kind": "rerank" if rerank_usage else "rrf", "ms": int((time.monotonic() - started) * 1000)})
    return chunks, usage


_trigger_vectors: dict[str, tuple[str, np.ndarray]] | None = None


def trigger_vectors(index_dir: Path = INDEX_DIR) -> dict[str, tuple[str, np.ndarray]]:
    """The committed embeddings of the trigger rules' fixed queries
    (corpus/index/trigger_queries.json, written by tools/build_index.py),
    keyed by trigger id: (query text, vector). Empty when the file is absent,
    in which case brief-mode queries are embedded live or run keyword-only."""
    global _trigger_vectors
    if _trigger_vectors is None:
        path = index_dir / "trigger_queries.json"
        table: dict[str, tuple[str, np.ndarray]] = {}
        if path.is_file():
            doc = json.loads(path.read_text())
            for tid, entry in doc.get("queries", {}).items():
                table[tid] = (entry["query"], np.array(entry["embedding"], dtype=np.float32))
        _trigger_vectors = table
    return _trigger_vectors


def retrieve_many(queries: list[TriggerQuery]) -> tuple[list[TriggerEvidence], list[Usage]]:
    """Brief mode: the top PER_TRIGGER passages for each fired trigger, in
    one pass over the index. A query whose text matches its committed vector
    costs no model call; any other query is embedded live when a key is
    present, else searched keyword-only. A chunk appears under the first
    trigger that retrieves it and under no other, so the panel never shows
    the same passage twice."""
    usage: list[Usage] = []
    evidence: list[TriggerEvidence] = []
    seen: set[str] = set()
    started = time.monotonic()
    committed = trigger_vectors()
    for q in queries:
        vec: np.ndarray | None = None
        entry = committed.get(q.trigger_id)
        if entry is not None and entry[0] == q.query:
            vec = entry[1]
        elif os.environ.get("OPENAI_API_KEY"):
            vecs, u = embed([q.query])
            vec = vecs[0]
            usage.append(u)
        cands = index().candidates(q.query, vec)
        ranked, rerank_usage = rerank(q.query, cands)
        usage.extend(rerank_usage)
        chunks: list[Chunk] = []
        for c, score in ranked:
            if c.chunk_id in seen:
                continue
            seen.add(c.chunk_id)
            chunks.append(Chunk(chunk_id=c.chunk_id, source_id=c.source_id, section=c.section, quote=c.text, score=round(score, 4)))
            if len(chunks) == PER_TRIGGER:
                break
        evidence.append(TriggerEvidence(trigger_id=q.trigger_id, chunks=chunks))
    log.info("retrieved", extra={"count": sum(len(e.chunks) for e in evidence), "kind": "brief", "ms": int((time.monotonic() - started) * 1000)})
    return evidence, usage
