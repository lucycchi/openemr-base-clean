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
| 2 | Correlation ID on every log entry, tool call, LLM interaction | ✅ Done, one gap noted |
| 3 | Canonical API/event/schema contracts as source of truth | ✅ Done |
| 4 | Real-time dashboard (requests, errors, p50/p95, tool calls, retries, verification rate) | ⚠️ Partial |
| 5 | Runnable API collection (Bruno) | ✅ Done |
| 6 | Separate `/health` and `/ready` with real dependency checks | ✅ Done |
| 7 | At least three alerts (p95 latency, error rate, tool failure rate) | ⚠️ Partial |
| 8 | Baseline CPU, memory, latency, throughput profiles | ❌ Not done |
| 9 | Load/stress tests at 10 and 50 concurrent users | ❌ Not done |

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

## 2. Correlation ID across service boundaries — ✅ Done (one gap)

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

**Gap.** The id is not attached to the outbound OpenAI HTTP request itself
([`OpenAiClient::complete()`](../interface/modules/custom_modules/oe-module-clinical-copilot/src/Llm/OpenAiClient.php#L49)
receives only `$system, $user, $schemaName, $schema`). The LLM interaction
is correlated on *our* side (Langfuse generation, `llm.*` step, log line)
but OpenAI's own request logs cannot be joined to ours. Fix is small: pass
the id into `complete()` and send it as the OpenAI `user` field or an
`X-Correlation-Id`/`X-Request-Id` header. See open question Q1.

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
- **Retry count is not emitted.** `OpenAiClient` retries once on 429/5xx
  ([`OpenAiClient.php:90-92`](../interface/modules/custom_modules/oe-module-clinical-copilot/src/Llm/OpenAiClient.php#L90))
  but the attempt count is a local variable; `LlmCompletion` carries only
  `data`, `promptTokens`, `completionTokens`. No trace, log line or audit row
  records whether a retry happened.
- **Queue depth** is not applicable in the literal sense (synchronous PHP
  request/response, no queue). The doc should say so explicitly and offer
  the nearest analogue (concurrent in-flight requests, or Apache worker
  utilisation from the load test).
- Langfuse Cloud's v3 ingestion API delays data by ~10 minutes
  ([`TODOS.md:45`](../TODOS.md#L45)); "real time" is therefore
  approximate until the OTel/v4 transport is adopted.

**Proposed completion.** Surface `attempts` from `OpenAiClient` through
`LlmCompletion` → `NarrationPipeline` → trace metadata (`llm_attempts`,
`llm_retried`) and the log line; build the dashboard in Langfuse with one
widget per row of the table above; export/screenshot it into
`clinical_copilot/dashboard/` with a short README naming each widget and the
trace field it reads.

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

## 7. Dashboard and alert definitions — ⚠️ Partial

**Requirement.** At least three alerts on the dashboard: p95 latency
threshold, error rate threshold, tool failure rate; each documented with
meaning and on-call response.

**What exists.** [`KEY_METRICS.md`](KEY_METRICS.md) documents alert
thresholds and on-call responses for:

| Alert in KEY_METRICS.md | Threshold | On-call response |
|---|---|---|
| Grounding failure (strip) rate | > 10 % rolling 1 h, or any `total_failure` | Compare `Prompt::VERSION`/model vs last good; re-run live evals; pin prior model |
| Omission-guard append rate | > 25 % over a day | Prompt-quality review |
| **Summary p95 latency** | > 15 s over 15 min (facts p95 > 2 s) | Check `/ready`, OpenAI status, retry rate in traces; DB health for facts |
| Refused request returning facts | any (P0) | Audit-log review by user |

So the **p95 latency alert is defined and documented.**

**What is missing.**

- **Error-rate alert** and **tool-failure-rate alert** are not in
  `KEY_METRICS.md`. `ARCHITECTURE.md § Alerts` claims all three are
  "defined on the trace fields in Langfuse and documented … in
  KEY_METRICS.md", but the doc only has the latency one; the other two exist
  as a sentence, not as threshold + meaning + response.
- No evidence the alerts are configured in Langfuse (or anywhere that pages
  someone). The repo has no alert export, notification channel, or
  screenshot. See open question Q4.

**Proposed completion.** Add an "Alerts" section (or a separate
`ALERTS.md`) with, for each of the three required alerts: the exact metric
expression over trace fields (`http_status >= 500 OR status != null` for
error rate; spans with `level = ERROR` ÷ spans for tool-failure rate;
`p95(duration_ms)` for latency), window, threshold, what it means, and the
on-call runbook; then configure them in Langfuse (or a Langfuse → webhook →
pager path) and record the configuration in the repo.

---

## 8. Baseline CPU, memory, latency, and throughput profiles — ❌ Not done

**Requirement.** Capture CPU, memory, request latency, and throughput under
the load-test scenarios and include them so future changes can be measured.

**What exists.** Latency only, and only from single-user eval runs: fact
assembly 6–55 ms; summary p50 ≈ 2.1 s, p95 ≈ 14.4 s cold, ~1 ms on cache
hit (`tests/evals/results.json`, `results-deployed.json`, `KEY_METRICS.md`
§ 3). No CPU, memory, or throughput (req/s) figures anywhere.
`ARCHITECTURE.md § Evaluation` lists load tests under "Deferred".

**What is needed.** A `clinical_copilot/BASELINES.md` (plus raw data under
`tests/load/results/`) recording, for each load level in item 9: container
CPU % and memory (e.g. `docker stats` sampled every second for the
`openemr` and `mysql` containers, or `/proc` on the droplet), request
throughput (req/s achieved), p50/p95/p99 latency, and error rate — with the
date, commit SHA, host spec, model, and whether the briefing cache was warm
or cold. Depends on item 9 and open question Q5.

---

## 9. Load/stress tests at 10 and 50 concurrent users — ❌ Not done

**Requirement.** Load tests simulating at least 10 and 50 concurrent users
against the deployed agent; record p50/p95/p99 latency and error rate at
each level.

**What exists.** Nothing. No k6/Locust/Artillery/ab scripts, no results.
The nearest thing is `tests/evals/smoke.php`, which is sequential.

**What is needed.**

- A load-test script under `tests/load/` that (a) logs in and opens a chart
  to obtain the session cookie and CSRF token — the same steps the Bruno
  collection performs in requests 03–05 — and (b) drives realistic
  scenarios against `chat.php`: warm-cache `brief` (the dominant real-world
  path), cold `brief` (forces an OpenAI call), and `ask` follow-ups, across
  several of the 30 seed patients.
- Runs at 10 and 50 virtual users against the deployed droplet, recording
  p50/p95/p99 and error rate per level and per scenario, saved to
  `tests/load/results/` and summarised in `BASELINES.md`.
- Decisions that need your input are in Q5–Q7 below.

---

## Open questions before completing items 2, 4, 7, 8, 9

- **Q1 (item 2).** Send the correlation id to OpenAI as the `user` field
  on the chat-completions request (visible in OpenAI's own logs and abuse
  reports), as a custom request header, or both?
- **Q2 (item 3).** Decided 2026-09-16: JSON Schema files loaded by PHP at
  runtime. Done.
- **Q3 (item 4).** Does a Langfuse dashboard already exist in the Cloud
  project for the droplet? If yes, I need an export or screenshots to
  document it. If no, should I build it in Langfuse (matching the existing
  tracer) or would you prefer a self-hosted alternative? Also confirm you
  are fine with "queue depth" being documented as not applicable.
- **Q4 (item 7).** Where should alerts actually fire — Langfuse's built-in
  alerting (if enabled on your plan), a webhook to email/Slack, or
  documentation-only for this submission? And are the thresholds in
  `KEY_METRICS.md` (p95 > 15 s / 15 min) the ones to keep?
- **Q5 (items 8, 9).** Which load tool: k6 (single binary, JS scenarios,
  built-in p50/p95/p99 — recommended), Locust (Python), or a PHP script
  using Guzzle's concurrent pool so no new toolchain is needed?
- **Q6 (item 9).** Cold-briefing scenarios at 50 concurrent users will
  make up to 50 real OpenAI calls per iteration (≈ $0.0007 each, so a
  5-minute run is a few dollars) and may hit the per-minute rate limit,
  which is itself a useful finding. Is real-model load acceptable, or
  should the 50-user level run warm-cache only with cold runs capped at 10
  users?
- **Q7 (items 8, 9).** Run against the deployed droplet (the requirement
  says "deployed agent") — can I have shell access to it, or a way to
  collect `docker stats` there, for the CPU/memory baselines? If not I will
  capture CPU/memory on the local dev stack and latency/throughput on the
  droplet, and label them accordingly.
