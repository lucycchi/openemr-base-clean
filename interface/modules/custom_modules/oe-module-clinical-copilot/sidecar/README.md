# Clinical Co-Pilot sidecar

A small FastAPI service beside OpenEMR that does what PHP should not:
parse PDFs, run the extraction model, anchor every proposed value to the
row it came from, route work through a LangGraph supervisor, and retrieve
guideline passages. PHP keeps authorization, storage, the chart, the
verifier and the UI. Design record: [clinical_copilot_week2/DESIGN.md](../../../../../clinical_copilot_week2/DESIGN.md);
architecture: [W2_ARCHITECTURE.md](../../../../../clinical_copilot_week2/W2_ARCHITECTURE.md).

| File | Does |
|---|---|
| `copilot_sidecar/app.py` | HTTP surface: `POST /run`, `GET /health`, and the test-only `/eval/*` endpoints (`COPILOT_EVAL_ENDPOINTS=1`) |
| `copilot_sidecar/graph.py` | The supervisor + two workers as a LangGraph `StateGraph`; deterministic routing; one `Handoff` per hop |
| `copilot_sidecar/extractor.py` | One document: parse → propose (one model call per page, one targeted retry) → anchor |
| `copilot_sidecar/parse.py` | PyMuPDF text layer with word boxes; tesseract for scanned pages |
| `copilot_sidecar/anchor.py` | Row-level anchoring: a value counts only if it is found in the same row as its analyte and unit |
| `copilot_sidecar/llm.py` | The model call (Structured Outputs), prompts, `PROMPT_VERSION` |
| `copilot_sidecar/retrieve.py` | Hybrid retrieval: BM25 + committed embeddings, RRF, relevance floor, Cohere rerank when a key is set |
| `copilot_sidecar/schemas.py` | Pydantic models mirroring `../contracts/*.schema.json` |
| `copilot_sidecar/logging_setup.py` | JSON log lines restricted to an allowlist; the correlation id bound per request |
| `corpus/` | Six guideline summaries (own words), `manifest.json`, the committed index |
| `tools/` | `generate_fixtures.py` (synthetic PDFs), `build_index.py` |
| `tests/` | pytest; run inside the gate |

## Logging and the correlation id

Every line is JSON with only allowlisted fields (`logging_setup.ALLOWED`):
ids, counts, timings, tokens, model names, status and reason codes. A
document's text, a question, an extracted value, a filename or an
exception message can never appear, whatever a log call attaches.

The request's correlation id is bound to a context variable by the HTTP
middleware (from `X-Correlation-Id`) and again by `/run` from the body, and
the formatter writes it on every line, so no call has to remember it. The
same id is sent to OpenAI as the `user` field plus header and to Cohere as
a header. One `/run` produces, in order: `handoff` per hop, `model_call`
per page (and per embedding or rerank), `extracted` or `retrieved`, `run`.

```bash
cd docker/development-easy && docker compose logs copilot-sidecar | grep <correlation id>
```

Why and the trade-offs: [ENGINEERING_REQUIREMENTS.md § 2](../../../../../clinical_copilot_week2/ENGINEERING_REQUIREMENTS.md#2-correlation-id-across-every-service-boundary).

## Running

```bash
cd docker/development-easy
docker compose build copilot-sidecar && docker compose up -d copilot-sidecar   # the source is baked into the image; rebuild after editing
docker compose exec -T copilot-sidecar python -m pytest -q
```

Environment: `OPENAI_API_KEY` (extraction, query embedding), `OPENAI_MODEL`
(default `gpt-4o-mini`), `COHERE_API_KEY` (rerank; optional, RRF order
without it), `COPILOT_EVAL_ENDPOINTS`, `COPILOT_FIXTURES_DIR`,
`COPILOT_CORPUS_DIR`, `COPILOT_QUERIES_DIR`. The service holds no PHI at
rest: documents arrive as bytes and leave as extractions.
