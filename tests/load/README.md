# Clinical Co-Pilot load tests

k6 scenarios that drive the deployed agent the way physicians do — log in,
open a chart, get the briefing, ask a follow-up, and (Week 2) attach a lab
report and have it extracted — at 10 and 50 concurrent users, while
sampling CPU and memory of every container on the target host. Results are
committed under `results/` and summarised in
[`clinical_copilot_week1/BASELINES.md`](../../clinical_copilot_week1/BASELINES.md)
(Week 1 build) and [`clinical_copilot_week2/BASELINES.md`](../../clinical_copilot_week2/BASELINES.md)
(Week 2 build, with the sidecar).

Nothing is mocked. Requests go through OpenEMR's login and session, the
module's CSRF and ACL checks, fact assembly against the real database, the
briefing cache, the real model provider (for cold briefings and every
follow-up), the verifier and the omission guard.

## Files

| File | What |
|---|---|
| `copilot.js` | The k6 script. One virtual user = one physician session. Scenarios `brief`, `ask`, `mixed`, `extract` (below). Writes `<label>.json` and `<label>.txt` to `RESULTS_DIR`. |
| `run-baselines.sh` | Runs the matrix (`VUS_LIST` × `SCENARIOS`, default `10 50` × `brief mixed ask extract`), starts `sample-stats.sh` on the target for each run, 30 s quiet gap between runs. |
| `sample-stats.sh` | `docker stats` + `/proc/loadavg` every 2 s as CSV for the app, sidecar and database containers. Read-only; runs locally or over ssh with output piped back. |
| `summarise.py` | Turns one run's JSON + CSV into the Markdown tables in `BASELINES.md`: latency/errors, the extraction table, and CPU/memory with a sidecar column. |
| `cleanup-documents.php` | Removes everything the `extract` scenario attached to its patient (documents, lab rows, provenance, intake rows, briefing cache). Run in the openemr container after a matrix: `php tests/load/cleanup-documents.php 30`. |
| `results/` | Committed outputs. No chart data: timings, counts, status codes, CPU/memory only. |

## Scenarios

| `SCENARIO` | Per iteration | What it measures |
|---|---|---|
| `brief` | open chart → `brief` | After the first pass over the 30 seed patients every briefing is a cache hit, so this is the deterministic path: session, fact assembly (DB + ACL), verify, omission guard. The floor of "time to verified facts". |
| `ask` | open chart → `brief` → `ask` | Follow-ups are never cached: one real model call per iteration. The model-bound path, and the one that hits provider rate limits first. |
| `mixed` | open chart → `brief` → `ask` on 30 % of iterations (`ASK_SHARE`) | The clinic pattern from `USERS.md`: most chart opens are a glance at the briefing; a minority ask something. |
| `extract` (Week 2) | open the load patient's chart (`EXTRACT_PID`, default 30) → upload the five-page synthetic lab report with a unique trailer → `extract` | The document path end to end: multipart upload and dedup, the sidecar's parse, one model call per page, row-level anchoring, and the four-table persist. Every iteration is a real extraction (the trailer defeats the per-patient dedup), so this is the throughput ceiling of the sidecar and of the provider's token limit. |

Week 2 changed `ask` as well: every follow-up now goes through the
sidecar's evidence retriever (query embedding, BM25 + cosine fusion,
Cohere rerank when a key is configured) before the model call, so the ask
latency includes that leg and the summary records `guideline_hit_pct`.

Each VU thinks for `THINK` seconds (default 1) between iterations and
rotates through `PIDS` (default 1–30) so consecutive iterations of one VU
hit different charts.

## Running

k6 is a single binary (`https://github.com/grafana/k6/releases`; the
baselines were captured with v2.2.0).

```bash
# one run
k6 run -e BASE_URL=https://146-190-139-37.sslip.io -e LOGIN_PASS=<admin password> \
       -e SCENARIO=mixed -e VUS=10 -e DURATION=2m -e LABEL=try-10vu-mixed tests/load/copilot.js

# the whole matrix against the droplet, with CPU/memory sampled over ssh
BASE_URL=https://146-190-139-37.sslip.io LOGIN_PASS=<admin password> STATS=ssh SSH_HOST=do-openemr \
  tests/load/run-baselines.sh

# against the local dev stack (docker stats sampled locally)
BASE_URL=http://localhost:8300 STATS=local tests/load/run-baselines.sh

# tables for BASELINES.md
python3 tests/load/summarise.py <stamp>
```

`LOGIN_USER`/`LOGIN_PASS` are prefixed on purpose: k6 exposes the host's
environment as `__ENV`, so plain `USER` would be your shell user.

## Reading the output

`<label>.txt` is a one-screen summary; `<label>.json` has the same numbers
for tooling:

| Field | Meaning |
|---|---|
| `throughput_rps` | HTTP requests per second achieved (login page, chart open, brief, ask). |
| `http_failed_pct` | k6's view: any non-2xx/3xx response. |
| `copilot_request_error_pct` | A `brief`/`ask` that was not a 200 with the expected body (contract violation or server error). |
| `summary_unavailable_pct` | 200 with `narration.status` / `answer.status` non-null: the model could not be used (provider busy, timeout…). The fact table was still delivered. |
| `verification_fail_pct` | `total_failure: true` — every model sentence stripped. Should be 0. |
| `brief_cache_hit_pct` | Share of briefings served from `copilot_briefing_cache`. |
| `model_calls` | Cold briefings + follow-ups: real provider calls made. |
| `guideline_hit_pct` | Share of follow-ups answered with at least one guideline passage (the retrieval leg found evidence). |
| `extractions`, `extract_ok_pct`, `extract_verified_pct`, `extract_confidence_p50` | Documents extracted; share with `status: extracted`; share with nothing unverified and nothing unextracted; median share of fields anchored. |
| `latency_ms.*` | p50/p95/p99/max per endpoint; `brief_cache_hit` vs `brief_cold` separates "time to verified facts" from "time to summary"; `upload` and `extract` are the document path. |

Retry counts for the run window are read from Langfuse (`llm_retried`,
`llm_attempts` on each trace), not from the client: the response body does
not expose them.

## Cleaning up after `extract`

The scenario attaches one document per iteration to `EXTRACT_PID` (a few
hundred at 50 users). They are real OpenEMR documents with real lab rows,
so remove them afterwards, on the target, as the web user:

```bash
# droplet
ssh do-openemr 'cd ~/openemr && docker compose exec -T openemr sh -c "cd /var/www/localhost/htdocs/openemr && su -s /bin/sh apache -c \"php tests/load/cleanup-documents.php 30\""'
# dev stack
openemr-cmd e "su -s /bin/sh apache -c 'cd /var/www/localhost/htdocs/openemr && php tests/load/cleanup-documents.php 30'"
```

It prints how many it removed and how many are left (0).

## Cost and rate limits

`ask` at 50 VUs makes roughly 10 model calls per second (≈ 600 RPM,
≈ 400k tokens per minute with gpt-4o-mini). That exceeds the default
per-minute token limit of a low OpenAI usage tier, so 429s, the module's
one retry, and `summary_unavailable` responses are expected at that level
and are part of the baseline, not a test failure. A 2-minute `ask` run at
50 VUs costs on the order of $0.15. `extract` is heavier per iteration
(five model calls, ~4.7k tokens per document): at 50 VUs a 2-minute run
is a few hundred documents, on the order of $0.30–0.50, and it is the
scenario most likely to hit the provider's per-minute token limit and the
sidecar's single worker; those ceilings are the point of the baseline.
