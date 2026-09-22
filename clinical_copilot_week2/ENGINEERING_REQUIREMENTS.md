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
| 3–9 | Contracts, dashboard, API collection, health/ready, alerts, baselines, load tests | Week 1 status stands; re-audited here as each is reviewed | — |

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

## Where the code lives

| Concern | Path |
|---|---|
| Eval harness, gate, hook installer, case checker | [tests/evals/](../tests/evals/README.md) |
| Eval cases and fixtures | [tests/evals/cases/](../tests/evals/cases/), [tests/evals/fixtures/](../tests/evals/fixtures/) |
| PHP module (controllers, facts, verifier, ids, logging, tracing) | [`<module>/src/`](../interface/modules/custom_modules/oe-module-clinical-copilot/src/) |
| Python sidecar (parser, anchoring, graph, retrieval, logging) | [`<module>/sidecar/`](../interface/modules/custom_modules/oe-module-clinical-copilot/sidecar/README.md) |
| Contracts (JSON Schema, the source of truth for every boundary) | [`<module>/contracts/`](../interface/modules/custom_modules/oe-module-clinical-copilot/contracts/README.md) |
| PHPUnit tests for the module | [tests/Tests/Isolated/Modules/ClinicalCopilot/](../tests/Tests/Isolated/Modules/ClinicalCopilot/), [tests/Tests/Services/Modules/ClinicalCopilot/](../tests/Tests/Services/Modules/ClinicalCopilot/) |
