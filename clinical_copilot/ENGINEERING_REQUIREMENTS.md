# ENGINEERING_REQUIREMENTS.md — Clinical Co-Pilot

Status of the nine engineering requirements that are graded alongside the
core submission. Each item states whether it is done, where the evidence is
(file paths are relative to the repository root, the module is
`interface/modules/custom_modules/oe-module-clinical-copilot/`, abbreviated
`<module>/` below), and what is still open. Audited 2026-09-16 against the
`audit` branch by reading the code, not the docs.

## Checklist

| # | Requirement | Status |
|---|---|---|
| 1 | Test design for boundaries, invariants, regression | ✅ Done |
| 2 | Correlation ID on every log entry, tool call, LLM interaction | ✅ Done |
| 3 | Canonical API/event/schema contracts as source of truth | ✅ Done |
| 4 | Real-time dashboard (requests, errors, p50/p95, tool calls, retries, verification rate) | ⚠️ Partial |
| 5 | Runnable API collection (Bruno) | ✅ Done |
| 6 | Separate `/health` and `/ready` with real dependency checks | ✅ Done |
| 7 | At least three alerts (p95 latency, error rate, tool failure rate) | ✅ Defined + receiver built; Langfuse rules to be configured |
| 8 | Baseline CPU, memory, latency, throughput profiles | ✅ Done |
| 9 | Load/stress tests at 10 and 50 concurrent users | ✅ Done |

---

## 1. Test design for boundaries, invariants, and regression — ✅ Done

**Requirement.** Every evaluation case exercises a boundary condition, an
invariant, or a known regression risk; no happy-path-only suites; the
failure mode each test guards against is documented.

**How it is done.**

- Every eval case in [`tests/evals/cases/`](../tests/evals/cases/) is a JSON
  file with two mandatory fields: `guards` (one of `boundary`, `invariant`,
  `regression`, `authorization`, `known limitation`) and `failure_mode` (one
  plain sentence naming what would go wrong). The harness
  [`tests/evals/run.php`](../tests/evals/run.php) reads both. Tally across
  the 15 cases: 8 invariant, 3 boundary, 3 authorization, 1 regression.
- The table of cases and failure modes is in
  [`tests/evals/README.md`](../tests/evals/README.md) ("Cases and the failure
  mode each guards"), and the design rationale is in
  [`ARCHITECTURE.md § Evaluation`](ARCHITECTURE.md#evaluation).
- Recorded cases 01–08 replay a fixed fact set and a hand-written model
  reply through `Verifier` + `OmissionGuard` and require exact `kept`,
  `stripped`, `omitted_ids`, `total_failure`. They feed replies a
  well-behaved model would never send: an uncited sentence (01), a
  fabricated fact id (02), a correct citation with a wrong number (03), a
  deliberately omitted must-surface fact (04), an **empty fact set** (05),
  an instruction planted in a chart field (06), the inline `[id, id]`
  regression found 2026-09-15 (07), and semantic inversion as a recorded
  known limitation (08).
- Live cases 09–15 run real seed charts through real OpenAI and check
  invariants independently of the implementation: `max_stripped`,
  `no_ungrounded_kept` (the harness re-implements the digit scan rather than
  calling `Verifier`), `no_identifier_leak` (substring check against
  `patient_data`), `answer_type: not_in_facts` for out-of-window (10),
  arithmetic (11), ambiguous (12), identifier extraction (13), other-patient
  (14), and instruction-override (15) questions.
- The PRD's three named edge cases are mapped to cases in
  [`ARCHITECTURE.md`](ARCHITECTURE.md) ("The edge cases the PRD names"):
  missing data → case 05 plus unit tests for no prior encounter, zero dates,
  missing reference ranges, missing facility; ambiguous queries → 11, 12;
  unauthorized extraction → 13, 14, 15 plus ACL refusal tests.
- 88 isolated unit tests in
  [`tests/Tests/Isolated/Modules/ClinicalCopilot/`](../tests/Tests/Isolated/Modules/ClinicalCopilot/)
  (`FactAssemblerTest` 28, `VerifierTest` 12, `NarrationPipelineTest` 12,
  `OpenAiClientTest` 10, `ReadinessTest` 6, `LangfuseTracerTest` 5,
  `OmissionGuardTest` 4, `PanelPayloadTest` 4, `PricingTest` 3,
  `StepRecorderTest` 2, `CorrelatedLoggerTest` 1, `QuestionScopeTest` 1)
  cover malformed model output (`LlmSchemaMismatch`), 429/5xx/timeout
  handling, ACL denial before any read, sensitivity filtering, and
  `0000-00-00` dates.
- The suite has caught two real defects (case 07 and case 14), documented
  under "What the suite has already found" in `ARCHITECTURE.md`.
- Latest results: [`tests/evals/results.json`](../tests/evals/results.json)
  (15/15, 0 of 60 sentences stripped) and
  [`results-deployed.json`](../tests/evals/results-deployed.json) (11/11 on
  the droplet).

**Run.**

```bash
openemr-cmd e "su -s /bin/sh apache -c 'php tests/evals/run.php'"          # recorded
openemr-cmd e "su -s /bin/sh apache -c 'php tests/evals/run.php --live'"   # + live
openemr-cmd pit                                                              # unit
```

---

## 2. Correlation ID across service boundaries — ✅ Done

**Requirement.** Every agent invocation gets a unique correlation ID that
appears in every log entry, tool call, and LLM interaction so a full trace
can be reconstructed from logs alone.

**How it is done.**

- **Generated** once per request in the controller constructor:
  [`ChatController.php:67`](../interface/modules/custom_modules/oe-module-clinical-copilot/src/Controller/ChatController.php#L67)
  via [`CorrelationId::generate()`](../interface/modules/custom_modules/oe-module-clinical-copilot/src/CorrelationId.php)
  (32 hex chars from `random_bytes(16)`).
- **Every log entry.**
  [`Ops/CorrelatedLogger.php`](../interface/modules/custom_modules/oe-module-clinical-copilot/src/Ops/CorrelatedLogger.php)
  is a PSR-3 decorator wrapping the logger; `log()` merges
  `correlation_id` into the context of every entry. The controller only ever
  logs through it (`copilot request`, `copilot tool failed`,
  `copilot response`, `copilot access denied`, `copilot request failed`).
  Unit test: `CorrelatedLoggerTest`.
- **Every tool call.** Each step (`authorize_and_assemble_facts`,
  `cache_lookup`, `llm.briefing` / `llm.follow_up`, `verify`, `cache_store`,
  `omission_guard`) is recorded by
  [`Ops/StepRecorder.php`](../interface/modules/custom_modules/oe-module-clinical-copilot/src/Ops/StepRecorder.php)
  with name, duration, detail and error, and the ordered `steps` array is
  attached to the `copilot response` / `copilot tool failed` /
  `copilot access denied` log lines, which carry the id. Unit tests:
  `StepRecorderTest`, `NarrationPipelineTest` (step order, failed step
  carries the cause).
- **Every LLM interaction.** In Langfuse the trace id *is* the correlation
  id ([`LangfuseTracer.php:39`](../interface/modules/custom_modules/oe-module-clinical-copilot/src/Ops/LangfuseTracer.php#L39)),
  each step is a span with `traceId = correlationId` (line 56), and the
  model call is a `generation` with `traceId = correlationId` (line 73)
  holding model, tokens, latency, and cost. Unit test: `LangfuseTracerTest`.
- **Audit log.** The OpenEMR `log` table row (event `clinical-copilot`)
  carries `correlation_id=` in its comment on success
  ([`ChatController.php:196`](../interface/modules/custom_modules/oe-module-clinical-copilot/src/Controller/ChatController.php#L196))
  and on ACL denial (line 145).
- **Returned to the caller.** `correlation_id` in every JSON body, including
  the 403, 500 and error paths
  ([`PanelPayload::base`](../interface/modules/custom_modules/oe-module-clinical-copilot/src/PanelPayload.php#L61),
  `ChatController.php:102,147,155,238,241`), plus an `X-Correlation-Id`
  response header (line 297). The panel shows the first 8 chars as
  `ref …` ([`panel.js:166`](../interface/modules/custom_modules/oe-module-clinical-copilot/public/assets/panel.js#L166)).
- **Reconstruction.** `ARCHITECTURE.md § Observability` walks through
  finding one request in all three sinks from the 8-char ref.

- **On the outbound OpenAI request itself.** `OpenAiClient` receives the id
  in its constructor ([`ChatController::pipeline()`](../interface/modules/custom_modules/oe-module-clinical-copilot/src/Controller/ChatController.php))
  and sends it both as OpenAI's per-request `user` field (stored on the
  provider's side, so a request can be found in their logs and abuse
  reports) and as an `X-Correlation-Id` request header (for our own egress
  logs). Unit test: `OpenAiClientTest::testCorrelationIdRidesOnTheRequestAsUserFieldAndHeader`.
  Added 2026-09-16 after this audit found it missing.

---

## 3. Canonical API/event/schema contracts — ✅ Done

**Requirement.** Strict schemas (Pydantic, Zod, or equivalent) for every
tool input and output; contracts are the source of truth, not the
implementation.

**How it is done.**

- Eleven JSON Schema (draft 2020-12) files under
  [`<module>/contracts/`](../interface/modules/custom_modules/oe-module-clinical-copilot/contracts/)
  with a [README](../interface/modules/custom_modules/oe-module-clinical-copilot/contracts/README.md)
  mapping each to the code that conforms to it. Every contract has
  `additionalProperties: false` at the top level and a description on every
  property.

  | Contract | What it governs |
  |---|---|
  | `chat.request` | POST body to `chat.php`: `csrf_token_form`, `action ∈ {brief, ask}`, and for `ask` a non-blank `question` (≤ 500 chars) and a 64-hex `facts_hash`; `transcript` as a JSON string; no other fields (a `pid` in the body is rejected, not ignored). |
  | `chat.briefing.response`, `chat.answer.response`, `chat.chart-changed.response`, `chat.error.response` | The four bodies `chat.php` can return, including the closed enum of physician-facing `status` strings and the `correlation_id` pattern. |
  | `health.response`, `ready.response` | The two ops endpoints, including the closed enum of per-dependency probe results. |
  | `llm.briefing.output`, `llm.followup.output` | What the model must return; `answer_type ∈ {cited, not_in_facts}`. |
  | `fact`, `sentence` | Shared definitions, `$ref`'d by the responses. |

- **The files are what runs, not a mirror.**
  [`Contracts.php`](../interface/modules/custom_modules/oe-module-clinical-copilot/src/Contracts.php)
  loads them; [`Prompt::briefingSchema()` / `followUpSchema()`](../interface/modules/custom_modules/oe-module-clinical-copilot/src/Prompt.php#L67)
  now return `Contracts::forOpenAi(...)` (the file with `$schema`/`$id`/`title`
  stripped) instead of building arrays, and that is what is sent to OpenAI
  as `response_format.json_schema` with `strict: true`. The previous
  hand-built arrays were deleted. `Prompt::VERSION` was bumped to
  `2026-09-18.1` because the schema sent to the model changed.
- **Inbound requests are parsed against the contract at the boundary.**
  [`ChatRequest::fromBag()`](../interface/modules/custom_modules/oe-module-clinical-copilot/src/ChatRequest.php)
  turns the form body into a typed, readonly object (`ChatAction` enum,
  `?string $question`, `?string $factsHash`, typed transcript) or throws
  [`InvalidRequest`](../interface/modules/custom_modules/oe-module-clinical-copilot/src/InvalidRequest.php)
  with a physician-facing message and an HTTP status (400; 403 for a
  missing CSRF token). `ChatController` calls it first
  ([`ChatController.php:113`](../interface/modules/custom_modules/oe-module-clinical-copilot/src/Controller/ChatController.php#L113))
  and the ad hoc `getString('action'/'question'/'facts_hash'/'transcript')`
  reads and the private `transcript()` parser were removed. Two behaviours
  changed as a result, both toward the contract: a `pid` in the body is now
  a 400 instead of silently ignored, and `ask` without a `facts_hash` is a
  400 instead of a `chart_changed` response.
- **Tests hold the code to the files** (41 tests, 122 assertions, isolated,
  run on every commit):
  - [`ContractsTest`](../tests/Tests/Isolated/Modules/ClinicalCopilot/ContractsTest.php):
    every contract is a strict 2020-12 object schema; `Prompt` returns
    exactly the contract file; the `fact.category` enum equals
    `FactCategory::cases()`; `PanelPayload::briefing/answer/chartChanged`,
    every error body, `ReadinessReport` (ready/degraded/not-ready) and the
    health body validate against their contracts using
    `justinrainbow/json-schema` (already a dev dependency); negative cases
    prove the contracts reject uncited sentences, unknown `answer_type`,
    unknown actions, `ask` without a question, and a short correlation id.
  - [`ChatRequestTest`](../tests/Tests/Isolated/Modules/ClinicalCopilot/ChatRequestTest.php):
    eleven bodies are judged by the schema and by `ChatRequest::fromBag()`
    and the verdicts must match; plus trim/cap, transcript last-10 and
    garbage handling, and the 403-for-missing-CSRF rule.
- Writing the tests first surfaced two defects that were fixed: the
  contract accepted a whitespace-only question (now `pattern: \S`), and the
  controller truncated the question *before* trimming so leading spaces ate
  the 500-character budget (now trims first).
- Verified end to end: Bruno collection 16/16 requests, 35/35 assertions
  against the local stack after the change; PHPStan (full run, level 10)
  and phpcs clean on all new and changed files.

## 4. Dashboard: request count, error count, latency, queue depth, retries, decision outcomes — ⚠️ Partial

**Requirement.** A real-time dashboard (LangSmith, Langfuse, Braintrust, or
equivalent) showing total requests, error rate, p50/p95 latency, tool call
counts, retry counts, verification pass/fail rate, plus agent-specific
metrics.

**What exists — the data is emitted.**

Every request writes one Langfuse trace
([`Ops/LangfuseTracer.php`](../interface/modules/custom_modules/oe-module-clinical-copilot/src/Ops/LangfuseTracer.php))
with the fields a dashboard needs, assembled at
[`ChatController.php:167-181`](../interface/modules/custom_modules/oe-module-clinical-copilot/src/Controller/ChatController.php#L167):

| Dashboard need | Trace field | Where |
|---|---|---|
| Total requests | one trace per request, tag `clinical-copilot` | trace |
| Error rate | `http_status` (200/400/403/500), `status` (non-null on failure) | trace metadata / generation `level` |
| p50 / p95 latency | `duration_ms` (whole request), `llm_duration_ms` (model call) | trace metadata, generation start/end |
| Tool call counts | one span per step (`authorize_and_assemble_facts`, `cache_lookup`, `llm.*`, `verify`, `cache_store`, `omission_guard`), each with `duration_ms` and `level` ERROR on failure | spans |
| Retry counts | `llm_attempts` (0/1/2), `llm_retried` (bool); `attempts` on the `llm.*` span | trace metadata, span detail |
| Verification pass/fail | `verification_pass` (bool), `stripped`, `omitted`, `total_failure` | trace metadata |
| Decision outcomes | `answer_type` (`cited` / `not_in_facts`), `chart_changed`, `from_cache`, `denied` | trace metadata |
| Tokens / cost | `prompt_tokens`, `completion_tokens`, `cost_usd`, generation `usage.totalCost` | trace + generation |

The same fields are on the `copilot response` log line and (as counts) in
the audit row. Deployment wires `LANGFUSE_PUBLIC_KEY` / `LANGFUSE_SECRET_KEY`
/ `LANGFUSE_HOST` ([`docker/vps/docker-compose.yml:87-89`](../docker/vps/docker-compose.yml#L87));
`/ready` confirms `langfuse: ok` on the droplet.

**What is missing.**

- **No dashboard artefact.** Nothing in the repository shows that a
  Langfuse dashboard has been built: no dashboard export, no screenshot, no
  link, no list of widgets. `ARCHITECTURE.md` describes traces, not a
  dashboard. Whether one exists in the Langfuse Cloud project is unknown
  from the repo — see open question Q3.
- ~~Retry count is not emitted.~~ Fixed 2026-09-16: `LlmCompletion` and
  every `LlmException` now carry `attempts` (1, or 2 when the one retry on
  429/5xx/timeout was used); `NarrationPipeline::llmAttempts()` surfaces it
  (0 when no model call was made, e.g. a cache hit); the controller writes
  `llm_attempts` and `llm_retried` to the trace metadata and log line and
  `llm_attempts=` to the audit row, and the `llm.*` span's detail carries
  `attempts`. Unit tests: `OpenAiClientTest` (attempts on success, on
  retry-then-success, on retry-then-fail), `NarrationPipelineTest`
  (attempts on the step, on a final failure, zero on cache hit).
- **Queue depth** is not applicable in the literal sense (synchronous PHP
  request/response, no queue). The doc should say so explicitly and offer
  the nearest analogue (concurrent in-flight requests, or Apache worker
  utilisation from the load test).
- Langfuse Cloud's v3 ingestion API delays data by ~10 minutes
  ([`TODOS.md:45`](../TODOS.md#L45)); "real time" is therefore
  approximate until the OTel/v4 transport is adopted.

**Proposed completion.** Document the existing Langfuse dashboard (export
or screenshots into `clinical_copilot/dashboard/` with a README naming each
widget and the trace field it reads), add a retry widget on `llm_attempts`,
and state that queue depth is not applicable.

---

## 5. Runnable API collection — ✅ Done

**Requirement.** Export a runnable Postman/Bruno collection covering the
core agent endpoints; graders can run any workflow without reading source.

**How it is done.**

- Bruno collection at [`clinical_copilot/api-collection/`](api-collection/):
  16 ordered requests (`01-health` … `16-restricted-brief-refused`), 35
  assertions, `collection.bru`, `bruno.json`, and two environments
  (`environments/local.bru` for the dev stack at `localhost:8300`,
  `environments/vps.bru` for the deployed droplet).
- Covers every endpoint and every workflow in
  [`USING_CLINICAL_COPILOT.md`](USING_CLINICAL_COPILOT.md): health, ready,
  login, open chart (captures CSRF and `pid` from the session), brief, ask,
  ask-for-arithmetic (withheld), ask-out-of-window (`not_in_facts`),
  ask-with-stale-hash (`chart_changed`), brief-again (cache hit, zero
  tokens), brief-without-CSRF (403), and a full restricted-user session
  ending in the ACL 403.
- Self-contained: requests 03–05 capture the session cookie and CSRF token
  into variables so nothing is edited between runs.
- Runnable headless: `npx --yes @usebruno/cli@2 run --disable-cookies --env local`
  ([README](api-collection/README.md)). Last recorded run: 16/16 requests,
  35/35 assertions, ~10 s locally; 01–02 also verified against the VPS.

---

## 6. Separate `/health` and `/ready` — ✅ Done

**Requirement.** `/health` = process alive; `/ready` = dependencies
reachable, and it must really check OpenEMR, the LLM provider, and the
observability backend.

**How it is done.**

- [`public/health.php`](../interface/modules/custom_modules/oe-module-clinical-copilot/public/health.php):
  liveness only. Does not load OpenEMR globals; returns
  `{"status":"ok","service":"clinical-copilot","time":…}` with
  `Cache-Control: no-store`.
- [`public/ready.php`](../interface/modules/custom_modules/oe-module-clinical-copilot/public/ready.php)
  → [`Ops/ReadinessProbes::readiness()`](../interface/modules/custom_modules/oe-module-clinical-copilot/src/Ops/ReadinessProbes.php),
  three real probes, each bounded to 2 s (`connect_timeout` 1 s):
  - **database (OpenEMR):** `QueryUtils::querySingleRow('SELECT 1')`;
    fails on exception or empty row.
  - **openai:** authenticated `GET https://api.openai.com/v1/models`;
    non-200 or transport error → not ready. The reason is deliberately
    coarse (`openai unavailable`) so an anonymous caller cannot learn key
    validity or quota state from the status.
  - **langfuse:** `GET <LANGFUSE_HOST>/api/public/health`; failure marks
    the service `degraded` (HTTP 200) rather than not ready, because
    observability must never block clinical requests.
- [`Ops/Readiness.php`](../interface/modules/custom_modules/oe-module-clinical-copilot/src/Ops/Readiness.php)
  + [`ReadinessReport`](../interface/modules/custom_modules/oe-module-clinical-copilot/src/Ops/ReadinessReport.php):
  HTTP 503 if any required dependency fails, 200 `ready` or `degraded`
  otherwise. Body lists each dependency with `ok` or its failure reason,
  `degraded`, `checked_at`, `age_seconds`, `from_cache`.
- Results are cached 60 s in the site directory
  (`FileReadinessStore`) so the unauthenticated endpoint cannot be used to
  spend OpenAI quota. Unit tests: `ReadinessTest` (6 tests: not-ready on
  required failure, degraded on optional failure, cache hit/age, etc.).
- Both are exercised by collection requests 01 and 02 and by
  `tests/evals/smoke.php` before the UI checks.

---

## 7. Dashboard and alert definitions — ✅ Defined, receiver built (Langfuse rules pending)

**Requirement.** At least three alerts on the dashboard: p95 latency
threshold, error rate threshold, tool failure rate; each documented with
meaning and on-call response.

**How it is done.**

- [`ALERTS.md`](ALERTS.md) defines the three paging alerts, each with the
  exact trace fields, aggregation, window, minimum sample, threshold,
  severity, baseline, what it means, and a numbered on-call runbook:

  | Alert | Metric | Threshold |
  |---|---|---|
  | p95 latency | p95 of trace `duration_ms` | > 15 s over 15 min |
  | Error rate | traces with `http_status ≥ 500` or non-null `status` ÷ traces | > 5 % over 15 min |
  | Tool failure rate | spans with `level = ERROR` ÷ spans | > 2 % over 15 min, with a per-span table saying which tool failing means what |

  Two further signals (verification pass rate, refusal rate) are documented
  as watched-not-paged with the reason.
- **Webhook receiver.** Firings are POSTed to
  [`public/alerts.php`](../interface/modules/custom_modules/oe-module-clinical-copilot/public/alerts.php),
  which verifies Langfuse's signed `x-langfuse-signature` header
  (HMAC-SHA256 with `LANGFUSE_WEBHOOK_SECRET`, 5-minute replay window;
  tampered or stale → 401) or, as a fallback, a shared `X-Alert-Token`
  equal to `ALERT_WEBHOOK_SECRET` (503 until either is configured, 400
  non-object body, 413 > 64 KiB, 405 non-POST), parses the payload tolerantly
  ([`Ops/AlertReceiver`](../interface/modules/custom_modules/oe-module-clinical-copilot/src/Ops/AlertReceiver.php),
  [`Ops/AlertEvent`](../interface/modules/custom_modules/oe-module-clinical-copilot/src/Ops/AlertEvent.php)),
  and records the firing as a WARNING `copilot alert received` in the app
  log and a `clinical-copilot-alert` row in the OpenEMR audit log — both
  with a fresh correlation id, and never with raw payload values (only the
  lifted fields and the remaining key names). Wired into
  `docker/vps/docker-compose.yml` and `.env.example`. Unit tests:
  `AlertReceiverTest` (13, including valid / tampered / stale / wrong-secret signatures). Contract:
  `contracts/alerts.response.schema.json`. Collection requests 17 and 18.
  Verified live on the local stack: 401 / 400 / 200, audit row and log line
  present.
- `KEY_METRICS.md` and `ARCHITECTURE.md` now point at `ALERTS.md` instead
  of claiming the alerts are documented elsewhere.

**Still to do.** Create the three rules in the Langfuse project (metric,
window, threshold and the webhook URL + header from `ALERTS.md`), send a
test notification for each, and capture a screenshot/export of the rules
into `clinical_copilot/dashboard/`. The deployed receiver needs
`ALERT_WEBHOOK_SECRET` set in the droplet's `.env` and the container
recreated.

## 8. Baseline CPU, memory, latency, and throughput profiles — ✅ Done

**Requirement.** Capture CPU, memory, request latency, and throughput under
the load-test scenarios and include them so future changes can be measured.

**What is built** (2026-09-16, [`tests/load/`](../tests/load/README.md)):

- [`sample-stats.sh`](../tests/load/sample-stats.sh) samples `docker stats`
  (CPU %, memory used/limit) for the app and database containers plus host
  `load1` every 2 s as CSV. Read-only; the runner streams it back over ssh
  from the droplet so nothing is written there.
- [`run-baselines.sh`](../tests/load/run-baselines.sh) runs every load
  level with the sampler attached and a 30 s quiet gap between runs.
- [`summarise.py`](../tests/load/summarise.py) produces two tables per run:
  latency/throughput/error rates per level and scenario, and app/DB CPU
  avg/peak, memory avg/peak and host load peak per level.
- Verified end to end on the local dev stack (3 VUs, 20 s): both tables
  render from real data.

**Captured 2026-09-17** against the droplet (2 vCPU / 4 GB, commit
`135c8cf`): [`BASELINES.md`](BASELINES.md) records, per level and
scenario, throughput, p50/p95/p99 per endpoint, error rates, app and DB
CPU avg/peak, memory avg/peak and host load, with commit, host spec,
model, cache state and the UTC window. Key figures: cache-hit briefing
0.6 s p50 / 0.85 s p95 at 10 users; follow-up 1.4 s / 2.6 s; app ≈ 1 core,
DB ≈ 0.9 core, load1 ≈ 9 at 10 users and ≈ 47 at 50; throughput plateau
≈ 3 req/s. Raw data in `tests/load/results/20260917T0245Z-*`.

---

## 9. Load/stress tests at 10 and 50 concurrent users — ✅ Done

**Requirement.** Load tests simulating at least 10 and 50 concurrent users
against the deployed agent; record p50/p95/p99 latency and error rate at
each level.

**What is built.** [`tests/load/copilot.js`](../tests/load/copilot.js), a
k6 script in which each virtual user is a physician session: real OpenEMR
login (cookie kept across iterations), chart open via
`demographics.php?set_pid=` (sets the session patient and yields the
panel's CSRF token, exactly as the Bruno collection does), then `brief`
and, per scenario, `ask`. Three scenarios: `brief` (deterministic path,
cache-hit after the first pass), `ask` (one real model call per
iteration), `mixed` (30 % follow-ups — the clinic pattern). Per-endpoint
p50/p95/p99/max trends split cache-hit vs cold briefings; rates for HTTP
failures, Co-Pilot contract errors, `summary_unavailable`
(model-unavailable 200s) and `verification_fail`; counters for model calls
and sentences kept/stripped. Response bodies are parsed for status fields
only and never written out. Summaries go to `tests/load/results/<label>.{json,txt}`.

Writing it found two k6 traps worth recording: k6 exposes the host
environment as `__ENV` (so `USER` was the shell user — variables are now
`LOGIN_USER`/`LOGIN_PASS`), and k6 clears each VU's cookie jar per
iteration unless `noCookiesReset: true`.

**Run 2026-09-17**, six runs of 2 minutes (10 and 50 VUs × brief / mixed
/ ask) against the deployed droplet with the real model at both levels;
1 097 Co-Pilot requests, 374 real model calls. p50/p95/p99 and error
rates per level are in [`BASELINES.md`](BASELINES.md). Findings: the
module's endpoints returned 0 % errors and 0 stripped sentences in every
run once sessions existed; the 50-user login burst exposed a deployment
limit (MariaDB `max_connections = 151` reached; 22 % HTTP errors in that
one run, root-caused from the server logs and recorded with a fix
recommendation); the provider never rate-limited (2 retries in 374
calls).

---

## Deploy state

Droplet recreated 2026-09-17 on commit `135c8cf` with `ALERT_WEBHOOK_SECRET`
set; `/ready` reports all three dependencies ok; the alert receiver
verified live (401 wrong token, 200 correct). A further recreate is needed
to add `LANGFUSE_WEBHOOK_SECRET` (signed-webhook verification, commit
`135c8cf`) — see item 7.

## Open questions before completing items 4, 7, 8, 9

- **Q1 (item 2).** Decided 2026-09-16: both. Done.
- **Q2 (item 3).** Decided 2026-09-16: JSON Schema files loaded by PHP at
  runtime. Done.
- **Q3 (item 4).** Answered 2026-09-16: a Langfuse dashboard exists.
  Still needed from you: an export or screenshots of its widgets (to
  document under `clinical_copilot/dashboard/`), and confirmation that
  "queue depth" may be documented as not applicable (synchronous PHP,
  no queue).
- **Q4 (item 7).** Decided 2026-09-16: Langfuse alerts → webhook to the
  module's own receiver. Receiver built; rules to be created in Langfuse.
- **Q5–Q7 (items 8, 9).** Decided 2026-09-16: k6; real model at both
  levels; CPU/memory sampled on the droplet over ssh (`ssh do-openemr`).
