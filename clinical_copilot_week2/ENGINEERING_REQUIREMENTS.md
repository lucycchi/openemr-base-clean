# Engineering requirements, Week 2: how each one is met

The Week 1 brief grades nine engineering requirements alongside the core
submission ([Week 1 audit](../clinical_copilot_week1/ENGINEERING_REQUIREMENTS.md)).
This file re-audits them against the Week 2 code as each one is reviewed,
in the order the review happens. For every requirement: the requirement as
written, how the code meets it, the decisions taken and their trade-offs,
how to verify it in a few commands, and what is still open.

Paths are relative to the repository root; `<module>/` is
`interface/modules/custom_modules/oe-module-clinical-copilot/`.

| # | Requirement | Status | Audited |
|---|---|---|---|
| 1 | [Test design for boundaries, invariants, and regression](#1-test-design-for-boundaries-invariants-and-regression) | Done, enforced by the push gate | 2026-09-22 |
| 2 | [Correlation id across every service boundary](#2-correlation-id-across-every-service-boundary) | Done, enforced by the push gate (sidecar gap found and fixed during the audit) | 2026-09-22 |
| 3 | [Canonical contracts as the source of truth](#3-canonical-contracts-as-the-source-of-truth) | Done, enforced by the push gate (documents endpoint and PHP-side gaps found and fixed during the audit) | 2026-09-22 |
| 4 | [Dashboards](#4-dashboards-request-count-error-count-latency-queue-depth-event-retries-decision-outcomes) | Done for the Week 2 agent (worker spans, per-call generations, retries, five scores, the pre-warm queue); widgets defined, screenshots pending; OTLP migration deferred to Phase 9 | 2026-09-22 |
| 5 | [Runnable API collection](#5-runnable-api-collection) | Done: a Week 2 Bruno collection (17 requests, verified 17/17, repeatable) beside the Week 1 one; stale links repointed | 2026-09-22 |
| 6 | [Separate /health and /ready](#6-separate-health-and-ready-endpoints) | Done: the sidecar has its own /ready with five real checks; PHP's /ready probes it (degraded-only); proven by stopping the container | 2026-09-22 |
| 7 | [Dashboard and alert definitions](#7-dashboard-and-alert-definitions) | Done: the three alerts now cover the sidecar (`tool_ok`, `request_ok` semantics), an extraction-latency rule, four watched rules, a Week 2 runbook | 2026-09-22 |
| 8 | [Baseline profiles](#8-baseline-cpu-memory-latency-and-throughput-profiles) | Done on the Week 2 build: two recorded runs on the droplet, sidecar bucketed, extract scenario; the first run found and the second measured a sidecar concurrency defect | 2026-09-23 |
| 9 | [Load and stress tests](#9-load-and-stress-tests-at-10-and-50-concurrent-users) | Done: 10 and 50 users × four scenarios with p50/p95/p99 and error rates | 2026-09-23 |

---

## 1. Test design for boundaries, invariants, and regression

> Every evaluation case must exercise a boundary condition (missing data,
> malformed input, empty patient record), an invariant (claims must always
> cite a source), or a known regression risk. Happy-path-only test suites do
> not pass. Document the failure mode each test guards against.

### How it is met

**Every case declares what it guards and what would go wrong.** Each of the
52 files in [tests/evals/cases/](../tests/evals/cases/) carries two mandatory
fields:

```json
"guards": "boundary",
"failure_mode": "A lab row printed without a unit must anchor the value it does have and flag the missing unit, not invent a unit or drop the row."
```

`guards` must be exactly one of `invariant`, `boundary`, `regression`;
`failure_mode` must be a plain-English sentence of at least 40 characters.

**The rule is enforced, not advisory.** [tests/evals/case-index.php](../tests/evals/case-index.php)
`--check` runs inside the push gate ([tests/evals/gate.sh](../tests/evals/gate.sh))
and refuses the push if any case lacks either field, uses a fourth category,
or if the generated case table in [tests/evals/README.md](../tests/evals/README.md#cases-and-the-failure-mode-each-guards)
is stale. The same script regenerates that table, so the documented failure
modes cannot drift from the case files.

**The tally** (27 invariant, 18 boundary, 7 regression) and what each
category means in this system are in
[W2_ARCHITECTURE.md § Test design](W2_ARCHITECTURE.md#test-design-boundaries-invariants-regressions).
Representative cases:

| Category | Examples | Case ids |
|---|---|---|
| boundary | empty fact set; question outside the briefing window; off-corpus question; corrupt, encrypted, six-page and blank-scan PDFs; blank intake form; lab row without a unit; report without a collection date; patient with no prior visit | 05, 10, 32, 34, 39–42, 47–49, 50 |
| invariant | every kept sentence cites a source; a value anchors only in its own row and page; an invented value never anchors; a must-surface fact is never dropped; a guideline id cannot cite a patient claim; no identifier reaches a log line; the receptionist is refused | 01–04, 16–18, 21, 23–31, 33, 35–38, 43–44, 51–52 |
| regression | inline citation group (Week 1); one model call omitting 7 of 20 rows; OCR mangling a unit; guideline passage cited inline with an empty id list; year inside a passage heading; document facts hidden for a patient with no prior visit | 07, 19–20 (history), 45, 46, 50 |

**Layers the cases reach.** The harness ([tests/evals/run.php](../tests/evals/run.php))
has eleven modes so that a case can target the layer where a failure would
actually happen rather than only the end-to-end surface:

| Mode | Layer | Needs |
|---|---|---|
| briefing, followup | Week 1 Verifier + OmissionGuard on a recorded narration | nothing |
| anchor, absent, malformed | the sidecar's parser and row-level anchoring on a recorded model proposal | sidecar container |
| route | the real LangGraph graph with stubbed workers | sidecar container |
| retrieve | hybrid retrieval with a committed query embedding | sidecar container |
| answer | facts + guideline chunks + a recorded narration through the extended Verifier | nothing |
| facts | a recorded extraction persisted for a temporary patient and assembled by the real `FactAssembler` | database |
| extract, phi_logs, live followups | the real model, the real controllers with a capturing logger and tracer | `--live`, API key |

### Decisions and trade-offs

1. **Three categories only; authorization is a theme, not a category.**
   The brief names three. The six cases that guard access control and log
   hygiene are invariants ("an identifier never leaves the server") tagged
   `theme: authorization` so they can still be found as a group. *Trade-off:*
   a reader looking for an "authorization" bucket has to read the theme
   column; the gain is that `case-index.php --check` can enforce the exact
   vocabulary the brief uses.
2. **One category per case.** Some cases fit two (case 50 is both a
   boundary, a patient with no encounters, and a regression). The file
   records the reason the case exists; the other reading goes in
   `failure_mode`. *Trade-off:* the tally under-counts overlaps; the gain is
   a table a grader can scan without a legend.
3. **Every clean fixture has a hostile sibling.** For each fixture that can
   be extracted correctly there is a case that feeds a wrong or absent value
   through the same path and requires it to come back unverified (18, 43,
   44). Without this, a passing anchor case would not distinguish "anchored
   because it was on the page" from "anchored because the anchor step is
   permissive". *Trade-off:* two cases per fixture instead of one, inside a
   50-case budget.
4. **Cases that look like happy paths are kept only with a history.** The
   five-page report (19) is the case that exposed the model omitting 7 of
   20 rows; the scanned copy (20) exposed OCR unit mangling. Their
   `failure_mode` says so. *Trade-off:* a reader has to trust the recorded
   history; the alternative, deleting them, would lose the regression pins.
5. **Recorded proposals for the deterministic cases, the live model behind
   `--live`.** Deterministic cases replay a committed `*.model.json` so the
   gate runs in seconds without a key and gives the same verdict on every
   machine; the live cases re-run the model and compare with
   `baseline-live.json`. *Trade-off:* model drift is caught only when
   someone pushes with `COPILOT_GATE_LIVE=1`; the recorded proposal is a
   snapshot of one model on one day. The per-case baselines and the
   `--update-baseline` ritual are the mitigation.
6. **A `facts` mode instead of a Selenium assertion.** The bug behind case
   50 lived between "the sidecar returned JSON" and "the physician sees a
   cited fact", a layer neither the sidecar tests nor the recorded cases
   reach. The mode persists a recorded extraction for a temporary patient,
   assembles facts through the real code and deletes everything it wrote.
   *Trade-off:* it needs the database, so it cannot run on a host without
   the container; it stays out of the isolated PHPUnit suite and inside the
   gate's default (in-container) run. A DB-backed PHPUnit test covers the
   same rule at unit level ([DocumentIngestServiceTest.php](../tests/Tests/Services/Modules/ClinicalCopilot/DocumentIngestServiceTest.php)).
7. **A known limitation stays as a passing case.** Case 08 (semantic
   inversion the Verifier cannot detect) is marked `known_limitation` and
   passes by design so the limitation is documented in the same place as
   the guarantees. *Trade-off:* a grader may read "PASS" as "handled"; the
   label on the harness line and the failure_mode text say otherwise.
8. **Regression cases pin the fix, not the symptom.** Case 45 replays the
   exact model output that once stripped a guideline sentence; case 50 is
   proven by reverting the fix and watching `anchor_correct` fail.
   *Trade-off:* an intended behaviour change in that area needs a baseline
   update and a commit message that says why; that friction is the point.
9. **52 cases against a budget of 50.** The three `facts` cases were added
   after the budget was set because they close a real gap; nothing was
   removed to make room. The budget in [DESIGN.md](DESIGN.md#case-budget-exactly-50-plus-2-extension-cases-in-phase-10)
   is a floor for the brief, not a ceiling.

### Verify it

```bash
openemr-cmd e 'php tests/evals/case-index.php --check'   # every case has guards + failure_mode; table current
tests/evals/gate.sh pre-push                              # what the hook runs: pytest, PHPUnit, case check, golden cases
tests/evals/install-hooks.sh --self-test                  # installs the hook and proves it refuses a regression
```

A grader's regression walk-through, with the recorded transcript of the
hook refusing a push, is in
[tests/evals/README.md § How graders test the gate](../tests/evals/README.md#how-graders-test-the-gate).

### Still open

- Phase 4b's rating console will add human 1–5 ratings next to the rubric
  verdicts; the rubric set does not change.
- Thursday's remaining fixtures (Phase 9) add boundary cases from the
  vetted document sources in [DOCUMENT_SOURCES.md](DOCUMENT_SOURCES.md).

---

## 2. Correlation id across every service boundary

> Every request or event carries a correlation ID across service
> boundaries. Assign a unique correlation ID to every agent invocation. The
> ID must appear in every log entry, tool call, and LLM interaction related
> to that request so a full trace can be reconstructed from logs alone.

### How it is met

One id, generated once in PHP per request, travels through every boundary.
The table of boundaries, mechanisms and the test that guards each is in
[W2_ARCHITECTURE.md § Correlation id](W2_ARCHITECTURE.md#correlation-id-one-id-every-boundary-requirement-audit-2026-09-22).
In short:

| Where | Mechanism | Code |
|---|---|---|
| Generation | 16 random bytes, hex | [`<module>/src/CorrelationId.php`](../interface/modules/custom_modules/oe-module-clinical-copilot/src/CorrelationId.php) |
| Every PHP log entry | PSR-3 decorator adds `correlation_id` to every record | [`<module>/src/Ops/CorrelatedLogger.php`](../interface/modules/custom_modules/oe-module-clinical-copilot/src/Ops/CorrelatedLogger.php) |
| Every response, including errors | JSON body field + `X-Correlation-Id` header | `ChatController::respond`, `DocumentController::respond` |
| PHP → OpenAI | `user` field + `X-Correlation-Id` header | [`<module>/src/Llm/OpenAiClient.php`](../interface/modules/custom_modules/oe-module-clinical-copilot/src/Llm/OpenAiClient.php) |
| PHP → sidecar | required `correlation_id` in `run.request`, echoed in `run.response` / `run.error`; the same header | [`<module>/src/Documents/SidecarClient.php`](../interface/modules/custom_modules/oe-module-clinical-copilot/src/Documents/SidecarClient.php), [contracts/](../interface/modules/custom_modules/oe-module-clinical-copilot/contracts/README.md) |
| Inside the sidecar | middleware binds the header's id to a context variable; `/run` rebinds to the body's id; the JSON formatter writes it on every line | [`sidecar/copilot_sidecar/logging_setup.py`](../interface/modules/custom_modules/oe-module-clinical-copilot/sidecar/copilot_sidecar/logging_setup.py), [`app.py`](../interface/modules/custom_modules/oe-module-clinical-copilot/sidecar/copilot_sidecar/app.py) |
| Sidecar log events | `run`, one `handoff` per hop, one `model_call` per proposal / embedding / rerank, `retrieved`, `extracted` / `extract failed` | [`graph.py`](../interface/modules/custom_modules/oe-module-clinical-copilot/sidecar/copilot_sidecar/graph.py), [`llm.py`](../interface/modules/custom_modules/oe-module-clinical-copilot/sidecar/copilot_sidecar/llm.py), [`retrieve.py`](../interface/modules/custom_modules/oe-module-clinical-copilot/sidecar/copilot_sidecar/retrieve.py), [`extractor.py`](../interface/modules/custom_modules/oe-module-clinical-copilot/sidecar/copilot_sidecar/extractor.py) |
| Sidecar → OpenAI, → Cohere | `user` + header; header via `request_options` | `llm.correlation_options()`, `retrieve.rerank()` |
| Trace | the Langfuse trace id is the correlation id | [`<module>/src/Ops/LangfuseTracer.php`](../interface/modules/custom_modules/oe-module-clinical-copilot/src/Ops/LangfuseTracer.php) |
| Persistence and audit | `copilot_document.correlation_id`; `correlation_id=` in the OpenEMR audit line | `DocumentIngestService::persist`, the controllers' `audit()` |

**Reconstructing a request from logs alone.** With the id from a response
header or from the panel's error text:

```bash
ID=0bdd116c611d99659a22a19cae4b1dbb
openemr-cmd php-log | grep $ID                                        # PHP: request line with tokens, cost, steps, http status
cd docker/development-easy && docker compose logs copilot-sidecar | grep $ID   # sidecar: run, handoffs in order, model calls, outcome
```

The sidecar's lines for one extraction read, in order: `handoff
supervisor→intake_extractor`, `model_call` per page (model, tokens, ms),
`extracted` (pages, fields, anchored, confidence), `handoff
intake_extractor→supervisor`, `handoff supervisor→done`, `run` (hops, ms),
plus the access line. The same id opens the Langfuse trace and selects the
`copilot_document` row.

### Decisions and trade-offs

1. **PHP is the only origin of ids.** The sidecar never mints one for a
   `/run`; it echoes what it was given. A single origin means a single
   grep, and an id that appears in the sidecar log but not in PHP's is a
   bug by definition. *Trade-off:* the sidecar's test-only `/eval/*`
   endpoints, which have no PHP caller, mint their own `eval-*` ids.
2. **Random, not time-ordered.** `bin2hex(random_bytes(16))`, no timestamp
   or host component, so the id leaks nothing about when or where a chart
   was opened. *Trade-off:* ids do not sort by time; the log line's own
   timestamp does that.
3. **The body is the authority; the header is for early binding.** The
   contract (`run.request`) requires `correlation_id` in the body because
   contracts are the source of truth (requirement 3). The header exists so
   the sidecar can bind the id *before* parsing the body, which is what
   makes a 400/422 rejection line correlated. If the two ever differed the
   body would win. *Trade-off:* the id is sent twice; a few bytes.
4. **Context variable, not a function argument.** Week 2's first cut passed
   the id by hand into `extractor.extract()`; the audit found the lines that
   had not received it (every model call, all of retrieval, the run
   itself). Binding the id to a `contextvars.ContextVar` and injecting it in
   the formatter makes the guarantee structural, the same shape as PHP's
   `CorrelatedLogger`. *Trade-off:* the formatter reads the context at emit
   time, so a log line formatted later (a test capturing records and
   formatting them after the request) sees no id; the tests install a
   handler that formats at emit time for that reason. Starlette copies the
   context into the thread pool for sync endpoints, so `/eval/*` handlers
   see the binding too.
5. **The id goes to the model providers as both `user` and a header.**
   OpenAI's `user` field is the one they persist and show in their own
   abuse and usage views, so an incident on their side can be matched to
   ours; the header is what proxies and gateways log. Both PHP and the
   sidecar send both, so the two LLM paths are indistinguishable in a
   provider log. Cohere's SDK exposes only headers. *Trade-off:* the
   `user` field was designed for end-user abuse monitoring, not tracing; a
   per-request random value defeats that purpose, which is acceptable here
   because no end user is ever named to the provider (that would be PHI).
6. **Retries share the id.** PHP's OpenAI client retries once inside the
   request budget; the sidecar makes one call per page plus one targeted
   retry. All carry the same id; the attempt count lives in the step
   recorder and the `model_call` lines, not in the id. *Trade-off:* "which
   attempt" is a second field to read, not a second id to join on.
7. **Idempotent replays return the original response, id included.** The
   sidecar caches a `/run` response for ten minutes keyed by correlation id
   plus document hashes, so a PHP retry after a timeout gets the same
   extraction without a second model call and logs `run served from cache`
   under the same id. *Trade-off:* a deliberate re-extraction needs a new
   id (a new request), which is how the UI's "extract" button behaves.
8. **Enforced inside the PHI cases, not as an eighth rubric.** Log hygiene
   and log correlation are the same property of the same lines, so the
   three `phi_logs` cases (36–38) scan the sidecar's captured lines for
   identifiers *and* count any line missing the request's id
   (`uncorrelated_log_lines`); either fails `no_phi_in_logs` and the gate.
   *Trade-off:* the rubric name under-describes what it checks; the README
   row spells it out.
9. **Per-row ids in the pre-warm job.** A scheduled pre-warm run is not a
   user request; each patient row gets its own id and its own receipt so a
   physician's later chart open can be matched to the row that warmed it.
   *Trade-off:* one job produces many ids; the job's own id ties them in
   the log.

### Verify it

```bash
cd docker/development-easy && docker compose exec -T copilot-sidecar python -m pytest -q tests/test_app.py tests/test_logging.py
openemr-cmd e 'php tests/evals/run.php --live'          # cases 36-38 report uncorrelated_log_lines: 0
```

For a live proof, upload and extract a fixture in the panel, copy the
`X-Correlation-Id` response header from the browser's network tab, and run
the two `grep` commands above.

### What the audit found and fixed (2026-09-22)

Week 1's PHP path already met the requirement in full. The Week 2 sidecar
had regressed it: its OpenAI, embedding and rerank calls carried no id;
only `extracted` / `extract failed` log lines had it; answer-mode retrieval
logged nothing; and the id was a function argument any new line could drop.
Commit `65c11d0` closed all four, and cases 36–38 now refuse a push if a
sidecar line loses the id again. Not yet on the droplet; it ships with the
Phase 8 deploy.

---

## 3. Canonical contracts as the source of truth

> Produce canonical API/event/schema contracts from cleaned requirements.
> Define strict schemas (Pydantic, Zod, or equivalent) for every tool input
> and output. Contracts must be the source of truth, not the implementation.

### How it is met

**One contract per boundary, written by hand, before the code.** 27 JSON
Schema (draft 2020-12) files in [`<module>/contracts/`](../interface/modules/custom_modules/oe-module-clinical-copilot/contracts/README.md),
each with a stable `$id`, a description that states the HTTP status codes
and authentication for its endpoint, `additionalProperties: false`, and
closed enums for every code that is logged. DESIGN task 1.2 wrote the Week
2 schemas from the brief's requirements before any fixture or code existed.

| Boundary | Contract(s) | Strict schema in code |
|---|---|---|
| browser → `chat.php` | `chat.request` | `ChatRequest` (typed parse at the boundary) |
| browser → `documents.php` | `documents.request` | `DocumentRequest` |
| `chat.php` → browser | `chat.briefing/answer/chart-changed/error.response`, `fact`, `sentence` | `PanelPayload` |
| `documents.php` → browser | `documents.list/upload/extract/error.response` | `DocumentController` payloads |
| PHP → OpenAI | `llm.briefing.output`, `llm.followup.output` | the contract file itself is the `response_format` |
| PHP → sidecar | `run.request` | `SidecarClient` |
| sidecar → PHP | `run.response`, `run.error`, `lab-report`, `intake-form`, `citation`, `handoff` | Pydantic `extra="forbid"` models on the sidecar; typed value objects on PHP |
| sidecar → OpenAI | `llm.lab-proposal.output`, `llm.intake-proposal.output` | the contract file is the `response_format`; `LabReportProposal` / `IntakeFormProposal` validate the reply |
| operators | `health/ready/prewarm/alerts.response` | `ReadinessReport`, `PrewarmStatusPayload` |

**The contract, not the implementation, decides.** Three mechanisms make
that literal rather than aspirational:

1. *At runtime:* `SidecarClient` validates every sidecar reply against
   `run.response` before anything is parsed or persisted
   ([Contracts::violations](../interface/modules/custom_modules/oe-module-clinical-copilot/src/Contracts.php)),
   and both LLM callers send the contract file to the provider as a strict
   `response_format`, so the model is held to the file too
   ([sidecar/copilot_sidecar/contracts.py](../interface/modules/custom_modules/oe-module-clinical-copilot/sidecar/copilot_sidecar/contracts.py)).
2. *In the tests:* every contract has an examples file,
   [`contracts/examples/<name>.examples.json`](../interface/modules/custom_modules/oe-module-clinical-copilot/contracts/examples/),
   with documents it must accept and reject. The sidecar's
   `test_contracts.py` runs them against the JSON Schema and the Pydantic
   model; PHP's `ContractExamplesTest` runs the same files against the
   JSON Schema and the typed parsers; `ChatRequestTest` and
   `DocumentRequestTest` require the parser and the schema to give the same
   verdict on every request body. Any drift on either side fails the gate.
3. *In the eval gate:* the `schema_valid` rubric validates real outputs
   (extractions, retrieval chunks, verified narration, and, since this
   audit, the real `documents.php` responses in PHI cases 36-38) against the
   contract files.

### Decisions and trade-offs

1. **Hand-written JSON Schema, not a Pydantic or PHP export.** An exported
   schema is by definition derived from the implementation, which is the
   inversion the requirement forbids; a hand-written file can be reviewed
   against the brief and read by someone with neither language. *Trade-off:*
   two artefacts to keep in step (file and model) instead of one. The shared
   examples are how they are kept in step.
2. **Behavioural equivalence, not textual equality.** Pydantic's export and
   a hand-written draft 2020-12 document never match byte for byte, and a
   PHP value object has no schema to export at all. So conformance is
   proven by behaviour: the same documents accepted and rejected by the
   file, the Python model and the PHP parser. *Trade-off:* the proof is
   only as good as the examples; the rule is that every rejected example
   breaks exactly one rule, so a missing rule is one line to add.
3. **Validate, then parse, on the PHP side.** PHP's typed parsers follow
   "parse, don't validate" and are deliberately tolerant (they ignore keys
   they do not use). That tolerance is a liability at a service boundary,
   so the contract runs first as a gate and the parser second as
   extraction. *Trade-off:* one JSON-Schema validation per sidecar call, a
   few milliseconds against a call that takes seconds.
4. **The model's proposal is a contract too.** The sidecar first built the
   OpenAI `response_format` by exporting the Pydantic proposal model; Week 1
   had done it contract-first. The audit made the sidecar match: the
   proposal contracts are files, loaded at runtime, and the Pydantic models
   validate the reply. *Trade-off:* keywords OpenAI's strict mode refuses
   (`minLength`, `minimum`, `pattern`) are stripped before sending and
   enforced on the reply instead; the file states the full rule, the
   provider sees a subset.
5. **One dialect both validators enforce.** The PHP validator
   (justinrainbow/json-schema 6) accepts documents that `if`/`then` would
   reject; the audit's examples exposed this on the citation contract (an
   anchored document citation without a bounding box passed PHP). The rule
   was rewritten as `anyOf`/`not`, which both validators enforce, and the
   README now names the constructs to use. *Trade-off:* slightly less
   readable schemas than `if`/`then`; the description on each branch says
   what it means.
6. **Enums, not free strings, for anything logged.** Handoff reasons,
   failure reasons, error reasons, document status and type are closed sets
   in the contract, mirrored by PHP enums and Python `Literal`s, and
   asserted equal where the drift risk is highest (`fact.category` =
   `FactCategory::cases()`). The handoff enum already names `critic`, the
   Phase 10 worker, so adding it later is a code change, not a contract
   change. *Trade-off:* a new reason code is a contract change first; that
   is the point.
7. **Multipart files are described, not schematised.** `documents.request`
   covers the form fields; the PDF part is stated in the description and
   enforced by the parser (`DocumentRequestTest` proves upload without a
   file is refused even when the fields conform). *Trade-off:* one rule
   lives outside the schema, named in the schema.
8. **No per-contract version field.** Contracts change in git with the code
   that consumes them; a change to what a model is asked to return bumps
   the prompt version so cached results are invalidated, and a change that
   the browser must see ships in the same deploy (the panel is served by
   the same module). A breaking change to a boundary that had independent
   consumers would be a new file name. *Trade-off:* no runtime negotiation;
   acceptable while both sides deploy together.
9. **Test-only endpoints stay in code.** The sidecar's `/eval/*` models are
   inline Pydantic and have no contract file: they exist only under
   `COPILOT_EVAL_ENDPOINTS=1`, are never deployed, and their consumer is the
   harness in this repository. *Trade-off:* a grader looking for them in
   `contracts/` finds a sentence, not a file.

### Verify it

```bash
openemr-cmd e 'php vendor/bin/phpunit -c phpunit-isolated.xml --filter "Contract|DocumentRequest|ChatRequest|SidecarClient"'
cd docker/development-easy && docker compose exec -T copilot-sidecar python -m pytest -q tests/test_contracts.py
openemr-cmd e 'php tests/evals/run.php --live'    # cases 36-38: schema_valid on the real documents.php responses
```

To see the gate work: add `"debug": 1` to an accepted document in
`contracts/examples/run.response.examples.json` and run either suite; or
add a reason code to `graph.py` without adding it to `handoff.schema.json`
and watch PHP refuse the reply as `schema_mismatch`.

### What the audit found and fixed (2026-09-22)

- `documents.php` had no contract although `DocumentRequest` cited one by
  name; its four response shapes were untyped. Five contracts added, with
  examples, a request test and harness validation of the real responses.
- PHP parsed sidecar replies without validating them, so a reply the
  contract rejects (unknown key, reason outside the enum) was accepted; the
  README claimed otherwise. Runtime validation added and tested.
- The sidecar built the model's `response_format` from a Pydantic export.
  Two proposal contracts added and loaded at runtime instead.
- The examples exposed two latent drifts the same day: the Pydantic
  proposal models accepted empty strings and page 0 that the contracts
  forbid (fixed at the source with `Field` constraints), and the PHP
  validator did not enforce the citation contract's `if`/`then` (rewritten
  as `anyOf`).
- **Found by the Phase 8 deploy (2026-09-23):** the validator library
  (`justinrainbow/json-schema`) was a dev-only dependency, and the
  production image installs without dev packages, so the runtime gate
  threw `Class "JsonSchema\Validator" not found` on every sidecar reply:
  every extraction and every question answered 500 while `/ready` said
  `ready`. Two fixes, both at the source: the package is a runtime
  dependency (`composer.json` `require`, lock updated), and `/ready` now
  has a required `contracts` probe (validator class present, contract
  files load) so a build missing either reports `not_ready`. The deploy's
  own health check and the Week 2 API collection against the droplet are
  what caught it; the dev stack could not, because it installs dev
  packages. Lesson recorded in the risks table of W2_ARCHITECTURE.md.

---

## 4. Dashboards: request count, error count, latency, queue depth, event retries, decision outcomes

> Build a dashboard (LangSmith, Langfuse, Braintrust, or equivalent) that
> shows in real time: total requests, error rate, p50/p95 latency, tool
> call counts, retry counts, and verification pass/fail rate. This is the
> minimum — add metrics that matter for your specific agent design.

### How it is met

The dashboard is Langfuse Cloud, fed by [`<module>/src/Ops/LangfuseTracer.php`](../interface/modules/custom_modules/oe-module-clinical-copilot/src/Ops/LangfuseTracer.php)
on every request. Week 1 established the pattern (one trace per request,
one span per tool step, a generation per model call, boolean scores for the
rates, three alerts) and documented every widget field-by-field in
[Week 1 DASHBOARD.md](../clinical_copilot_week1/DASHBOARD.md). Week 2's
agent design added things that pattern did not see, and this audit made
them visible. The Week 2 record is [DASHBOARD.md](DASHBOARD.md).

| Required metric | Where it comes from | Week 2 change |
|---|---|---|
| Total requests | one trace per request; names `copilot.brief`, `copilot.ask`, `copilot.documents.extract`, `copilot.documents.upload`, `copilot.prewarm` | two document traces and the sweep trace added |
| Error rate | `request_ok` score; `http_status` and `status` in metadata | extraction and sweep traces carry it too |
| p50 / p95 latency | trace `duration_ms`; per-step spans | worker spans give the sidecar's own latency inside the PHP step |
| Tool call counts | spans per tool step | **`sidecar.intake_extractor` and `sidecar.evidence_retriever` are spans now** (derived from the supervisor's handoff log), so the workers count as tools and a `worker_failed` hop is an ERROR span |
| Retry counts | `llm_attempts` / `llm_retried` (PHP client) | **`sidecar_retries`** from a new required `retries` field in `run.response` (the sidecar's omission-driven re-ask) |
| Verification pass/fail | `verification_pass` score (narration) | **`extraction_verified`** score for documents: extracted with nothing unverified and nothing unextracted |
| Queue depth | Week 1: not applicable | **the pre-warm sweep is traced** (`copilot.prewarm`: `scheduled`, `warmed`, `already_cached`, `skipped`, `errored`, `queue_depth_after`, throughput) with a `prewarm_ok` score |
| Decision outcomes | `answer_type`, `chart_changed`, `from_cache`, `denied` | plus `doc_type`, `status`, `failure_reason`, `confidence`, `unverified`, `unextracted`, `guideline_chunks`, `reranked`, and the full `handoffs` list; **`routing_ok`** and **`retrieval_hit`** scores |
| Tokens and cost | one generation per PHP model call | **one generation per sidecar call** (`sidecar.chat` per page, `sidecar.embedding`, `sidecar.rerank`) with cost; embedding and rerank list prices added to `Pricing`; trace `cost_usd` is the sum |

Agent-specific metrics beyond the minimum (all defined widget-by-widget
in DASHBOARD.md): extraction success rate, fully-verified share,
unverified/unextracted per document, extraction confidence by document
type, routing outcomes, retrieval hit rate, rerank share, sidecar model
calls/tokens/cost by kind and model, pre-warm queue size and throughput.

### Decisions and trade-offs

1. **One emitter.** The sidecar never talks to Langfuse; PHP derives worker
   spans and per-call generations from what the sidecar returns
   (`handoffs`, `usage`). One set of keys, one PHI allowlist, one place
   to change when the ingestion API moves. *Trade-off:* worker span times
   are reconstructed from hop durations, anchored on the PHP step that made
   the call, accurate to the millisecond counts the sidecar measured but
   not to its wall clock.
2. **No double counting.** An extraction trace has no aggregate generation;
   its model calls are the `sidecar.chat` generations. The chat path keeps
   the aggregate for the PHP call and adds the sidecar's embedding/rerank
   beside it. *Trade-off:* a widget summing generations across all trace
   names is still correct; one that expected exactly one generation per
   trace is not.
3. **Rates are scores.** Langfuse charts a boolean score's average as a
   rate and can alert on it; metadata can only be filtered. Five Week 2
   scores follow the Week 1 three, each emitted only on traces where the
   outcome was decided so one kind of request never dilutes another's
   rate. *Trade-off:* more events per trace.
4. **Retries are a contract field, not an inference.** `run.response`
   extractions gained `retries` (contract, Pydantic, shared examples, PHP
   parser, `ContractsTest`, `test_contracts.py`). Inferring it from
   `model_calls − pages` would break silently when the call pattern
   changes. *Trade-off:* transport-level retries inside the OpenAI client
   library are not visible to the sidecar's code and are not counted; the
   contract says so.
5. **The sweep is the queue, and the trace is per sweep.** Per-patient
   detail stays in the receipt table (it is patient-scoped); the trace
   carries the counts and the total duration, which is what a queue widget
   needs. *Trade-off:* per-patient warm latency is not in Langfuse.
6. **Widgets are built in the UI; there is no dashboards API.** Checked
   (`GET /api/public/dashboards` is a 404); the definitions in DASHBOARD.md
   are the reproducible artefact, screenshots are the evidence.
7. **The ingestion path stays on v3 this week.** Langfuse Cloud delays it by
   minutes and shuts it down for non-score events on 2026-11-16; the OTLP
   migration is a Phase 9 item (`TODOS.md`). "Real time" therefore means
   "within about ten minutes" until then. *Trade-off:* deliberate; the
   night before the Wednesday gate is the wrong time for a transport
   rewrite with its own failure modes.

### Verify it

```bash
openemr-cmd e 'php vendor/bin/phpunit -c phpunit-isolated.xml --filter "LangfuseTracerTest|PrewarmCommandTest|ContractsTest|ContractExamplesTest"'
openemr-cmd e 'php tests/evals/smoke.php http://openemr 2'    # real traces; then read them back with the project's keys
```

Read-back after the smoke run of 2026-09-22 (v2 observations API, the
window covering one upload + extract of the five-page synthetic lab report
and one guideline question):

| Observation | Count | Note |
|---|---|---|
| SPAN `copilot.documents.extract` → `sidecar_extract_and_persist` → **`sidecar.intake_extractor`** | 1 each | the worker span reports 11.2 s, inside the PHP step |
| GENERATION **`sidecar.chat`** | 5 | one per page; no re-ask this run (`sidecar_retries` 0) |
| SPAN `copilot.ask` → `retrieve_evidence` → **`sidecar.evidence_retriever`** | 1 each | the worker span reports 1.0 s |
| GENERATION **`sidecar.embedding`**, **`sidecar.rerank`**, `copilot.ask.llm` | 1 each | the query embedding, the Cohere rerank (a key was configured), the PHP answer call |
| Scores `extraction_ok`, `extraction_verified`, `routing_ok` ×2, `retrieval_hit` | all `true` | visible within a minute; the spans and generations took about 25 minutes to appear (v3 ingestion delay) |

The list projection of the v2 API omits model, usage and metadata; those
are on the observation detail and in the UI.

### What the audit found and fixed (2026-09-22)

- The sidecar's workers were not tool calls in the dashboard (handoffs
  were a metadata blob): worker spans added.
- Sidecar model calls were collapsed into one generation; embedding and
  rerank were neither traced nor costed: one generation per call, list
  prices added, trace cost is the sum.
- Sidecar retries were unobservable: `retries` added to the contract and
  surfaced as `sidecar_retries`.
- No Week 2 outcome was a chartable rate: five scores added.
- The pre-warm queue sent nothing: one trace per sweep with the queue
  numbers and a `prewarm_ok` score.
- Documented as DASHBOARD.md with every widget's definition. Not yet on
  the droplet; ships with the Phase 8 deploy.

---

## 5. Runnable API collection

> Commonly used API calls in Postman, Bruno, or equivalent runnable API
> collection. Export a runnable API collection covering the core agent
> endpoints. Graders must be able to run any workflow from this collection
> without reading source code.

### How it is met

Two Bruno collections, one per week, each runnable in the desktop app or
with `npx @usebruno/cli@2` against the dev stack (`local`) or the droplet
(`vps`), with no manual edits between requests: each collection logs in
through OpenEMR's real login, captures the session cookie and the panel's
CSRF token itself, and carries the values later requests need (`document_id`,
`facts_hash`) in variables.

| Collection | Covers | Verified |
|---|---|---|
| [Week 1](../clinical_copilot_week1/api-collection/README.md), 19 requests | health, ready, login, chart open, brief, follow-ups (cited, arithmetic withheld, out-of-window, stale hash), cache hit, CSRF refusal, restricted-user refusal, alert webhook (valid, wrong token, token in query) | 18/18 requests, 42/42 assertions (Week 1) |
| [Week 2](api-collection/README.md), 17 requests | attach a lab PDF → extract through the sidecar graph → list → brief with document-cited facts → guideline-evidence question; upload without CSRF; restricted user on documents; pre-warm status | 17/17 requests, 39/39 assertions, 5/5 tests; repeatable (second run 200 `existing` / `already`) |

Every request has a `docs` block (what it does, what to expect) and
assertions that are the pass/fail criteria; the README of each collection
maps requests to the workflows in plain words and names the contract each
response conforms to.

### Decisions and trade-offs

1. **Two collections, not one.** Week 1's is a graded deliverable already
   verified against the droplet; adding Week 2 to it would have changed its
   numbering and its verified line. Week 2's collection stands alone, with
   its own login requests, so either runs without the other. *Trade-off:*
   login and session capture are duplicated (four requests); each collection
   is complete on its own, which is what a grader needs.
2. **A quiet demo patient for the document workflow.** The live eval cases
   measure the busiest charts (case 09: zero stripped sentences on the ten
   busiest patients). A document attached by the collection changes a
   chart's facts, so `docPid` defaults to a patient outside that set.
   *Trade-off:* the graded demo patient and the eval patients differ; the
   README says why.
3. **Idempotent by construction.** The upload is deduplicated per patient on
   the file's hash and the extraction answers `already` on a repeat, so the
   collection can be run any number of times without a cleanup step and
   without ever deleting. Assertions accept both the first-run shape (201,
   fresh handoffs) and the repeat shape (200 `existing`, `already`).
   *Trade-off:* the fixture stays attached to the demo chart; deliberate,
   so the highlight can be inspected in the UI afterwards.
4. **The fixture travels with the collection.** `fixtures/lab-layout1.pdf`
   (synthetic, generated, fictitious patient) is copied into the collection
   folder rather than referenced from `tests/evals/fixtures/`, so the
   folder can be exported on its own. *Trade-off:* a 20 KB duplicate that
   must be refreshed if the generator changes the fixture.
5. **The sidecar is exercised through PHP, not directly.** It has no host
   port and PHP is its only client; a direct request would need a
   port-forward that does not exist on the droplet. Requests 08 and 11 are
   the end-to-end proof of `/run`. The test-only `/eval/*` endpoints are
   documented as out of scope.
6. **Loose assertions where the model decides.** Request 11 accepts a
   refusal shape as well as a cited answer, because the question's outcome
   depends on the model and the corpus; the eval harness, not the
   collection, pins those behaviours case by case.

### Verify it

```bash
cd clinical_copilot_week2/api-collection && npx --yes @usebruno/cli@2 run --disable-cookies --env local
cd clinical_copilot_week1/api-collection && npx --yes @usebruno/cli@2 run --disable-cookies --env local
```

### What the audit found and fixed (2026-09-22)

- No Week 2 endpoint was in any collection: `documents.php` (list, upload,
  extract), the guideline-evidence answer, the documents refusals and
  `prewarm.php` had no runnable request. Added as the Week 2 collection.
- Every link to `clinical_copilot/api-collection` (and the other Week 1
  documents) pointed at the pre-rename folder; repointed to
  `clinical_copilot_week1/` across the root README, ARCHITECTURE.md,
  USERS.md, KEY_METRICS.md, TODOS.md, project-tasks.md, the load-test
  README and the Week 1 collection's own run instructions.
- Found for requirement 6: `/ready` does not probe the sidecar although the
  design says it should; noted in request 02's docs and left for that audit.

---

## 6. Separate /health and /ready endpoints

> Expose /health (is the process alive) and /ready (are dependencies
> reachable) as separate endpoints. /ready must actually check that
> OpenEMR, the LLM provider, and the observability backend are reachable,
> not just return 200 unconditionally.

### How it is met

Two services, each with the pair:

| Endpoint | Answers | Checks |
|---|---|---|
| PHP [`public/health.php`](../interface/modules/custom_modules/oe-module-clinical-copilot/public/health.php) | the module is served | nothing; does not even load OpenEMR's globals |
| PHP [`public/ready.php`](../interface/modules/custom_modules/oe-module-clinical-copilot/public/ready.php) → [`ReadinessProbes`](../interface/modules/custom_modules/oe-module-clinical-copilot/src/Ops/ReadinessProbes.php) | 200 `ready` / 200 `degraded` / 503 `not_ready` | **database** (`SELECT 1` through OpenEMR's query layer), **openai** (authenticated `GET /v1/models`), **langfuse** (`GET /api/public/health`), and since this audit **sidecar** (`GET /ready` on the sidecar). Each bounded to 2 s; result cached 60 s so the endpoint cannot be used to spend provider quota |
| sidecar `GET /health` | the process answers, with prompt and parser versions | nothing |
| sidecar `GET /ready` ([app.py](../interface/modules/custom_modules/oe-module-clinical-copilot/sidecar/copilot_sidecar/app.py)) | 200 `ready` / 503 `not_ready` | **contracts** (the proposal contract loads from the mounted directory), **loinc_map** (parses), **corpus_index** (the committed embeddings load and match the chunk list), **tesseract** (binary on PATH), **openai_key** (configured); Cohere reported under `optional`, never blocking |

Database and OpenAI down → 503; Langfuse or the sidecar down → 200
`degraded` with the reason named. Contracts: `health.response`,
`ready.response` (now with `sidecar` and its reason codes),
`sidecar.health.response`, `sidecar.ready.response`, all with shared
examples.

Proof that it is not unconditional, from the dev stack on 2026-09-22:

```
$ curl ready.php   (sidecar running)
{"status":"ready","dependencies":{"database":"ok","openai":"ok","langfuse":"ok","sidecar":"ok"},"degraded":[],...}   HTTP 200
$ docker compose stop copilot-sidecar; sleep 62; curl ready.php
{"status":"degraded","dependencies":{...,"sidecar":"sidecar unreachable"},"degraded":["sidecar"],"from_cache":false}   HTTP 200
$ (sidecar) curl /ready with an empty contracts directory
{"status":"not_ready","dependencies":{"contracts":"contracts unavailable",...}}   HTTP 503   (test_app)
```

### Decisions and trade-offs

1. **The sidecar is degraded-only, like Langfuse.** Without it, briefings
   and follow-ups from chart facts still work; uploads are stored and can be
   retried; questions are answered from facts only. A load balancer must not
   pull the whole module for a dependency that only the Week 2 features
   need. *Trade-off:* a site that relies on document extraction sees
   "degraded" rather than "down"; the reason string says which dependency
   and the `degraded` list is what an alert should watch.
2. **The sidecar's readiness is local and fast; the provider is probed
   once.** `sidecar /ready` checks the things that would make a run fail
   at startup or on the first request (a missing contract mount, a stale
   index, no tesseract, no key) without network calls, so PHP's 2 s probe
   of it never waits on OpenAI. PHP's own `openai` probe covers the
   provider for the whole service. *Trade-off:* the sidecar does not prove
   it can reach OpenAI itself; if PHP can and the sidecar cannot (a
   network policy), the first extraction fails with `model_error`, not
   readiness.
3. **Exercise the dependency the way a run does, not a proxy for it.** The
   index probe loads the embeddings and compares their count with the
   chunk list (the same `RuntimeError` a run would hit); the contract probe
   loads the file the model call sends. *Trade-off:* the first `/ready`
   after start pays the index load once; it is cached in-process after.
4. **Reasons are fixed strings, never messages.** `contracts unavailable`,
   `sidecar unreachable`, `openai unavailable`: an anonymous caller learns
   which dependency, never a path, a key's validity or a quota state
   (`ReadinessProbesTest` asserts a 401 and a 429 from OpenAI both read
   `openai unavailable`). *Trade-off:* the operator opens the log for the
   detail.
5. **Probes are testable against a mocked client.** `ReadinessProbes::probes()`
   became public and takes the HTTP client, so the real closures are tested
   for 200, 503, 500 and a transport failure without a network.
6. **Container health stays liveness.** Both compose files' `healthcheck`
   for the sidecar hits `/health`, so docker restarts a hung process but
   does not restart a healthy process whose contracts mount is missing;
   that condition is visible on `/ready` and, through it, on `ready.php`.

### Verify it

```bash
openemr-cmd e 'php vendor/bin/phpunit -c phpunit-isolated.xml --filter "ReadinessTest|ReadinessProbesTest|ContractsTest"'
cd docker/development-easy && docker compose exec -T copilot-sidecar python -m pytest -q tests/test_app.py
curl -s https://146-190-139-37.sslip.io/interface/modules/custom_modules/oe-module-clinical-copilot/public/ready.php   # on the droplet, after the Phase 8 deploy
```

### What the audit found and fixed (2026-09-22)

- `/ready` did not probe the sidecar although the design said so: sidecar
  probe added (degraded-only), with the contract's dependency list, reason
  enums and `degraded` enum extended, which `ContractsTest` caught the first
  time round.
- The sidecar had a liveness endpoint only: `GET /ready` added with five
  real local checks and a 503 path, two contracts with examples, four
  tests.
- `ReadinessProbes` was not unit-testable: refactored to take the client;
  `ReadinessProbesTest` added.
- The Week 2 API collection's request 02 now asserts `sidecar: ok`.
- **Added after the first Phase 8 deploy (2026-09-23):** a required
  `contracts` probe (the JSON Schema validator library is installed and
  the contract files load). The first deploy shipped a build whose
  validator was a dev-only package; `/ready` said `ready` while every
  sidecar reply failed with a 500. With this probe the same build reports
  `not_ready` with `contract validator missing`. Requirement 3's audit
  notes carry the full account.

---

## 7. Dashboard and alert definitions

> Define at least three alerts on top of your dashboard: one for p95
> latency exceeding threshold, one for error rate exceeding threshold, and
> one for tool failure rate. Document what each alert means and what the
> on-call response is.

### How it is met

The three alerts exist since Week 1, each fully specified (metric, window,
minimum sample, threshold, severity, baseline, meaning, numbered on-call
runbook) in [Week 1 ALERTS.md](../clinical_copilot_week1/ALERTS.md), and
they are delivered: a Langfuse rule fires a signed webhook into the
module's own receiver (`alerts.php`: HMAC verified, replay-protected,
audit row per firing, unit-tested, collection requests 17–19), with the
first real firing recorded with its correlation id.

Week 2 audited each against the new agent and found a blind spot in every
one; [Week 2 ALERTS.md](ALERTS.md) is the record:

| Alert | Week 2 blind spot | Fix |
|---|---|---|
| p95 latency | extraction (5–20 s by design) was silently outside the filter; the ask path gained a retrieval leg | the exclusion is stated; **1b extraction latency** is its own paging rule (> 30 s over an hour); the ask runbook starts with the `retrieve_evidence` span |
| error rate (`request_ok`) | now counts extraction and pre-warm traces, and would have paged on a batch of encrypted or over-long uploads | `request_ok` ignores the three document-caused failure reasons (`unreadable`, `encrypted`, `too_many_pages`); service failures (`model_error`, `timeout`, `schema_mismatch`) still count; pinned by `LangfuseTracerTest` |
| tool failure rate (`tool_ok`) | computed from PHP steps only; a failed sidecar worker never moved it | `tool_ok` is false when any worker reported `worker_failed`, so the existing rule covers the agent's two workers; the failing-span table gained the four Week 2 spans |

Plus four watched rules (`extraction_ok`, `extraction_verified`,
`retrieval_hit`, `prewarm_ok`), an external readiness check on `/ready`
degraded, the Langfuse field-by-field definitions for the new rules, and a
runbook (S1–S7, M1–M2) for every Week 2 failure mode: sidecar down or
OOM-killed, stale retrieval index, invalid Cohere key, scan memory, contract
drift after a deploy, sidecar internal error, missing contracts mount,
provider failure through the sidecar, and the answer-length regression.

### Decisions and trade-offs

1. **Extend the three scores, do not add three rules.** The alerts a grader
   saw in Week 1 keep their names and now cover the Week 2 agent because
   the scores they read were extended. *Trade-off:* the alert name says
   "tool" and the tool may be a Python worker; the ERROR span names it.
2. **Extraction latency is a fourth rule, not a wider first rule.** A
   threshold that fits 20 s extractions would blind the chat rule.
   *Trade-off:* the plan's alert allowance (two rules on Hobby, per the
   Week 1 notes) may keep it a definition until the plan changes.
2b. **On the Hobby plan, the two live slots go to tool failure rate and
   error rate for Week 2.** The project is on Langfuse's Hobby plan (two
   rules); Week 1 made p95 latency and error rate live. Because `tool_ok`
   and `request_ok` now carry the sidecar's failures, those two rules
   catch every Week 2 failure that is not pure latency, so the
   recommendation is to repoint the p95 rule at `tool_ok` and read latency
   from the dashboard until the plan allows four rules. The exact UI steps
   and a change log are in ALERTS.md ("Which two rules are live").
   *Trade-off:* no page on a slow-but-working summary; the fact table is
   already on screen in that case.
3. **The document's fault is not the service's.** A physician's encrypted
   PDF must not page anyone at night; the three document-caused codes are
   assigned by the parser before any model call, so a service fault cannot
   hide behind them. *Trade-off:* the error-rate alert no longer sees bad
   uploads; watched rule 7 does.
4. **Read-only, then restart, then roll back.** Every runbook step is
   ordered that way and the sidecar is stateless, so a restart is always
   safe and never loses a document (files are stored before extraction).
5. **Readiness is watched from outside Langfuse.** A down sidecar produces
   fewer traces, not a metric; an uptime check on `/ready` is the honest
   signal and posts to the same webhook.

### Verify it

```bash
openemr-cmd e 'php vendor/bin/phpunit -c phpunit-isolated.xml --filter "LangfuseTracerTest|AlertReceiverTest"'
cd clinical_copilot_week2/api-collection && npx --yes @usebruno/cli@2 run 02-ready.bru --env local   # the readiness signal rule 11 watches
```

### What the audit found and fixed (2026-09-22)

- `tool_ok` did not count sidecar worker failures; it does now (test).
- `request_ok` would have counted a physician's unreadable upload as a
  service error; it no longer does, service failures still count (test).
- No latency rule covered extraction, and no runbook covered any Week 2
  failure mode; both written in Week 2 ALERTS.md, with the Langfuse
  definitions for the new rules.
- The plan allows two live rules (Hobby). Which two should be live for
  Week 2 is decided and written down (tool failure rate + error rate),
  with the UI steps to make the change; the change itself is pending in
  the Langfuse UI.

---

## 8. Baseline CPU, memory, latency, and throughput profiles

> Capture baseline infrastructure metrics (CPU, memory, request latency,
> throughput) under the load test scenarios. Include these baselines in
> your submission so future performance changes can be measured against
> them.

### How it is met

[BASELINES.md](BASELINES.md) records the Week 2 build's profiles on the
deployed droplet, captured with the Week 1 tooling in
[../tests/load/](../tests/load/README.md) extended for the Week 2 agent:
a resource sampler that now buckets the sidecar container separately
(CPU %, memory used/limit every 2 s, host load, streamed over ssh), an
`extract` scenario for the document path, and a summariser that prints the
latency/error table, a document-extraction table and a CPU/memory table
with app, sidecar and database columns. Raw results are committed under
`tests/load/results/` (two stamps, `20260923T1522Z` and
`20260923T1558Z`), so any later run compares against them with the same
script.

The file records two runs on purpose. Run 1, on the Phase 8 deploy, found
that the sidecar serialised every request (an `async` handler calling the
synchronous graph on the event loop): at 10 users 95 % of extractions hit
the 60 s budget and the follow-up p50 was 19 s with the sidecar at 2 % CPU.
Run 2, 33 minutes later on the fix (the graph on the thread pool), is the
baseline going forward: 100 % of extractions succeed at 10 and 50 users
(p95 19.6 s and 29.7 s), follow-up p50 5.2 s / 8.0 s, sidecar under 330
MiB and 60 % CPU peak. The pair is what "measure future changes against a
baseline" means here, and the finding could not have come from the eval
suite or the API collections, which run one request at a time.

### Decisions and trade-offs

1. **Same tooling, one more scenario, one more bucket.** Extending Week 1's
   scripts rather than adopting a new tool keeps the Week 1 and Week 2
   numbers comparable row for row. *Trade-off:* k6 over the public internet
   from a laptop adds network variance to every latency; the sidecar-side
   `run` log lines give the server-side view when it matters.
2. **A real extraction every iteration.** The `extract` scenario appends a
   unique PDF comment so the per-patient dedup never short-circuits it;
   otherwise the load test would measure a cache. *Trade-off:* a few hundred
   real documents and lab rows on the load patient per matrix, removed by
   `cleanup-documents.php` afterwards (156 after run 2, 0 left).
3. **Record the bad run.** Run 1 stays in the file with its explanation
   instead of being replaced, because the value of a baseline is the
   before/after. *Trade-off:* a reader has to read two tables.
4. **`guideline_hit_pct` measures citations, not retrieval.** Reported as
   what it is, with the sidecar log's retrieval hit rate beside it, rather
   than redefined to look better.
5. **Sidecar memory is measured against its limit.** The 768 MiB cap was set
   by the spike; run 2 shows 330 MiB peak at 50 users with tesseract idle
   (text-layer fixture), so the cap has room but has not been tested with
   fifty concurrent scans; that is noted, not assumed.

### Verify it

```bash
python3 tests/load/summarise.py 20260923T1558Z       # the run-2 tables from the committed results
BASE_URL=https://146-190-139-37.sslip.io LOGIN_PASS=<admin password> STATS=ssh SSH_HOST=do-openemr tests/load/run-baselines.sh
```

### What the audit found and fixed (2026-09-23)

- The baselines described the Week 1 build; re-captured on the Week 2 build.
- No scenario exercised the document path and the sidecar was not a bucket
  in the resource tables; both added.
- The first capture found the sidecar serialising all work; fixed
  (`96a0947`), redeployed, re-measured, both runs recorded.

---

## 9. Load and stress tests at 10 and 50 concurrent users

> Load tests simulating at least 10 and 50 concurrent users against the
> deployed agent; record p50/p95/p99 latency and error rate at each level.

### How it is met

The same k6 matrix as requirement 8, against the deployed droplet: {10, 50}
virtual users × {brief, mixed, ask, extract}, two minutes each, each VU a
real physician session (OpenEMR login, chart open with the panel's CSRF
token, brief, ask, and in Week 2 upload + extract). p50/p95/p99/max per
endpoint, HTTP error rate, Co-Pilot error rate (a response that is not a
200 with the contract's body), summary-unavailable rate and verification
failure rate at each level are in [BASELINES.md](BASELINES.md); the raw
per-run JSON and text summaries are under `tests/load/results/`.

Headline numbers on the fixed build (run 2, `20260923T1558Z`):

| Level | Scenario | p50 / p95 / p99 (ms) | Errors |
|---|---|---|---|
| 10 users | ask (retrieval + model) | 5 163 / 7 342 / 8 997 | 0 % HTTP, 0 % Co-Pilot |
| 10 users | extract (five-page document) | 16 730 / 19 576 / 20 924 | 0 % / 0 %; 100 % extracted and fully verified |
| 50 users | ask | 8 027 / 9 971 / 11 611 | 29 % HTTP / 44 % Co-Pilot, all from the login burst (Week 1's known MariaDB connection ceiling); 0 % once a session exists |
| 50 users | extract | 22 775 / 29 705 / 35 024 | 1.3 % / 2.9 %, same cause; 100 % extracted and fully verified |

And on the Phase 8 build before the fix (run 1): extract at 10 users
60 383 / 60 652 / 60 701 ms with 5 % extracted; ask 19 096 / 38 960 /
39 591 ms.

### Decisions and trade-offs

1. **The 50-user error mode is reported, not tuned away.** It is a
   deployment setting (Apache workers vs MariaDB connections) recorded in
   Week 1 and outside the module; changing it for the run would make the
   two weeks incomparable. *Trade-off:* the 50-user rows measure the
   droplet's login ceiling as much as the agent.
2. **Provider limits are part of the baseline.** `extract` at 50 users makes
   ~35 documents × 5 calls per minute; no 429s appeared on this account at
   that rate, and `summary_unavailable` stayed 0 %, so the numbers are the
   sidecar's, not the provider's. A higher level would meet the token limit
   first.
3. **Cost.** Run 1 and run 2 together: ~370 extractions (~1.7 M tokens) and
   ~330 follow-ups, about $0.40 at list price; recorded in
   COST_AND_LATENCY.md's spend table.

### What the audit found and fixed (2026-09-23)

- No Week 2 level had been measured; measured, and the first measurement
  found the concurrency defect described under requirement 8.

---

## Where the code lives

| Concern | Path |
|---|---|
| Eval harness, gate, hook installer, case checker | [tests/evals/](../tests/evals/README.md) |
| Eval cases and fixtures | [tests/evals/cases/](../tests/evals/cases/), [tests/evals/fixtures/](../tests/evals/fixtures/) |
| PHP module (controllers, facts, verifier, ids, logging, tracing) | [`<module>/src/`](../interface/modules/custom_modules/oe-module-clinical-copilot/src/) |
| Python sidecar (parser, anchoring, graph, retrieval, logging) | [`<module>/sidecar/`](../interface/modules/custom_modules/oe-module-clinical-copilot/sidecar/README.md) |
| Contracts (JSON Schema, the source of truth for every boundary) and their shared examples | [`<module>/contracts/`](../interface/modules/custom_modules/oe-module-clinical-copilot/contracts/README.md), [`contracts/examples/`](../interface/modules/custom_modules/oe-module-clinical-copilot/contracts/examples/) |
| PHPUnit tests for the module | [tests/Tests/Isolated/Modules/ClinicalCopilot/](../tests/Tests/Isolated/Modules/ClinicalCopilot/), [tests/Tests/Services/Modules/ClinicalCopilot/](../tests/Tests/Services/Modules/ClinicalCopilot/) |
