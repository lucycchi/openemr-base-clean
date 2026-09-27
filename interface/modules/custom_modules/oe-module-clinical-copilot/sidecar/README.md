# Clinical Co-Pilot sidecar

A small FastAPI service beside OpenEMR that does what PHP should not:
parse PDFs, run the extraction model, anchor every proposed value to the
row it came from, route work through a LangGraph supervisor, and retrieve
guideline passages. PHP keeps authorization, storage, the chart, the
verifier and the UI. Design record: [clinical_copilot_week2/DESIGN.md](../../../../../clinical_copilot_week2/DESIGN.md);
architecture: [W2_ARCHITECTURE.md](../../../../../clinical_copilot_week2/W2_ARCHITECTURE.md).

| File | Does |
|---|---|
| `copilot_sidecar/app.py` | HTTP surface: `POST /run` (503 `overloaded` when extraction capacity is full), `GET /health` (liveness), `GET /ready` (every local dependency checked, 503 when one is missing; PHP's `ready.php` probes it), `GET /metrics` (extraction capacity and document outcomes), and the test-only `/eval/*` endpoints (`COPILOT_EVAL_ENDPOINTS=1`) |
| `copilot_sidecar/capacity.py` | How much extraction work is taken on: the admission gate, provider-call slots, the per-request deadline, backoff, and the `/metrics` counters (see "Capacity" below) |
| `copilot_sidecar/graph.py` | The supervisor + three workers (intake extractor, evidence retriever, critic) as a LangGraph `StateGraph`; deterministic routing; one `Handoff` per hop. Modes: `extract`, `answer`, `brief` (the chart's fired guideline triggers as fixed queries; retrieval once, then the critic once) |
| `copilot_sidecar/extractor.py` | One document: parse → propose (one model call per page, one targeted retry) → anchor |
| `copilot_sidecar/parse.py` | PyMuPDF text layer with word boxes; tesseract for scanned pages |
| `copilot_sidecar/anchor.py` | Row-level anchoring: a value counts only if it is found in the same row as its analyte and unit |
| `copilot_sidecar/llm.py` | The model calls (Structured Outputs): the extraction proposal and the critic's applicability verdict (`llm.critic.output`), prompts, `PROMPT_VERSION` |
| `copilot_sidecar/retrieve.py` | Hybrid retrieval: BM25 + committed embeddings, RRF, relevance floor, Cohere rerank when a key is set (a reranker error falls back to fused order); `retrieve_many()` for brief mode using the committed trigger-query vectors |
| `copilot_sidecar/schemas.py` | Pydantic models conforming to `../contracts/*.schema.json`, held to them by the shared examples |
| `copilot_sidecar/contracts.py` | Loads a contract file at runtime; the proposal contracts are what OpenAI is asked to fill (`response_format`, strict) |
| `copilot_sidecar/logging_setup.py` | JSON log lines restricted to an allowlist; the correlation id bound per request |
| `corpus/` | Six guideline summaries (own words), `manifest.json`, the committed index (`chunks.json`, `embeddings.npy`, and `trigger_queries.json`: the embedded fixed queries of `contracts/guideline_triggers.json`, rebuilt with `build_index.py` when a rule's query changes) |
| `tools/` | `generate_fixtures.py` (synthetic PDFs), `build_index.py`, `load_mock.py` (extraction load test against a rate-limited fake provider) |
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

## Capacity

Extraction is limited per process (the image runs one uvicorn process, so
per process is also per deployment today; N processes or containers would
each take the full fixed numbers). Set through the environment:

| Variable | Default | Controls |
|---|---|---|
| `COPILOT_MAX_EXTRACTIONS` | 8 | extract runs worked on at once (the most; fewer while the provider throttles) |
| `COPILOT_MAX_WAITING_EXTRACTIONS` | 16 | runs allowed to wait for a slot; beyond that, 503 `overloaded` at once |
| `COPILOT_EXTRACTION_MAX_WAIT_S` | 20 | longest a run waits for a slot before 503 `overloaded` |
| `COPILOT_EXTRACTION_DEADLINE_S` | 50 | time from arrival an extract run has (PHP waits 60 s); queue wait counts |
| `COPILOT_MAX_PROVIDER_CALLS` | 8 | the most model calls in flight (extraction pages, retries and critic verdicts together) |
| `COPILOT_PROVIDER_MAX_ATTEMPTS` | 3 | attempts per extraction model call (the SDK's own retries are off) |
| `COPILOT_PROVIDER_ATTEMPT_TIMEOUT_S` | 30 | longest single attempt, further cut to the time left before the deadline |
| `COPILOT_PROVIDER_BACKOFF_BASE_S` / `_CAP_S` | 1 / 8 | full-jitter backoff when the provider gives no Retry-After |

The provider-call limit adapts: a 429 halves it (at most once a second)
and pauses every new call for the provider's Retry-After; each success
grows it back by 1/limit, up to `COPILOT_MAX_PROVIDER_CALLS`. The number of
extraction slots follows it. So a limit set too high for the account
corrects itself, and quota drawn by PHP's own model calls (invisible here)
still shrinks it through the 429s it causes. The critic's calls take a
provider slot too, waiting at most 2 s (else the verdict is unknown).

A refused run is HTTP 503 with `{"code": "overloaded"}` and `Retry-After: 15`;
PHP keeps the file stored and passes `retry_after_s` to the panel, which
waits that long (plus up to 20 %) and tries again by itself, at most three
times. Retries happen only for 429 (except an exhausted quota), timeouts,
connection drops and 5xx; they wait the provider's Retry-After when it
sends one and never start one that the deadline could not fit.

When the client disconnects, a waiting run leaves the line and a running
one stops at the next page or retry (logged as `run abandoned`, status 499).

`GET /metrics` reports runs admitted, refused and abandoned, each document's
final outcome (a 200 run can carry a failed document), provider calls,
429s, retries, limit decreases and cooldowns, why calls were given up, the
current adaptive limit, and recent queue-wait and work timings.

`COPILOT_MAX_PROVIDER_CALLS` is a ceiling, not a target: set it to what the
account can serve on a good day and the adaptive limit finds the rest.
Compare two builds
on the same workload with the mock load test (no key, no network):

```bash
# from the sidecar directory; BEFORE is a copy of an older build, e.g.
#   git archive <sha> interface/modules/custom_modules/oe-module-clinical-copilot/sidecar | tar -x --strip-components=5 -C "$BEFORE"
R=$(git rev-parse --show-toplevel)
for build in "$BEFORE:before" "$PWD:after"; do
  docker run --rm --network none -v "${build%%:*}":/code:ro -v "$PWD/tools":/tools:ro \
    -v "$R/interface/modules/custom_modules/oe-module-clinical-copilot/contracts":/contracts:ro \
    -v "$R/tests/evals/fixtures/docs":/fixtures:ro -e COPILOT_CONTRACTS_DIR=/contracts \
    development-easy-copilot-sidecar python /tools/load_mock.py --sidecar-dir /code --label "${build##*:}"
done
```

Defaults: 50 users, 90 s, one second between a user's requests, the 5-page
`lab-layout1.pdf`, a provider that serves 4 calls/s (burst 8) at 2 s each.
`--rate`, `--burst`, `--latency`, `--users`, `--seconds`, `--client-timeout`
(users who give up early) and `--env KEY=VALUE` (sidecar settings) change
the workload. Results from
2026-09-26 are in `tests/load/results/20260926T-mock-*.json`.
