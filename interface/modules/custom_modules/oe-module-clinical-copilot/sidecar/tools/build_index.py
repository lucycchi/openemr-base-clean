"""Build the committed retrieval index: chunk the corpus, embed every chunk
once with text-embedding-3-small, write corpus/index/{chunks.json,
embeddings.npy}. Also embeds the eval queries listed in
tests/evals/fixtures/queries/queries.json (repo path, passed as argv[1])
so offline retrieval cases never call the API.

Usage (needs OPENAI_API_KEY):
  python -m tools.build_index [/path/to/tests/evals/fixtures/queries]
"""

from __future__ import annotations

import json
import sys
from pathlib import Path

import numpy as np

from copilot_sidecar import retrieve


def main() -> None:
    chunks = retrieve.chunk_corpus()
    vecs, usage = retrieve.embed([f"{c.section}\n{c.text}" for c in chunks])
    retrieve.INDEX_DIR.mkdir(parents=True, exist_ok=True)
    (retrieve.INDEX_DIR / "chunks.json").write_text(json.dumps({"model": retrieve.EMBED_MODEL, "chunks": [c.__dict__ for c in chunks]}, indent=1) + "\n")
    np.save(retrieve.INDEX_DIR / "embeddings.npy", vecs)
    print(f"indexed {len(chunks)} chunks from {len({c.source_id for c in chunks})} documents ({usage.input} embedding tokens)")
    if len(sys.argv) > 1:
        qdir = Path(sys.argv[1])
        queries = json.loads((qdir / "queries.json").read_text())
        qvecs, qusage = retrieve.embed([q["query"] for q in queries])
        for q, v in zip(queries, qvecs):
            (qdir / f"{q['id']}.json").write_text(json.dumps({"id": q["id"], "query": q["query"], "model": retrieve.EMBED_MODEL, "embedding": [round(float(x), 6) for x in v]}) + "\n")
        print(f"embedded {len(queries)} eval queries ({qusage.input} tokens)")


if __name__ == "__main__":
    main()
