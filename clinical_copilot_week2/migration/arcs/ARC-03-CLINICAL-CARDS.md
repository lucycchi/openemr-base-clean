# ARC-03 — Clinical cards

**Status:** complete
**Estimate:** 14 h

## Goal
The Allergies, Problem List, Medications, Prescriptions and Care Team cards, each fed by FHIR, read-only, with parity to the old dashboard except the approved Gate 2 exceptions. Each slice is vertical: mapper, hook, card, parity spec and error state. The per-card specs are `modules/allergies.md`, `problem-list.md`, `medications.md`, `prescriptions.md` and `care-team.md`.

## Out of scope
Edit controls (none are ported) and the encounter section (ARC-04).

## BUGS-MITIGATIONS items resolved
- BM-009 — narrative is rendered as markup (read as text only)
- BM-010 — uncoded allergy name is only in the narrative
- BM-011 — severity collapsed to criticality (show risk level)
- BM-012 — inferred "No Known Allergies" and "None" (never claimed)
- BM-013 — Edit shown without a write check (no edit controls)
- BM-014 — severe allergies not sorted first (keep entry order, highlight high)
- BM-015 — "Peanuts ()" (brackets omitted)
- BM-016 — future end-date allergy (hidden, as FHIR does)
- BM-017 — first-occurrence problems reported as resolved (show all not inactive, labelled)
- BM-018 — sort order (oldest start date first)
- BM-019 — list rows and prescriptions indistinguishable (split on intent, with a caveat)
- BM-020 — linked list row hidden by the UNION (shown once, as the prescription)
- BM-023 — "Filled" column shows date added (labelled "Added")
- BM-024 — empty table when every prescription is discontinued ("No active prescriptions")
- BM-028 — care-team names unresolvable ("Name unavailable")
- BM-030 — empty care team shows bare headers ("No care team recorded")
- BM-031 — default role code 407542009 (shown as blank)
- BM-036 — medication order not reproducible from FHIR (FHIR order kept; prescriptions by authoredOn descending)
- BM-037 — care-team since date, status and note not in FHIR (approved exception)
- BM-038 — prescription Details not in FHIR (approved exception)

## Stories

### Story 03-01 — Allergies
**Acceptance:** TP-TYPICAL shows Penicillin (Low risk, reaction Hives) and Peanuts; TP-NKA and TP-EMPTY show "No allergies recorded"; TP-HISTORY shows none; TP-LONG shows 25; TP-ESCAPING shows "Latex x".

#### Slice 03-01-01 — Allergies card
- [x] Tests first, watched failing
- [x] Implement `mappers/allergies.ts` (name from coding display, else narrative textContent, else "Unnamed allergy"; clinicalStatus active only; criticality label; high highlighted; entry order), a shared `hooks/useBundleCard.ts` instead of a per-card hook, and `cards/AllergiesCard.tsx`
- [x] Gates green; DEV-LOG, arc file and BM rows updated (BM-012 and BM-013 are struck through after story 03-03, because they also cover the problem and medication cards)
**Acceptance tests:**
- `tests/unit/mappers/allergies.test.ts :: uncoded name comes from narrative text (BM-010)`
- `tests/unit/mappers/allergies.test.ts :: narrative markup is text, never HTML (BM-009, TP-ESCAPING)`
- `tests/unit/mappers/allergies.test.ts :: moderate maps to "Low risk", high is highlighted (BM-011)`
- `tests/unit/mappers/allergies.test.ts :: inactive entries are hidden (BM-016)`
- `tests/unit/mappers/allergies.test.ts :: no brackets without a risk level (BM-015)`
- `tests/unit/mappers/allergies.test.ts :: entry order is kept (BM-014)`
- `tests/parity/allergies.spec.ts :: all fixtures, exceptions BM-009 BM-011 BM-012 BM-015`
- `tests/e2e/errors.spec.ts :: allergies card shows "Couldn't load" on API failure`
**Resolves:** BM-009, BM-010, BM-011, BM-012, BM-013, BM-014, BM-015, BM-016
**Touches:** `web/src/mappers/`, `hooks/`, `cards/`, `tests/`

### Story 03-02 — Problem list
**Acceptance:** TP-TYPICAL shows all three problems with Hyperlipidaemia labelled "resolved (per FHIR)" and hypertension "recurrence"; TP-LONG shows 60, starting with problem 60.

#### Slice 03-02-01 — Problem list card
- [x] Tests first, watched failing
- [x] Implement `mappers/problems.ts` (category problem-list-item; exclude inactive only; label resolved and recurrence; sort by onset ascending, missing onset first), the shared hook with `&category=problem-list-item`, and `cards/ProblemListCard.tsx`
- [x] Gates green; DEV-LOG, arc file and BM rows updated
**Acceptance tests:**
- `tests/unit/mappers/problems.test.ts :: resolved-per-FHIR problems are shown and labelled (BM-017)`
- `tests/unit/mappers/problems.test.ts :: inactive problems are hidden (TP-HISTORY)`
- `tests/unit/mappers/problems.test.ts :: TP-LONG order starts at "Long-list problem 60" (BM-018)`
- `tests/parity/problems.spec.ts :: all fixtures, exceptions BM-012 BM-017`
**Resolves:** BM-017, BM-018
**Touches:** `web/src/`

### Story 03-03 — Medications and Prescriptions
**Acceptance:** one MedicationRequest fetch feeds both cards. Medications shows intent=plan, active entries (TP-TYPICAL: Metformin, Lisinopril). Prescriptions shows intent=order, active entries (TP-TYPICAL: Amlodipine, Omeprazole, and Atorvastatin, which is a list row marked Order), with a visible caveat note. TP-HISTORY shows "No active prescriptions".

#### Slice 03-03-01 — Shared medication mapper and Medications card
- [x] Tests first, watched failing
- [x] Implement `mappers/medications.ts` (split on intent; status active; dosageInstruction text; Medications keep FHIR response order; Prescriptions sorted by authoredOn descending) and `cards/MedicationsCard.tsx` with the caveat note
- [x] Gates green; DEV-LOG, arc file and BM rows updated
**Acceptance tests:**
- `tests/unit/mappers/medications.test.ts :: plan goes to Medications, order goes to Prescriptions (BM-019)`
- `tests/unit/mappers/medications.test.ts :: linked Amlodipine appears once, under Prescriptions (BM-020)`
- `tests/unit/mappers/medications.test.ts :: TP-LONG shows all 60 in FHIR order (BM-036)`
- `tests/parity/medications.spec.ts :: all fixtures, compares the set of entries not their order, exceptions BM-012 BM-019 BM-020 BM-036`
**Resolves:** BM-019, BM-020, BM-036
**Touches:** `web/src/`

#### Slice 03-03-02 — Prescriptions card
- [x] Tests first, watched failing
- [x] Implement `cards/PrescriptionsCard.tsx`: drug, details (dosageInstruction text or blank), quantity, refills, "Added" (authoredOn, local date); sorted by authoredOn descending; "No active prescriptions" when none are active
- [x] Gates green; DEV-LOG, arc file and BM rows updated
**Acceptance tests:**
- `tests/unit/cards/PrescriptionsCard.test.tsx :: column label is "Added" (BM-023)`
- `tests/unit/cards/PrescriptionsCard.test.tsx :: all-discontinued shows "No active prescriptions" (BM-024)`
- `tests/unit/cards/PrescriptionsCard.test.tsx :: Details is blank when dosageInstruction has no text (BM-038)`
- `tests/parity/prescriptions.spec.ts :: all fixtures, exceptions BM-023 BM-024 BM-038`
**Resolves:** BM-023, BM-024, BM-038
**Touches:** `web/src/`

### Story 03-04 — Care Team
**Acceptance:** TP-TYPICAL shows team "practitioner" (Active) with a Provider "Name unavailable" (Nurse Practitioner, since 2026-09-26) and a Related Person "Name unavailable"; TP-EMPTY shows "No care team recorded".

#### Slice 03-04-01 — Care Team card
- [x] Tests first, watched failing
- [x] Implement `mappers/careTeam.ts` (resolve Practitioner and RelatedPerson references with one read each; a 404 becomes "Name unavailable"; blank role for a bare 407542009) and `cards/CareTeamCard.tsx`
- [x] Gates green; DEV-LOG, arc file and BM rows updated
**Acceptance tests:**
- `tests/unit/mappers/careTeam.test.ts :: unresolved member shows "Name unavailable" and is kept (BM-028)`
- `tests/unit/mappers/careTeam.test.ts :: bare 407542009 role shows blank (BM-031)`
- `tests/unit/cards/CareTeamCard.test.tsx :: empty shows "No care team recorded" (BM-030)`
- `tests/unit/mappers/careTeam.test.ts :: since is shown only when participant.period.start exists (BM-037)`
- `tests/parity/careteam.spec.ts :: TP-TYPICAL and TP-EMPTY, exceptions BM-028 BM-030 BM-037`
**Resolves:** BM-028, BM-030, BM-031, BM-037
**Touches:** `web/src/`

### Story 03-05 — Parity review fixes (Codex, 2026-09-26)
**Acceptance:** every finding of the Codex parity review is fixed test-first or recorded as a user decision; the seeded gaps (`fixtures/seed-parity-gaps.php`) fail parity before the fix and pass after.

#### Slice 03-05-01 — Fixes from the Codex parity review
- [x] Tests first, watched failing (seeded data turned problems, prescriptions and care-team parity red; new unit and E2E tests red)
- [x] Implement: visit-linked problems (BM-043); end-dated prescriptions and refills "Not available" (BM-044, BM-041); care-team facility names (BM-045); the Bootstrap stylesheet; encounter provider from the primary performer only and same-day order by time (the Arc 04 card); settings failure shows an error; parity and switch test gaps
- [x] Gates green; DEV-LOG, arc file and BM rows updated
**Follow-up:** BM-044 for the Medications card was resolved in slice 03-05-02.
**Resolves:** BM-041, BM-043, BM-045 (BM-042 recorded as a user decision)
**Touches:** `web/src/`, `server/fhirProxy.ts`, `tests/`

#### Slice 03-05-02 — Medication list end dates from the Standard REST API
- [x] Tests first, watched failing (seeded Lisinopril end date turned medication parity red; route, parser, mapper and hook tests red)
- [x] Implement: BFF route `/api/medication-end-dates` (patient uuid to pid, then the medication list; uuid, end date and outcome only; every row checked against the patient); `getJson` on the API client; `useMedicationCards`; the old filterActiveIssues rule for list rows on both medication cards; three extra scopes on the app client
- [x] Gates green; DEV-LOG, arc file and BM rows updated
**Resolves:** BM-044 (user decision 2026-09-26: option 3, read the end date from the Standard REST API); records BM-046
**Touches:** `server/medicationEndDates.ts`, `web/src/api/`, `web/src/hooks/useMedicationCards.ts`, `web/src/mappers/medications.ts`, `spike/register-client.mjs`

#### Slice 03-05-03 — Fixes from the Fable parity review
- [x] Tests first, watched failing:
  - encounter parity went red on the seeded same-day visits
  - allergy parity went red on the seeded resolved and future-ended allergies
  - the clinician E2E went red with user-token name reads
  - every new unit test was red first
- [x] Implement:
  - server-only names client (`server/systemToken.ts`, `/api/display-names`) (BM-048)
  - allergy list dates from the Standard REST API (`/api/list-dates?list=allergy`) (BM-016, BM-047)
  - same-day visit order
  - dead refresh token logs out
  - site date format
  - `DISABLE_PRESCRIPTIONS`
  - problem merge key includes codes
  - proxy requires a patient on searches
  - encounter parity requires resolvable names
- [x] Gates green; DEV-LOG, arc file and BM rows updated
**Resolves:** BM-016, BM-047, BM-048; records BM-049 and BM-050
**Touches:** `server/`, `web/src/`, `tests/`, `spike/register-client.mjs`, `fixtures/seed-parity-gaps.php`

## Definition of Done for this arc
- [x] All slices ticked; every BM row listed above struck through
- [x] DEV-LOG has an arc-completion entry
