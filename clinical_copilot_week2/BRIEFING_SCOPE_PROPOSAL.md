# Briefing scope expansion: proposal for feedback

Status: APPROVED 2026-09-23 with every default in the decision tables, critic included. Implementation plan: docs/superpowers/plans/2026-09-23-briefing-scope-expansion.md

## What the briefing contains today

Every sentence the model writes must cite a fact from the list built by
`FactAssembler::assemble()`. Everything is a delta relative to the prior
visit (the most recent encounter before the open one), and each category is
capped at 50 facts.

| Source | Fact | Filter |
|---|---|---|
| form_encounter | Prior visit date and reason | Latest encounter before the open one, minus sensitivity-restricted |
| prescriptions | Active medications; new if started after the prior visit | active = 1 and no end date |
| lists (allergy) | Allergies; new since prior visit; allergy-to-drug matches | no end date |
| procedure_result | Out-of-range labs; labs that changed vs the previous value | numeric results only; new since prior visit |
| lists (medical_problem) | New problems | begun after prior visit |
| copilot_intake | Chief concern, meds, allergies, family history from intake form | uploaded since prior visit |
| copilot_document_fact | Unverified extractions; name/DOB mismatch | uploaded since prior visit |

Known gaps in the current set:

- Only 16 of the 38 LOINC codes in `contracts/loinc_map.json` have a built-in
  reference range, so 22 analytes (eGFR, ALT, AST, calcium, ferritin, B12,
  vitamin D, magnesium, MCV, and others) can never be flagged abnormal.
- The range printed on the lab PDF and the lab's own H/L flag are stored
  (`procedure_result.range`, `procedure_result.abnormal`,
  `copilot_document_fact.reference_range/abnormal_flag`) but the assembler
  ignores both. Only the built-in table is consulted.
- Non-numeric results ("positive", "reactive", "detected") are dropped by the
  labs query, so they never reach the briefing.
- Normal results are not facts at all. The model cannot answer "what was the
  potassium?" if the value was in range.
- Stopped medications and resolved problems are invisible: the queries read
  only active rows.
- No vitals. `form_vitals` is never read.
- No notes. The prior visit contributes only its date and reason.
- `FactCategory::MedicationChanged` exists and is must-surface but is never
  emitted.

## A. Reference ranges on every lab, abnormal flagging

Goal: every lab result new since the prior visit carries a reference range,
whether or not the PDF printed one, and out-of-range or lab-flagged results
are surfaced as must-surface facts.

### A1. Range table covers every mapped analyte

Expand the range table from 16 to all 38 LOINC codes in `loinc_map.json`,
and require that every future `loinc_map.json` entry has a range (a test
asserts the two files agree). Move the table from a PHP constant to
`contracts/reference_ranges.json` so the sidecar and PHP read the same
values, keyed by LOINC, with `{low, high, unit, sex?: "M"|"F", source}` per
row. Adult ranges only. Cite the source of each range in the file (each row is
a clinical coding decision, same rule as `loinc_map.json`).

Sex-specific rows for hemoglobin, hematocrit, RBC, creatinine, ferritin, uric
acid, HDL. This needs `patient_data.sex` for the patient. That is the one
demographics read this proposal adds; it is used only to pick the range row
and is never a fact.

Decision needed (A-1): sex-specific ranges, or single adult ranges for the
first cut.

### A2. Precedence when the PDF also printed a range

Three signals can exist for one result: the lab's printed H/L flag, the
lab's printed range, and the built-in range.

Proposed rule: a result is abnormal if any of the three says so. The fact
text names the range it used and where it came from:

- "Potassium 5.4 mmol/L on 2026-09-10 (above the lab's range 3.5-5.1)"
- "ALT 62 U/L on 2026-09-10 (above the standard range 7-56; no range printed on the report)"
- "TSH 5.2 m[IU]/L on 2026-09-10 (flagged H by the lab; lab's range 0.4-4.5)"

When the lab's range and the built-in range disagree and only one calls the
result abnormal, the fact says so in one clause: "within the lab's range
0.5-5.5, above the standard range 0.4-4.0". The clinician sees the
disagreement instead of one table silently winning.

Decision needed (A-2): "any signal" (more sensitive, some noise) versus "lab's
range wins when printed, built-in only fills gaps" (quieter).

### A3. Normal results become facts

New category `LabNormal` (not must-surface): one fact per result new since
the prior visit that is inside range, with the range in the text:
"Sodium 139 mmol/L on 2026-09-10 (reference range 135-145)". Rendered in the
panel as a collapsed "Normal labs since last visit" section, last in order.
The model gets them so follow-up questions about normal values are
answerable; the briefing prompt's ordering rule keeps them at the end.

`LabDelta` facts also gain the range clause so a change is read against it.

### A4. Qualitative results

Drop the numeric-only filter. Results with `result_data_type = 'S'` are
facts when the lab flagged them (`abnormal` in yes/high/low/vhigh/vlow) or
the text matches a small positive set (positive, reactive, detected,
abnormal, present): "Urine culture: positive on 2026-09-10 (flagged abnormal
by the lab)" as `LabAbnormal`. Unflagged qualitative results go to
`LabNormal`.

### A5. Critical values (optional)

`vhigh`/`vlow` lab flags, or a built-in panic column (potassium >6.0 or <2.8,
glucose <50 or >400, sodium <120 or >160, hemoglobin <7, platelets <50), become
`LabCritical`, must-surface, first in panel order and first in the prompt's
priority list.

Decision needed (A-3): include the critical tier now or later.

## B. Vitals

Source: `form_vitals` joined to `forms` (`formdir = 'vitals'`, `deleted = 0`)
for the encounter id, so the existing sensitivity filter applies. OpenEMR
stores weight in lb, height in inches, temperature in Fahrenheit; BP as two
strings. Fields used: bps/bpd, pulse, oxygen_saturation, temperature,
respiration, weight, BMI. The demo database has 33 vitals rows across the
seed patients, so the smoke test has data.

Two categories:

- `VitalAbnormal` (must-surface): a reading new since the prior visit outside
  an adult threshold table, versioned like the lab ranges. Proposed: BP
  >= 140/90, pulse < 50 or > 100, SpO2 < 94 %, temperature >= 100.4 F,
  respiration > 20, BMI >= 30 or < 18.5. Text: "Blood pressure 152/94 mmHg on
  2026-09-10 (above 140/90)".
- `VitalDelta` (not must-surface): change versus the most recent prior reading
  of the same vital, only past a threshold so every visit is not noise.
  Proposed: weight +/- 5 % or 5 lb, systolic +/- 20, BMI +/- 2. Text:
  "Weight changed from 182 lb (2026-03-02) to 171 lb (2026-09-10): down 11 lb
  (6 %)".

Adults only: if `patient_data.DOB` gives an age under 18, no `VitalAbnormal`
facts are produced (pediatric thresholds are out of scope). Latest reading
per vital per encounter is used.

Decision needed (B-1): BP threshold 140/90 (this table) or 130/80 (ACC/AHA
stage 1).

## C. Chart-state changes

- `MedicationStopped` (must-surface): prescriptions with `active = 0` or an
  `end_date`, where the end date (or `date_modified` when the end date is
  blank) is after the prior visit. Text: "Metformin 500 mg stopped 2026-09-01
  (started 2024-03-02)".
- `MedicationChanged` (already defined, currently dead): a stopped row and a
  new row for the same drug name across the prior-visit boundary become one
  fact with both doses: "Lisinopril changed from 10 mg daily to 20 mg daily
  on 2026-09-01". The two rows are then not also reported as stopped and new.
- `ProblemResolved` (not must-surface): `lists` medical_problem rows with an
  `enddate` after the prior visit, or `activity = 0` with `modifydate` after
  it. Text: "Acute bronchitis resolved 2026-08-15".
- `LabPending` (must-surface): `procedure_order` for the patient with no
  `procedure_report`, dated after the prior visit, `order_status` not in
  (cancelled, complete), excluding the module's own COPILOT-PANEL orders.
  Text: "Lipid panel ordered 2026-09-01, no result on file". Constraint: the
  demo database has 3,224 orders with no report, so the date boundary and
  status filter are not optional; without them the category would hit its cap
  on every seed patient.

## D. Prior visit assessment and plan

Source: for the prior encounter only, `form_soap` (assessment, plan) via
`forms` with `formdir = 'soap'`, and `form_clinical_notes` (description,
clinical_notes_type) via `formdir = 'clinical_notes'`, both `deleted = 0`.
The `encounters/notes` ACL is already required by the assembler; the prior
encounter has already passed the sensitivity filter. The demo database has 3
SOAP rows and no clinical notes, so seed rows are needed for the smoke test.

Categories:

- `PriorVisitPlan` (must-surface): the SOAP plan, plus clinical notes typed as
  plan or progress. The plan is what the physician most wants back ("we said
  recheck A1c in three months").
- `PriorVisitAssessment` (not must-surface): the SOAP assessment.

Free-text handling:

- One fact per field, capped at 600 characters, cut at a sentence boundary
  with a trailing "[truncated]" and the full text still in the panel's fact
  table. Multiple notes for the encounter are joined in date order before the
  cap.
- The existing `flatten()` step removes newlines and brackets, which is what
  keeps fact text from breaking the one-fact-per-line prompt format. Eval case
  06 (prompt injection inside a fact) is extended with an injection inside a
  plan note.
- PHI: fact values already reach the model and the panel, never the logs. A
  new `phi_logs` eval case puts a name, DOB and phone number in a SOAP plan
  and asserts none of it appears in the captured logger or tracer output.
- The verifier's literal check strips any number the model writes that is not
  in a cited fact. A plan with "recheck in 3 months" carries the 3, so the
  model can restate it.

## E. What the guidelines say about this chart

Goal: a briefing section that shows the guideline passages relevant to this
patient's chart, without the physician typing a question. It reuses the ask
path's retriever and citation contract; the only new piece is deriving the
queries from the chart instead of from a typed question.

### E1. Triggers: the chart decides which topics apply (deterministic, PHP)

A versioned `contracts/guideline_triggers.json`. Each rule has a matcher over
the assembled facts, a fixed query string, the corpus source it expects, and
optional exclusions. Examples against the current six-document corpus:

| Trigger | Fires on | Query | Source |
|---|---|---|---|
| lipids | LabAbnormal LDL (2089-1) or total cholesterol high; statin in active meds | statin indication and intensity when LDL is above goal | acc-aha-2018-cholesterol |
| diabetes | A1c (4548-4) >= 5.7 or fasting glucose high; problem matches diabetes or prediabetes; metformin active | A1c target and monitoring frequency in type 2 diabetes | ada-2025-standards |
| hypertension | VitalAbnormal blood pressure; problem matches hypertension | blood pressure target and treatment threshold in adults | acc-aha-2017-hypertension |
| anemia | Hemoglobin (718-7) low | evaluation of anemia in adults | anemia-adults-primary-care |
| ckd | eGFR (62238-1) low or creatinine high; problem matches CKD | CKD staging and monitoring by eGFR and albuminuria | kdigo-2024-ckd |
| screening | age 35-70, BMI >= 25, no diabetes on the problem list, no A1c on file in 3 years | screening for prediabetes and diabetes in adults | uspstf-screening |

Each fired trigger carries the ids of the facts that fired it. A test asserts
every rule names a source present in the corpus manifest, so a rule can never
point at a topic the corpus does not cover. Zero fired triggers is a valid,
deterministic result: the section reads "No guideline topic in the corpus
matches this chart". No model is involved in deciding what applies, which
keeps the routing inspectable and replayable (PRD: the supervisor must not be
a black box) and means no chart text leaves the server for this step.

The screening trigger and the exclusions below need age and sex from
`patient_data`. That is the strongest reason to include the demographics read.

### E2. Retrieval: one batch through the existing evidence-retriever

`RunRequest` gains a `brief` mode carrying the list of trigger queries. The
supervisor routes it to `evidence_retriever` once with handoff reason
`chart_triggers` (or to done with `no_triggers`), so the route log shows why
retrieval happened. `retrieve.py` runs each query through the existing BM25 +
dense + RRF + relevance floor + optional Cohere rerank, keeps the top 2 chunks
per trigger, and de-duplicates across triggers. A query that falls under the
floor returns nothing and its trigger is dropped rather than shown with the
least-bad passage.

Because the trigger queries are fixed strings, `tools/build_index.py` can
embed them once and commit the vectors alongside the corpus index. Retrieval
for this section then makes zero model calls per request; only the optional
rerank needs a key. The result is cached with the briefing, keyed on the facts
hash plus the triggers version and the corpus index version.

### E3. Presentation: quoted first, narrated later

Tier 1 (no model): a panel section "What the guidelines say about this
chart", one card per fired trigger. Card layout: "Because: LDL 165 mg/dL on
2026-09-10 (above the standard range 0-129)" with the fact chip, then the
passage quote with its title and section, click-to-source through the
existing guideline chip and source viewer. Verbatim quotes only, so there is
nothing to verify and the section works when the model is unavailable.

Tier 2 (narrated, after Tier 1 has evals): the briefing narration may add one
sentence per trigger under the same contract the ask path already enforces:
a sentence about the patient cites fact ids, a sentence about a guideline
cites the 12-character chunk id, the verifier checks every number against the
quote, and a guideline sentence is never presented as a fact about the
patient. The prompt already states that restating a cited passage is not the
model's own advice.

### E4. Applicability: the real risk

A trigger fires on a value, but a passage can describe a population the
patient is not in: the statin passage is for ages 40-75, the A1c target
differs for frail older adults, an anemia workup differs in pregnancy.

- First cut: exclusions in the trigger rule (age bounds, problem-list
  exclusions such as type 1 diabetes or pregnancy), deterministic.
- Every card carries the label "Guideline text; applicability not assessed"
  until the next step exists.
- Next step: the PRD's critic-agent extension. One boolean model call per
  card, "does the population this passage describes match these facts?",
  logged as a boolean rubric, dropping or labelling cards that fail. This is
  the same critic that "rejects uncited claims or unsafe action suggestions".

Wording rules: the section is "What the guidelines say", never
"Recommendations"; cards never say "this patient should"; when no trigger
fires nothing is shown. This keeps the section on the right side of the
safe_refusal rubric and the PRD's separation of record facts from evidence.

### E5. Tests and evals

- Isolated PHP: trigger derivation. High LDL fires lipids with the fact id;
  a normal chart fires nothing; problem-list-only fires; an exclusion
  suppresses; every rule's source exists in the manifest.
- Retrieve-mode eval cases, one per trigger query, expecting the top source
  id (same shape as cases 29-31), plus an off-corpus trigger query that must
  return zero chunks.
- Route-mode cases: brief with triggers routes to evidence_retriever once
  with reason chart_triggers; brief without triggers routes to done.
- Tier 2 only: answer-mode cases asserting guideline sentences cite a chunk
  id, numbers match the quote, and no sentence gives advice of its own.
- Panel fixture and contract updated for the new section.

### E6. Effort and order

Tier 1 about 3-4 h with Claude Code (triggers file, PHP derivation, sidecar
brief mode with precomputed query vectors, panel section, evals). Tier 2
about 2 h. Critic about 3 h. Build Tier 1 after section A, since abnormal
labs are the main trigger input.

Decisions needed:

| Id | Question | Proposed default |
|---|---|---|
| E-1 | Tier 1 only first, Tier 2 after evals? | Yes |
| E-2 | Include the demographics read for the screening trigger and age exclusions? | Yes |
| E-3 | Passages per trigger | 2 |
| E-4 | Critic agent in this scope or the next? | Next |

## Cross-cutting changes

- `FactCategory` is matched exhaustively, so each new case forces updates to
  `mustSurface()`, the assembler's truncation labels, the panel's
  `CATEGORY_LABELS` and `CATEGORY_ORDER` in `panel.js`, the panel response
  contract, `ContractsTest`, and `run.php`. PHPStan fails the build until all
  are done, which is the point.
- New panel order: critical labs, allergy hits, abnormal labs, abnormal vitals,
  pending labs, stopped/changed/new medications, new allergies, new problems,
  unverified extractions, document mismatches, intake items, prior visit plan,
  prior visit assessment, lab deltas, vital deltas, resolved problems, active
  medications, active allergies, normal labs, prior visit.
- Prompt rule 7 gets the same priority list. Add a briefing length cap of 12
  sentences ("the remaining facts are in the table beside the briefing"),
  mirroring the six-sentence cap on answers, because the fact count on the
  seed chart will roughly double.
- Facts hash: fact text changes, so every cached briefing and pre-warm receipt
  invalidates on deploy. Expected and harmless; the pre-warm cron stays off.
- Latency and cost: more facts means a longer prompt. Re-run the load
  baselines (`tests/load/run-baselines.sh`) after the change and record the
  delta in BASELINES.md.
- Seed data: add SOAP plan rows and a pending order for the two smoke-test
  demo patients in the eval seed step, cleaned up afterwards like the upload.

## Tests

- `FactAssemblerTest` (isolated): the fake chart source gains vitals,
  stopped medications, resolved problems, pending orders, and notes. One test
  per new rule: abnormal from lab flag only; abnormal from built-in range
  when the PDF printed none; lab range and built-in disagree; normal result
  is a LabNormal fact with its range; qualitative positive is abnormal; each
  vital threshold; vital delta under threshold is not a fact; stopped med;
  changed med collapses two rows; resolved problem; pending order; pending
  order before the prior visit is not a fact; note truncation at 600;
  under-18 produces no VitalAbnormal.
- `ReferenceRangesTest`: every LOINC in `loinc_map.json` has a range; every
  range has a source; sex-specific rows resolve for M, F and unknown.
- `OpenEmrChartSourceTest` (DB-backed, new): each new query against seeded
  rows, including the deleted-form and sensitivity exclusions.
- Eval cases: one invariant, one boundary and one regression case per new
  category, plus the phi_logs and injection cases above. `case-index.php
  --check` enforces the three guards. Gate baseline updated in the same
  commit.
- Selenium smoke: after upload, assert the panel shows an "Abnormal labs"
  entry with a range clause and a "Prior visit plan" section for the seeded
  patient.

## Open decisions

| Id | Question | Proposed default |
|---|---|---|
| A-1 | Sex-specific reference ranges (reads patient sex)? | Yes, for the seven analytes listed |
| A-2 | Abnormal if any signal says so, or lab's printed range wins? | Any signal, with the disagreement stated in the fact |
| A-3 | Critical-value tier now? | Now; small table, high value |
| B-1 | BP threshold 140/90 or 130/80? | 140/90 |
| B-2 | Vital delta thresholds as proposed? | Yes |
| C-1 | ProblemResolved must-surface? | No |
| D-1 | Plan must-surface, assessment not? | Yes |
| D-2 | 600-character cap per note field? | Yes |
| X-1 | 12-sentence briefing cap? | Yes |

## Effort

Roughly, with Claude Code: A 2 h, B 1.5 h, C 1.5 h, D 1.5 h, tests and eval
cases 2.5 h, seed data and smoke 1 h, baselines 0.5 h. About one working
day. Order: A first (it is the requirement you named), then C, B, D.
