# ARCHITECTURE.md — Clinical Co-Pilot

## Summary

The Clinical Co-Pilot is an OpenEMR custom module
(`interface/modules/custom_modules/oe-module-clinical-copilot`) that adds a
panel to the patient dashboard for a primary care physician (see
[`USERS.md`](USERS.md)). When a chart opens, the panel shows what changed since the
previous visit and anything flagged on file, then answers follow-up
questions in a multi-turn thread. It is a conversational agent in the PRD's
sense, but the design decision that shapes everything is that **the
language model never generates clinical facts.**

Deterministic PHP (`FactAssembler`) reads the chart through patient-scoped
queries, enforces OpenEMR's ACL before the first row is read, and builds a
typed fact set: each fact carries a content-derived id, the source table,
record id and field, and a rendered value. That table is sent to the panel
and rendered first, with no model involved, so the physician has verified
information within the fact-assembly time (measured at 6-55 ms). The model
is then asked, asynchronously, to narrate *by fact id*. A pure `Verifier`
strips any sentence with no citation, an unknown citation, or a number or
date that does not appear verbatim in a cited fact. An `OmissionGuard` then
appends every must-surface fact (new medications, new allergies,
allergy-vs-medication matches, abnormal labs, new problems) that the
narration failed to cite. The design responds to two findings from the
audit and the research: authorization in OpenEMR is often menu-gated rather
than enforced at the service layer, so the agent enforces it itself; and
clinical summarizers fail far more often by omission than by hallucination,
so coverage is enforced, not hoped for.

Key decisions: OpenAI `gpt-4o-mini` with Structured Outputs (a strict JSON
schema of cited sentences); no agent framework, because one tool and one
schema need no orchestration layer. One LLM tool, not three: "active
medications" and "allergy flags" are fact categories, and the
allergy-vs-medication check is a deterministic cross-check. Verified
briefings are cached by a hash of the facts, the prompt version and the
model, so re-opening a chart costs nothing and prompt edits never serve
stale text. The transcript is held in the browser and re-verified every
turn. Abnormal labs come from a small, cited, versioned reference-range
table because the seed data carries no ranges; drug-drug interactions are
out of scope because no source of truth exists in this install.

Trust boundaries: the browser is untrusted (CSRF token, patient id from the
session only, transcript re-verified); the model is untrusted (ID-only
narration, strip on any ungrounded claim, chart text passed as delimited
data with an explicit "never instructions" rule); the provider is untrusted
with identifiers (no name, date of birth or MRN leaves the server; fact
values only). Every request carries a correlation id through logs, the
OpenEMR audit log and a Langfuse trace with tokens and verification outcome.
`/health` and `/ready` are separate; readiness really probes the database,
OpenAI and Langfuse, cached 60 s so it cannot be used to spend quota.

Known limitations, stated rather than hidden: the verifier checks values,
not meaning, so "lisinopril was discontinued" about a started drug passes
(mitigated by the fact table being the primary UI; eval case 08 keeps it
visible); sensitivity filtering covers encounters and their labs, not
medications, allergies or problems, which are not encounter-scoped in
OpenEMR. The eval suite (`tests/evals`) exercises each of these.

## Where it lives

```
interface/modules/custom_modules/oe-module-clinical-copilot/
├── openemr.bootstrap.php      registers the namespace, subscribes Bootstrap
├── src/
│   ├── Bootstrap.php          PatientDemographics RenderEvent → panel HTML
│   ├── Controller/ChatController.php   chat.php handler: session, CSRF, ACL, brief/ask
│   ├── FactAssembler.php, FactSet.php, Fact.php, FactCategory.php
│   ├── OpenEmrChartSource.php, AclAuthorization.php   adapters over OpenEMR
│   ├── ReferenceRanges.php    curated LOINC ranges (versioned)
│   ├── Prompt.php             prompts + strict schemas, VERSION in cache key
│   ├── Llm/OpenAiClient.php   Guzzle, typed failures, bounded retry
│   ├── NarrationPipeline.php  cache → model → Verifier → OmissionGuard
│   ├── Verifier.php, OmissionGuard.php   pure, unit-tested
│   ├── DbBriefingCache.php    copilot_briefing_cache table
│   ├── PanelPayload.php       JSON contract to the panel
│   └── Ops/                   Readiness, LangfuseTracer, CorrelatedLogger
├── public/chat.php, health.php, ready.php, assets/panel.js, panel.css
└── sql/install.sql, register.sql, uninstall.sql
```

It hooks `PatientDemographics\RenderEvent::EVENT_SECTION_LIST_RENDER_BEFORE`,
the same extension point `oe-module-dashboard-context` uses, so nothing in
core OpenEMR is modified.

## Data flow

```
chart page load (today's encounter in session)
        │
        ▼
 panel.js ── POST chat.php {csrf, action:brief}
        │
        ▼
 ChatController: session ▸ CSRF ▸ pid=$_SESSION ▸ correlation id
        │
        ▼
 FactAssembler(pid, currentEncounter)
   ├─ ACL: patients/med, encounters/notes  ── deny → 403, nothing read
   ├─ encounters (sensitivity-filtered) → prior visit (rule below)
   ├─ prescriptions → new / active; allergies → new / active
   ├─ allergy × medication → allergy_medication_hit
   ├─ labs since prior (encounter-linked, sensitivity-filtered)
   │     → lab_abnormal (reference table), lab_delta (vs previous same LOINC)
   ├─ problems since prior → problem_new
   ├─ per-category cap 50 → truncation fact
   └─ FactSet{ facts[id → …], hash }        ── rendered first, no model
        │
        ▼
 NarrationPipeline
   ├─ cache(facts_hash | Prompt::VERSION | model) ─hit─► verified narration
   ├─ OpenAiClient (Structured Outputs, temperature 0, 1 retry, 25 s budget)
   ├─ Verifier: ≥1 known id per sentence; every number/date verbatim in a cited fact
   ├─ OmissionGuard: must-surface facts not cited → "Also on file"
   └─ cache store (never on total failure)
        │
        ▼
 PanelPayload → JSON → panel.js (table, summary with chips, status line)
        │
        └─ logs (correlation id) · audit log row · Langfuse trace + generation
```

**Prior-visit rule.** With a current encounter in the session: the latest
encounter strictly before it by (date, id). Without one: the latest before
the start of today. No prior encounter: "first visit on record", diff
skipped, allergy checks still run.

**Follow-ups.** `action=ask` re-assembles facts; if the client's
`facts_hash` differs, the response is `chart_changed` and the panel restarts
the thread on fresh facts. Otherwise the same fact set, the question and the
last ten turns go to the model with a schema whose `answer_type` is `cited`
or `not_in_facts`. Answers pass the same verifier; follow-ups are never
cached.

## Verification, precisely

Two layers, both deterministic PHP:

1. **Verifier** (`Verifier::verify(Narration, FactSet)`), a pure function:
   - a sentence with no fact ids is stripped;
   - a sentence citing an id not in the set is stripped;
   - a sentence containing a number or ISO date token (digits not embedded
     in a word, so "A1c" is not a number) that does not appear verbatim in a
     cited fact's rendered value is stripped. Inline `[id]` or `[id, id]`
     echoes of cited ids are ignored before scanning.
   - stripped sentences are replaced in the panel by an explicit marker; if
     every sentence is stripped, the panel shows a distinct "unable to
     verify" state and the fact table only. Nothing is cached in that case.
2. **OmissionGuard**, coverage: every fact whose category is must-surface
   and that no kept sentence cites is appended in a fixed section rendered
   straight from the fact table.

Because the model narrates by id and PHP emits computed facts (deltas,
"N more not shown"), no value normalizer exists: the comparison is exact
string containment. What this cannot catch, by construction, is a sentence
that cites the right fact and inverts its meaning without stating a wrong
value. That is why the fact table, not the prose, is the primary UI, and
why eval case 08 exists to keep the limitation visible.

## Authorization and PHI

- OpenEMR's service layer performs no ACL checks on reads (confirmed by
  inspection: `PrescriptionService`, `AllergyIntoleranceService`,
  `BaseService` contain none; `EncounterService` checks only on update). The
  module therefore calls `AclMain::aclCheckCore()` itself, with the session
  user passed explicitly, for `patients/med` and `encounters/notes` before
  any read, and `sensitivities/<level>` per encounter. Seed users
  `receptionist` and `accountant` are refused; `physician`, `clinician`,
  `admin` are allowed.
- The patient id is taken from the OpenEMR session only. A pid in the
  request body is ignored.
- Every response and audit row is keyed by a correlation id. The audit log
  (`log` table, event `clinical-copilot`) records user, patient id, action,
  correlation id, fact and strip counts, success flag. No fact values.
- What leaves the server: fact values (clinical strings and numbers), never
  the patient's name, date of birth, MRN, address or phone. `OpenEmrChartSource`
  selects none of those columns. Langfuse receives counts and the
  correlation id, not fact text.
- The PRD instructs us to treat a BAA with the LLM provider as signed; the
  minimization above is applied regardless.

## Failure modes and what the physician sees

| Failure | Handling | Physician sees |
|---|---|---|
| ACL denied | refused before any read | "You are not authorized to view this chart" |
| OpenAI 429 / 5xx | one jittered retry, then typed failure | fact table + "AI summary unavailable: provider busy" |
| OpenAI timeout | retry within 25 s budget, then typed failure | fact table + "timed out" |
| Malformed / refused model output | typed failure | fact table + status line |
| Every sentence stripped | not cached, distinct state | "Unable to verify the AI summary … showing verified chart facts only" |
| Question outside the facts | `not_in_facts` | fixed sentence pointing to chart tabs |
| Computed number in an answer | stripped | "The answer was withheld: it contained N claims not supported by the facts" |
| Chart changed mid-thread | `chart_changed` | facts refreshed, thread restarted |
| Panel endpoint unreachable | async fetch with 30 s abort | chart page unaffected; panel shows "could not be reached" |
| Langfuse down | best effort, 2 s bound | nothing; `/ready` reports `degraded` |

## Observability

- `public/health.php`: liveness, 200 if PHP serves the module.
- `public/ready.php`: probes `SELECT 1`, `GET https://api.openai.com/v1/models`,
  Langfuse `/api/public/health`, each bounded to 2 s; result cached 60 s in a
  file; database or OpenAI down → 503; Langfuse down → 200 `degraded`.
- Every module log entry carries `correlation_id` (`CorrelatedLogger`).
  OpenEMR drops INFO/NOTICE unless an operator sets
  `system_error_logging=DEBUG`, so the always-on record is the audit log row.
- `LangfuseTracer` sends per request one trace (id = correlation id) with
  metadata: action, HTTP status, facts, stripped, omitted, from_cache,
  total_failure, answer_type, verification_pass, duration; and, when the
  model ran, one generation with model, tokens, latency and status. Alerts
  (p95 latency, error rate, tool failure rate) are configured on those
  fields in Langfuse; thresholds are in [`KEY_METRICS.md`](KEY_METRICS.md).

## Deployment

Single VPS running the `openemr/openemr:flex` image, which clones this
fork's `audit` branch at start; MariaDB alongside; `docker/vps/`. The
upstream production compose cannot be used because it pulls the upstream
image without this module. Seed data moves as an `openemr-cmd` capsule so
the eval patients match locally and remotely. Module enablement is
`sql/install.sql` + `sql/register.sql` or the Module Manager UI.

## Testing

- 73 isolated PHPUnit tests (no database) over `FactAssembler` (fakes for
  chart and ACL), `Verifier`, `OmissionGuard`, `NarrationPipeline`,
  `OpenAiClient` (Guzzle mock), `PanelPayload`, `Readiness`,
  `LangfuseTracer`, `CorrelatedLogger`.
- `tests/evals`: 11 cases, each guarding a boundary, invariant or
  regression; 3 run live against OpenAI and report strip rate, latency and
  tokens. Latest run: 11/11, 1 of 59 sentences stripped, 0 omissions.
- `tests/evals/smoke.php`: Selenium through the real dashboard for 10
  patients as admin and refusal as receptionist.
- Deferred to the final submission: the dashboard regression E2E in the
  Panther suite, DB-backed tests for the adapters, load tests.

## Tradeoffs made knowingly

| Chose | Over | Because |
|---|---|---|
| Facts-first, ID-only narration | free prose + post-hoc fact checking | verification becomes set membership plus a digit scan; no normalizer, no re-fetch |
| One LLM tool | tool per data source | with facts assembled deterministically, extra tools were categories in disguise |
| Direct patient-scoped SQL in the adapter | service-layer calls | service rows lack prescription ids/start dates and lab→encounter links needed to cite and to filter by sensitivity |
| Client-held transcript | conversation table | no migration before the gate; every turn re-verified anyway; persistence tracked in [`TODOS.md`](../TODOS.md) |
| Curated reference-range table | seeding abnormal flags | works on all 30 patients, is a real clinical rule, is versioned and cited; local labs vary |
| flex image on a VPS | custom image | zero adaptation the night before the gate; custom image in [`TODOS.md`](../TODOS.md) |
| Langfuse Cloud | self-hosted | four extra services on one box; only counts and ids leave the server |

## How the audit shaped this

[`AUDIT.md`](AUDIT.md) findings that became design constraints: menu-gated
authorization (→ ACL enforced in the tool layer, verified by negative
tests); no caching layer anywhere (→ the briefing cache is the first,
keyed to be invalidation-safe); N+1 patterns in shared services (→ direct
bounded queries; worst seed patient assembles in 55 ms); no
"encounter finalized" event (→ the prior-visit rule above); audit log holds
PHI and its checksum is unverified (→ the module writes ids and counts only
and does not attempt to fix the log; separate remediation); 99.8% of seed
encounters reference a nonexistent facility (→ facility is optional; the
join is a LEFT JOIN). Data gotchas found during the spike (zero dates in
`procedure_result.date` and `prescriptions.start_date`, no `abnormal` flags
or ranges in 5,605 results) are handled in `OpenEmrChartSource` and
`ReferenceRanges`. A medication or allergy without a recorded start/onset
date is dated by when the clinician first entered it and the fact value
says `first noted` rather than `started`/`onset`, so the model can cite a
date without the physician mistaking an entry date for a clinical one
(`DateProvenance`); 67 of 68 active seed prescriptions take this path.
