# TODOS

## Week 1 grader feedback (open, scheduled for Week 2 Thursday)

Two items from the Week 1 submission review. Both are documentation, both
are scheduled as tasks 9.1 and 9.2 in
[clinical_copilot_week2/DESIGN.md](clinical_copilot_week2/DESIGN.md).

### Cost analysis: per-tier architectural changes

**What:** The cost analysis has two sections (development spend, projected production). It needs the per-tier view the rubric asks for: 1 clinic (1-5 physicians), group practice (about 50), network (about 500), with monthly model, embedding and rerank spend from measured tokens, pre-warm on and off, cache-hit assumptions, and the architectural change each tier forces (shared cache, a queue for the pre-warm, sidecar replicas, provider rate-limit budget, per-tenant keys).

**Where:** `COST_AND_LATENCY.md` (new in Week 2; also the Week 2 cost and latency report). Inputs: `tests/load/results/`, the scaling plan from commit 99d926a.

**Effort:** S
**Priority:** P1

### USERS.md: personas and use cases are thin

**What:** Expand the PCP persona (day shape, what they will not read, what "safe to act on" means), add the front-desk uploader persona that Week 2 introduces (permissions, what they see, what they must not see), and add UC4 (a lab PDF arrives before the visit) and UC5 (the intake form contradicts the chart) with the exact click path.

**Where:** `USERS.md`.

**Effort:** S
**Priority:** P1

## Clinical Co-Pilot

- ~~PHPStan level 10 is not clean for the Co-Pilot code (544 errors measured 2026-09-22).~~ **Done 2026-09-22:**
  `openemr-cmd pst` reports no errors; how, and how to keep it that way, is in
  `clinical_copilot_week2/STATIC_ANALYSIS.md`. The historical `tests/evals/spike/` scripts are excluded from
  analysis (documented there); everything else was fixed at the source. Next step: `openemr-cmd prek-install`.


### Server-side conversation persistence

**What:** Store chat threads in a table keyed by (pid, encounter_id, user_id) instead of holding the transcript in the browser.

**Why:** Threads survive page reloads and there is an audit trail of what the agent said to whom about which patient. The HIPAA audit section of the submission will ask how agent interactions are recorded.

**Context:** Cut from v1 in the eng review (decision D1, 2026-09-15) to avoid a migration before the Wednesday gate. The v1 endpoint receives the full transcript each turn and re-verifies it, so correctness does not depend on server state. Insertion point: `NarrationPipeline` already has the transcript; add a `copilot_conversation` table in the module's `sql/install.sql`, write each turn, and load by key on panel open. Needs a retention policy (PHI at rest) documented in ARCHITECTURE.md.

**Effort:** M
**Priority:** P1
**Depends on:** None

### Custom production image

**What:** A Dockerfile that bakes the fork (including the module) into an image, replacing the flex bind-mount deploy.

**Why:** The Wednesday deploy runs the dev-flavored `openemr/openemr:flex` image with the source tree bind-mounted on the VPS (decision D13). A real image is reproducible, keeps no source tree on the server, and is what a hospital would run.

**Context:** `docker/production/docker-compose.yml` pulls the upstream `openemr/openemr:latest` image, which does not contain the fork. Start from the upstream Dockerfile in openemr-devops, COPY the fork over `/var/www/localhost/htdocs/openemr`, build in CI, push to a registry, and point the production compose at it. Module enablement must be scripted (SQL) since Module Manager is a UI step.

**Effort:** M
**Priority:** P2
**Depends on:** Wednesday deploy working on flex + bind-mount first

### Drug-drug interaction source of truth

**What:** Wire an interaction source (NLM RxNorm interaction API or a curated rule table) so `FactAssembler` can emit grounded interaction facts.

**Why:** The PRD names interaction flags as a domain constraint. v1 ships allergy-vs-medication only, because this install has no interaction data and letting the LLM decide interactions from general knowledge is an ungrounded clinical claim.

**Context:** Design doc premise #4 (`clinical_copilot/DESIGN.md`). Prescriptions in OpenEMR carry a drug name and optionally an RxNorm code (`prescriptions.rxnorm_drugcode`); most seed rows will need name-to-RxCUI mapping. Emit interactions as a new fact category so the verifier and omission guard cover them with no other changes.

**Effort:** L
**Priority:** P3
**Depends on:** RxNorm mapping for seeded prescriptions; licensing review of the chosen source

### Nurse intake in the briefing: reason for visit and new symptoms

**What:** Surface today's intake in the briefing as two must-surface fact categories: `visit_reason` (the chief complaint the nurse recorded at check-in) and `symptom_new` (symptoms reported today that were not on file at the prior visit).

**Why:** The physician reads the briefing between rooms, and the first thing they want to know is why the patient is here today. v1 diffs the chart against the prior visit but does not show today's reason or the nurse's intake notes, so the physician still opens the encounter tab before walking in.

**Context:** Future iteration, deferred 2026-09-16 (office-hours session; no time before the submission gate). Sources already in OpenEMR: `form_encounter.reason` for today's encounter (`EncounterRecord::reason` is already loaded by `OpenEmrChartSource::encounters()`, so `visit_reason` is one new fact on the current encounter, not a new query). Symptoms live in encounter forms attached to today's encounter: `form_soap.subjective` (free text), `form_ros` / `form_reviewofs` (per-symptom yes/no columns), and `form_vitals`. Add `ChartSource::intake(PatientId, encounterId)` returning a typed `IntakeRecord`; `FactAssembler` emits `visit_reason` from `form_encounter.reason` and one `symptom_new` fact per ROS column that is positive today and was not positive on the prior encounter's ROS form (a deterministic diff, same pattern as `MedicationNew`). Free-text `subjective` goes in as a single fact whose value is the verbatim text, capped, so the verifier's verbatim rule still holds. Both categories are `mustSurface() = true` so the OmissionGuard appends them if the model skips them. Add both to the `category` enum in `contracts/fact.schema.json` (ContractsTest asserts the enum equals `FactCategory::cases()`) and bump `Prompt::VERSION`. Seed data check first: count how many of the 30 seed patients have a SOAP or ROS form on their latest encounter; if fewer than 3, add one under `tests/evals/seed/`.

**Effort:** M
**Priority:** P1
**Depends on:** None

### Due-today checklist: clinical reminders and immunizations

**What:** One fixed checklist section in the briefing listing every screening and vaccination that is `past_due`, `due`, or `soon_due` (due within one month), drawn from OpenEMR's Clinical Reminders and the immunization history, rendered directly from facts with no model involvement.

**Why:** The chart already computes these (the "Clinical Reminders" widget on the dashboard and the Immunizations tab) but in two places, neither of which the physician looks at between rooms. Putting them in the briefing panel is the same placement argument as UC3 in `USERS.md`: not new data, one fewer place to look.

**Context:** Future iteration, deferred 2026-09-16. Clinical Reminders are computed live by `library/clinical_rules.php::test_rules_clinic('', 'active_alert', $date, 'reminders-all', $pid, ...)`, which returns rows of `{category, item, due_status, rule_id}` with `due_status` in `due | soon_due | past_due | not_due` (list `rule_reminder_due_opt`); the dashboard widget `active_alert_summary()` formats these as HTML, so call `test_rules_clinic` directly and keep the array. Seeded rules cover colon, mammogram, pap, prostate screening, influenza (>= 50), pneumococcal (>= 65), diabetic eye/foot/A1c, hypertension BP, tobacco assessment. Immunizations come from `ImmunizationService::getAll(['patient_id' => $pid])`; `administered_date`, `cvx_code`, `immunization_id`, `added_erroneously`. Emit two new fact categories: `reminder_due` (value: "<category>: <item> (<due_status>)", source `ClinicalRules#<rule_id>.due_status`, must-surface, filter out `not_due`) and `immunization` (value: "<name> <administered_date>", source `ImmunizationService#<id>.administered_date`, not must-surface; the due-ness of vaccines already comes through the reminder rules, so do not re-derive vaccine schedules in PHP). Rule evaluation reads many tables and is the slowest thing on the dashboard; time it in the spike against the 5 s briefing budget and, if it blows it, evaluate only the `patient_reminders` table (the batch-computed copy) instead of live rules and label the section with `date_created`. ACL: reminders need `patients/med` (already checked); immunizations are covered by the same gate. Add both categories to `fact.schema.json` and bump `Prompt::VERSION`. Panel: render the checklist as its own block above the AI summary, grouped past_due, due, soon_due, so the physician sees it without reading prose.

**Effort:** M
**Priority:** P1
**Depends on:** Nurse intake entry is independent; both should land with the briefing schema below so the section order is fixed once

### Briefing schema: fixed section list for the AI summary

**What:** A `briefing.schema.json` contract that names every section the briefing presents, in order, with each section bound to the fact categories it may contain: Reason for visit; New symptoms; Due today (reminders and immunizations); Safety flags (allergy-vs-medication, abnormal labs); Since last visit (new/changed meds, new allergies, new problems, lab deltas); Prior visit. The LLM narration output schema gains a required `section` field per sentence drawn from that enum, and the panel renders sections in schema order.

**Why:** The fact set is large and the narration currently orders and groups it however the model likes, so two patients with similar charts get differently shaped briefings and the physician cannot build a reading habit. A fixed section list makes the output the same shape every time; the model only decides wording and priority within a section.

**Context:** Future iteration, deferred 2026-09-16. Fits the existing contracts-as-source-of-truth rule in `interface/modules/custom_modules/oe-module-clinical-copilot/contracts/README.md`: write the schema first, then conform. Concretely: (1) add `contracts/briefing.schema.json` with `sections: [{id, title, categories[]}]` as a static document the panel and the prompt both load; (2) extend `llm.briefing.output.schema.json` sentences with `section` (enum of section ids) and send it as strict Structured Output, so the provider enforces the enum; (3) add a Verifier rule: a sentence whose cited facts are not all in its section's allowed categories is stripped, like an unknown fact id; (4) the OmissionGuard appends missed must-surface facts under the correct section rather than one trailing "Also on file" block; (5) `PanelPayload::briefing()` groups facts and sentences by section; the response contract `chat.briefing.response.schema.json` gains a `sections` array; (6) bump `Prompt::VERSION` to invalidate `copilot_briefing_cache`. Empty sections render a fixed "none on file" line so the shape never changes. Document the section list in `ARCHITECTURE.md` and the user-facing order in `USING_CLINICAL_COPILOT.md`.

**Effort:** M
**Priority:** P1
**Depends on:** Nurse intake and due-today entries (they supply the first three sections); can ship first with the existing categories and grow the enum

### Physician rating of the AI summary: thumbs up / down with a comment

**What:** Two buttons on the AI summary block of the panel (thumbs up, thumbs down). Either one records the rating immediately and opens an optional free-text comment box; the comment is saved when submitted. Ratings and comments are logged, audit-logged, sent to the trace as a score, and rolled up as thumbs-up %, thumbs-down % and no-response % per day in the dashboard.

**Why:** The verifier proves the summary is grounded; nothing today tells us whether the physician found it *useful*. A one-click rating is the cheapest usefulness signal that is attributable to a specific briefing, prompt version and model, which a satisfaction survey is not (see "Why not other metrics" in `KEY_METRICS.md`). The comment is the qualitative channel: "missed that the patient is post-op" is worth more than a score.

**Context:** Future iteration, deferred 2026-09-16 (office-hours; no time before the submission gate). Pieces, all reusing existing paths:
- **Request:** new `action=rate` in `contracts/chat.request.schema.json` with `{rating: "up" | "down", comment?: string (max 2000), facts_hash, briefing_cache_key}`; `ChatRequest::fromBag()` parses it at the boundary; `ChatRequestTest` gets the new bodies. Same CSRF, session pid and ACL gate as `brief`/`ask` (`ChatController`), so only a user who could see the briefing can rate it. Response contract `chat.rate.response.schema.json` is `{ok: true}`.
- **Storage:** `copilot_briefing_rating` in the module's `sql/install.sql`: `id, pid, encounter_id, user_id, briefing_cache_key, prompt_version, model, correlation_id, rating ENUM('up','down'), comment TEXT NULL, created_at`. Keyed to `copilot_briefing_cache` by cache key so a rating is tied to the exact narration shown, not to the patient. One rating per (user, cache key); a second click replaces it (idempotent, no double counting). Comment is PHI-adjacent free text: same retention policy as the conversation-persistence entry above; strip control phrases the way fact values are before any of it reaches a prompt (it never should; it is for humans).
- **Logging and trace:** `copilot rating` log line with correlation id, rating, comment length (never the comment text in the log); `EventAuditLogger::newEvent('clinical-copilot', ..., 'action=rate rating=up ...')` audit row like the existing brief/ask rows; Langfuse `score` on the briefing's trace (name `physician_rating`, value 1 / 0, comment attached) via `Tracer`, which today only writes traces and generations. Scores make the per-prompt-version breakdown a built-in Langfuse view; no new dashboard needed if Langfuse is the dashboard.
- **Denominator:** "no response" needs the count of briefings *rendered*, which already exists (audit rows with action=brief; metric 5 in KEY_METRICS.md). Rate = ratings / rendered briefings, with up and down as shares of rendered, so ignored briefings are visible rather than hidden by a ratings-only average.
- **Panel:** `public/assets/panel.js` renders the two buttons under the summary only after the summary is rendered (not on the fact table, which is deterministic and not what is being rated); a comment textarea slides open on click; disabled state after submit; failure shows a status line above the intact summary like every other error. The buttons must not block or delay the briefing; rating is fire-and-forget from the physician's side.
- **Tests:** contract test for the new request/response schemas; unit test for the rating parser; DB-backed test for the replace-on-second-click rule; eval harness `--live` run unaffected.

**Effort:** M
**Priority:** P2
**Depends on:** None (Langfuse score write is a small addition to `Tracer`; if server-side conversation persistence lands first, share its retention policy)

### Chat adoption per patient encounter

**What:** Log which encounter each chat turn belongs to and compute, per physician per day and per week, the share of patient encounters on which the chat was used at least once (`chat bot use / patient encounters`), plus mean `ask` turns per used encounter.

**Why:** Metric 7 in `KEY_METRICS.md`. Metric 5 measures whether the panel is seen; this measures whether the conversation is chosen, per encounter, which is the production test of the PRD's rule that multi-turn must be justified by a use case.

**Context:** Future iteration, deferred 2026-09-16 (office-hours; no time before the submission gate). Numerator: the audit rows `ChatController` already writes (`EventAuditLogger::newEvent('clinical-copilot', ...)` with `action=ask`) carry user and pid but not the encounter; add `encounter_id=<id>` to the audit event string (the session encounter is already known to the controller, it is what `FactAssembler::assemble()` receives) and to the `copilot response` log line and the Langfuse trace metadata, so all three stores agree. Denominator: `form_encounter` rows with `date` on that day and `provider_id` = the physician, which is OpenEMR's own definition of an encounter. Encounters with no chart open at all count in the denominator on purpose. Query: one SQL over `log` (action LIKE 'action=ask%') joined to `form_encounter` on pid + encounter id, grouped by user and day; ship it as `tests/evals/adoption.php` (same shape as `smoke.php`) that prints the table and writes JSON, and as a Langfuse saved view keyed on the `encounter_id` metadata if the dashboard is Langfuse. Do not count the auto-rendered `brief` action or a rating (`action=rate`, see the rating entry above) as use. If server-side conversation persistence lands first, the `copilot_conversation` table keyed by (pid, encounter_id, user_id) makes the numerator a `COUNT(DISTINCT encounter_id)` with no log parsing.

**Effort:** S
**Priority:** P2
**Depends on:** None; simpler after server-side conversation persistence

### Move Langfuse to the v4 OpenTelemetry write path

**What:** Replace the `/api/public/ingestion` batch call in `LangfuseTracer` with OTLP export (or the v4 SDK path). Confirmed 2026-09-22: every ingestion response now carries the shutdown notice (non-score events rejected from 2026-11-16); the legacy read APIs are already closed to this organisation. Scheduled for Phase 9; see clinical_copilot_week2/DASHBOARD.md § Decisions, item 7.

**Why:** Langfuse Cloud reports the v3 ingestion API deprecated with v4-only write mode from 2026-11-16, and data on the v3 API is delayed about 10 minutes. Real-time dashboards need the OTel path.

**Context:** `LangfuseTracer` is a single class with a unit test asserting the payload shape; swapping the transport is contained. Keep trace id = correlation id.

**Effort:** S
**Priority:** P2
**Depends on:** None

## Completed
