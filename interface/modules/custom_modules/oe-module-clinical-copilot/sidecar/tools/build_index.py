"""Build the committed retrieval index: chunk the corpus, embed every chunk
once with text-embedding-3-small, write corpus/index/{chunks.json,
embeddings.npy}. Also embeds the eval queries listed in
tests/evals/fixtures/queries/queries.json (repo path, passed as argv[1])
so offline retrieval cases never call the API.

Usage (needs OPENAI_API_KEY):
  python -m tools.build_index [/path/to/tests/evals/fixtures/queries]

What the index is. The guideline corpus is six Markdown summaries under
corpus/, one per guideline, listed in corpus/manifest.json. Retrieval
(copilot_sidecar/retrieve.py) does not search those files directly; it
searches an index made of three parts:

  chunks      each "##" section of each document, as one unit of text with a
              stable id, the source document's id and its heading path.
              Stored in chunks.json. A chunk is what gets quoted back to the
              clinician, with its heading as the citation.
  BM25 tokens the keyword leg: the words of each chunk, lower-cased with
              stop words removed. Not stored; retrieve.py rebuilds them from
              chunks.json in memory when the index loads, because that is
              instant and needs no API.
  embeddings  the meaning leg: one vector per chunk from OpenAI's
              text-embedding-3-small, scaled to unit length so a dot product
              with a question's vector is a cosine similarity. Stored in
              embeddings.npy, in the same order as the chunks. Producing them
              is the one step that costs an API call, which is why they are
              built here once and committed rather than computed at startup.

When it must be rebuilt: whenever anything under corpus/ changes (a
document's text, a heading, the manifest), and whenever EMBED_MODEL
changes. Chunk ids are derived from document id, heading and position, so
an edited corpus produces different ids; tests/test_retrieve.py fails with
"rebuild the index: corpus changed", and at runtime Index.load refuses an
index whose vector count no longer matches its chunk count, which /ready
reports as "corpus_index unavailable" (ALERTS.md, S2). Commit corpus/index/
with the corpus change and redeploy; until then answers are facts-only.

The eval queries: the retrieval eval cases and tests/test_retrieve.py run
offline, so each question's embedding is also computed here once and saved
as <id>.json beside queries.json. Re-run with the queries directory as the
argument whenever queries.json or EMBED_MODEL changes.
"""

from __future__ import annotations

import json
import sys
from pathlib import Path

import numpy as np

from copilot_sidecar import retrieve


def main() -> None:
    # 1. Split the corpus into chunks. The same function retrieve.py uses at
    #    runtime, so the ids written here are the ids the tests compare.
    chunks = retrieve.chunk_corpus()
    # 2. Embed every chunk in one API call. The heading is prepended to the
    #    text so a chunk's vector carries its context ("<document title> >
    #    <section heading>"), which is also how retrieve.py phrases the
    #    chunk when it asks the reranker.
    vecs, usage = retrieve.embed([f"{c.section}\n{c.text}" for c in chunks])
    # 3. Write both halves of the index. chunks.json records which embedding
    #    model made the vectors, so a model change is visible in the diff.
    #    `c.__dict__` turns each chunk object into a plain key/value mapping.
    retrieve.INDEX_DIR.mkdir(parents=True, exist_ok=True)
    (retrieve.INDEX_DIR / "chunks.json").write_text(json.dumps({"model": retrieve.EMBED_MODEL, "chunks": [c.__dict__ for c in chunks]}, indent=1) + "\n")
    np.save(retrieve.INDEX_DIR / "embeddings.npy", vecs)
    print(f"indexed {len(chunks)} chunks from {len({c.source_id for c in chunks})} documents ({usage.input} embedding tokens)")
    # 3b. The guideline trigger queries (contracts/guideline_triggers.json):
    #     fixed strings PHP sends in brief mode, embedded once here so a
    #     briefing's retrieval costs no model call. Keyed by trigger id with
    #     the query text, so retrieve.py can tell a changed query from a
    #     committed one.
    import os
    contracts = Path(os.environ.get("COPILOT_CONTRACTS_DIR") or Path(__file__).resolve().parents[2] / "contracts")
    rules_path = contracts / "guideline_triggers.json"
    if rules_path.is_file():
        rules = json.loads(rules_path.read_text())["rules"]
        tvecs, tusage = retrieve.embed([r["query"] for r in rules])
        table = {r["id"]: {"query": r["query"], "embedding": [round(float(x), 6) for x in v]} for r, v in zip(rules, tvecs)}
        (retrieve.INDEX_DIR / "trigger_queries.json").write_text(json.dumps({"model": retrieve.EMBED_MODEL, "queries": table}, indent=1) + "\n")
        print(f"embedded {len(rules)} trigger queries ({tusage.input} tokens)")
    # 4. Optional: the eval queries. Only when a directory was given, because
    #    this is a second API call and the queries live outside the sidecar.
    if len(sys.argv) > 1:
        qdir = Path(sys.argv[1])
        queries = json.loads((qdir / "queries.json").read_text())
        qvecs, qusage = retrieve.embed([q["query"] for q in queries])
        # One small file per query: its id, its wording, the model, and the
        # vector rounded to six decimals (enough for ranking, small to commit).
        for q, v in zip(queries, qvecs):
            (qdir / f"{q['id']}.json").write_text(json.dumps({"id": q["id"], "query": q["query"], "model": retrieve.EMBED_MODEL, "embedding": [round(float(x), 6) for x in v]}) + "\n")
        print(f"embedded {len(queries)} eval queries ({qusage.input} tokens)")


if __name__ == "__main__":
    main()
