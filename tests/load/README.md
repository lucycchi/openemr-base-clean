# Clinical Co-Pilot load tests

k6 scenarios that drive the deployed agent the way physicians do — log in,
open a chart, get the briefing, ask a follow-up — at 10 and 50 concurrent
users, while sampling CPU and memory on the target host. Results are
committed under `results/` and summarised in
[`clinical_copilot_week1/BASELINES.md`](../../clinical_copilot_week1/BASELINES.md).

Nothing is mocked. Requests go through OpenEMR's login and session, the
module's CSRF and ACL checks, fact assembly against the real database, the
briefing cache, the real model provider (for cold briefings and every
follow-up), the verifier and the omission guard.

## Files

| File | What |
|---|---|
| `copilot.js` | The k6 script. One virtual user = one physician session. Scenarios `brief`, `ask`, `mixed` (below). Writes `<label>.json` and `<label>.txt` to `RESULTS_DIR`. |
| `run-baselines.sh` | Runs the matrix (`VUS_LIST` × `SCENARIOS`), starts `sample-stats.sh` on the target for each run, 30 s quiet gap between runs. |
| `sample-stats.sh` | `docker stats` + `/proc/loadavg` every 2 s as CSV. Read-only; runs locally or over ssh with output piped back. |
| `summarise.py` | Turns one run's JSON + CSV into the Markdown tables in `BASELINES.md`. |
| `results/` | Committed outputs. No chart data: timings, counts, status codes, CPU/memory only. |

## Scenarios

| `SCENARIO` | Per iteration | What it measures |
|---|---|---|
| `brief` | open chart → `brief` | After the first pass over the 30 seed patients every briefing is a cache hit, so this is the deterministic path: session, fact assembly (DB + ACL), verify, omission guard. The floor of "time to verified facts". |
| `ask` | open chart → `brief` → `ask` | Follow-ups are never cached: one real model call per iteration. The model-bound path, and the one that hits provider rate limits first. |
| `mixed` | open chart → `brief` → `ask` on 30 % of iterations (`ASK_SHARE`) | The clinic pattern from `USERS.md`: most chart opens are a glance at the briefing; a minority ask something. |

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
| `latency_ms.*` | p50/p95/p99/max per endpoint; `brief_cache_hit` vs `brief_cold` separates "time to verified facts" from "time to summary". |

Retry counts for the run window are read from Langfuse (`llm_retried`,
`llm_attempts` on each trace), not from the client: the response body does
not expose them.

## Cost and rate limits

`ask` at 50 VUs makes roughly 10 model calls per second (≈ 600 RPM,
≈ 400k tokens per minute with gpt-4o-mini). That exceeds the default
per-minute token limit of a low OpenAI usage tier, so 429s, the module's
one retry, and `summary_unavailable` responses are expected at that level
and are part of the baseline, not a test failure. A 2-minute `ask` run at
50 VUs costs on the order of $0.15.
