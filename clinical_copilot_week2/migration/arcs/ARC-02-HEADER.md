# ARC-02 — Header

**Status:** planned
**Estimate:** 5 h

## Goal
The persistent patient header: name, MRN, date of birth with age (or age at death), sex, and a status of "Deceased (date)" or "Active", from FHIR `Patient`. Also patient switching, so that nothing from the previous patient is ever shown. See `modules/header.md` and the Gate 2 header decision.

## Out of scope
The photo, the encounter selector and the page-heading action buttons (not ported).

## BUGS-MITIGATIONS items resolved
- BM-005 — Patient.active is always true (status comes from deceasedDateTime)
- BM-007 — age computed server-side (ported rules in one pure function)

## Stories

### Story 02-01 — The header shows who the patient is
**Acceptance:** for every fixture, the header matches the old identity bar on name, MRN, DOB and age, and adds sex and status.

#### Slice 02-01-01 — Age rules
- [ ] Tests first, watched failing
- [ ] Implement `web/src/mappers/age.ts`, a port of `PatientService::getPatientAge` and `getPatientAgeDisplay` (months under 2 years; `age_display_format` and `age_display_limit` from config)
- [ ] Gates green; DEV-LOG, arc file, BM-007 updated
**Acceptance tests:**
- `tests/unit/mappers/age.test.ts :: 68 for 1958-03-14 on 2026-09-26`
- `tests/unit/mappers/age.test.ts :: months under 2 years`
- `tests/unit/mappers/age.test.ts :: the day before a birthday`
- `tests/unit/mappers/age.test.ts :: leap-day birthday`
- `tests/unit/mappers/age.test.ts :: age at death 93 for TP-DECEASED`
**Resolves:** BM-007
**Touches:** `web/src/mappers/`

#### Slice 02-01-02 — Header mapper, hook and card
- [ ] Tests first, watched failing
- [ ] Implement `mappers/header.ts` (name from official name, MRN from identifier type PT, sex from gender, status from deceasedDateTime, never Patient.active), plus `hooks/usePatient.ts` and `cards/PatientHeader.tsx`, rendering the name as text
- [ ] Gates green; DEV-LOG, arc file, BM-005 updated
**Acceptance tests:**
- `tests/unit/mappers/header.test.ts :: TP-DECEASED status is "Deceased (2025-11-02)" although Patient.active is true`
- `tests/unit/mappers/header.test.ts :: TP-TYPICAL status is "Active"`
- `tests/unit/mappers/header.test.ts :: TP-ESCAPING name renders O'Brien-Núñez as text`
- `tests/parity/header.spec.ts :: name, MRN, DOB, age match the old identity bar for all fixtures`
**Resolves:** BM-005
**Touches:** `web/src/mappers/`, `hooks/`, `cards/`

### Story 02-02 — Switching patients is safe
**Acceptance:** after switching from TP-TYPICAL to TP-HISTORY, no card shows TP-TYPICAL data, even for a moment, and every resource on screen references the header patient.

#### Slice 02-02-01 — Patient switch
- [ ] Tests first, watched failing
- [ ] Implement a patient route (`/patient/:fhirId`) that resets all card state on change, plus a simple patient picker (FHIR `Patient` search by name)
- [ ] Gates green; DEV-LOG, arc file updated
**Acceptance tests:**
- `tests/e2e/switch.spec.ts :: switching patients clears every card before new data arrives`
- `tests/e2e/switch.spec.ts :: every rendered resource references the header patient`
**Touches:** `web/src/app/`, `tests/e2e/`

## Definition of Done for this arc
- [ ] All slices ticked; BM-005 and BM-007 struck through
- [ ] DEV-LOG has an arc-completion entry
