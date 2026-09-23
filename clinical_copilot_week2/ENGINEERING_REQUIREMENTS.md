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
| 5–9 | API collection, health/ready, alerts, baselines, load tests | Week 1 status stands; re-audited here as each is reviewed | — |

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

Not yet on the droplet; ships with the Phase 8 deploy.

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

## Where the code lives

| Concern | Path |
|---|---|
| Eval harness, gate, hook installer, case checker | [tests/evals/](../tests/evals/README.md) |
| Eval cases and fixtures | [tests/evals/cases/](../tests/evals/cases/), [tests/evals/fixtures/](../tests/evals/fixtures/) |
| PHP module (controllers, facts, verifier, ids, logging, tracing) | [`<module>/src/`](../interface/modules/custom_modules/oe-module-clinical-copilot/src/) |
| Python sidecar (parser, anchoring, graph, retrieval, logging) | [`<module>/sidecar/`](../interface/modules/custom_modules/oe-module-clinical-copilot/sidecar/README.md) |
| Contracts (JSON Schema, the source of truth for every boundary) and their shared examples | [`<module>/contracts/`](../interface/modules/custom_modules/oe-module-clinical-copilot/contracts/README.md), [`contracts/examples/`](../interface/modules/custom_modules/oe-module-clinical-copilot/contracts/examples/) |
| PHPUnit tests for the module | [tests/Tests/Isolated/Modules/ClinicalCopilot/](../tests/Tests/Isolated/Modules/ClinicalCopilot/), [tests/Tests/Services/Modules/ClinicalCopilot/](../tests/Tests/Services/Modules/ClinicalCopilot/) |
