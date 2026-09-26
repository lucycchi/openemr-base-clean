# ARC-04 — Encounter history (extra section)

**Status:** complete
**Estimate:** 4 h

## Goal
An Encounter history card from FHIR `Encounter`: date, reason and provider, newest first, the 20 most recent with "Show all". The parity reference is the old Visit History page (`interface/patient_file/history/encounters.php`). See `modules/extra-encounter-history.md`.

## Out of scope
The billing, insurance, issue and forms columns, interleaved documents, and opening an encounter (not ported).

## BUGS-MITIGATIONS items resolved
- BM-032 — provider without an NPI dropped from the encounter ("Name unavailable")
- BM-034 — ordering and paging (newest first, 20 plus "Show all")
- BM-035 — "1-0 of 0" empty state ("No encounters recorded")
- BM-039 — issue, forms, billing, insurance and document columns not in FHIR (approved exception)

## Stories

### Story 04-01 — Encounter history card
**Acceptance:** TP-TYPICAL shows three encounters newest first (2026-08-14 Diabetes review, Lee, Donna; 2026-03-02 Blood pressure check, Name unavailable; 2025-11-20 Annual physical, Lee, Donna); TP-LONG shows 20, then 30 after "Show all"; TP-EMPTY shows "No encounters recorded".

#### Slice 04-01-01 — Encounter mapper, hook and card
- [x] Tests first, watched failing
- [x] Implement `mappers/encounters.ts` (period.start descending; reasonCode text; provider name through Practitioner, or "Name unavailable"; page size from config, default 20) and `cards/EncounterHistoryCard.tsx`
- [x] Gates green; DEV-LOG, arc file and BM rows updated
**Acceptance tests:**
- `tests/unit/mappers/encounters.test.ts :: newest first (BM-034)`
- `tests/unit/mappers/encounters.test.ts :: missing participant shows "Name unavailable" (BM-032)`
- `tests/unit/cards/EncounterHistoryCard.test.tsx :: 20 shown, "Show all" reveals 30 for TP-LONG (BM-034)`
- `tests/unit/cards/EncounterHistoryCard.test.tsx :: empty shows "No encounters recorded" (BM-035)`
- `tests/parity/encounters.spec.ts :: TP-TYPICAL, TP-HISTORY, TP-LONG, TP-EMPTY against the Visit History page, compares date, reason and provider only, exceptions BM-032 BM-035 BM-039`
**Resolves:** BM-032, BM-034, BM-035, BM-039
**Touches:** `web/src/`

## Definition of Done for this arc
- [x] Slice ticked; BM-032, BM-034, BM-035 and BM-039 struck through
- [x] DEV-LOG has an arc-completion entry
