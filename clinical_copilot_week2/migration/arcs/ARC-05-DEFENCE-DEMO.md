# ARC-05 — Deploy, defence and demo

**Status:** complete
**Estimate:** 6 h

## Goal
The dashboard runs next to OpenEMR on the droplet with login working, the full parity suite has been run and its results recorded in `PATIENT_DASHBOARD_MIGRATION.md`, and there's a demo walkthrough for the video.

## Out of scope
New features. Any parity failure found here is fixed in the owning arc's slice, or recorded as a documented exception with a BM row.

## BUGS-MITIGATIONS items resolved
- None new. This arc checks that every `fix in the new app` row is struck through.

## Stories

### Story 05-01 — Deployed next to OpenEMR
**Acceptance:** the droplet serves the dashboard over HTTPS, a clinician can log in through the droplet's OpenEMR, and TP-TYPICAL-equivalent data renders.

#### Slice 05-01-01 — Container and droplet deploy
- [x] Tests first: smoke test against the deployed URL, watched failing
- [x] Implement:
  - a Dockerfile (one Node image serving the SPA and the BFF)
  - a compose service next to OpenEMR
  - a confidential client registered and enabled on the droplet
  - secrets in the droplet's env, never committed
  - memory checked against the 3.9 GB budget
- [x] Gates green; DEV-LOG, arc file updated
**Acceptance tests:**
- `tests/e2e/deployed.spec.ts :: droplet login reaches the dashboard shell`
- `tests/e2e/deployed.spec.ts :: /healthz returns 200`
**Touches:** `patient-dashboard/Dockerfile`, deploy configuration

### Story 05-02 — Parity results and defence
**Acceptance:** the full parity suite is green with approved exceptions only; `PATIENT_DASHBOARD_MIGRATION.md` has a results table per section and uses the past tense for work that is done.

#### Slice 05-02-01 — Run the suite and record results
- [x] Tests first: the results-table check fails while the table is missing
- [x] Implement: run `npm run test:parity`, then write a per-section table (fields compared, matched, approved exceptions by BM id) into `## Parity evidence`; update the tense throughout; run `tools/check-bugs.sh` and confirm every `fix in the new app` row is struck through
- [x] Gates green; DEV-LOG, arc file updated
**Acceptance tests:**
- `bash clinical_copilot_week2/migration/tools/check-doc.sh PATIENT_DASHBOARD_MIGRATION.md "## Parity evidence" "| Section |"`
- `npm run test:parity` with all specs passing
**Touches:** `PATIENT_DASHBOARD_MIGRATION.md`, `clinical_copilot_week2/migration/BUGS-MITIGATIONS.md`

### Story 05-03 — Demo walkthrough
**Acceptance:** a short script that shows login, TP-TYPICAL, TP-DECEASED (status), TP-LONG (long lists), a patient switch, and one Gate 2 exception explained.

#### Slice 05-03-01 — Demo script
- [x] Tests first: `check-doc.sh` on the script's headings, watched failing
- [x] Implement `clinical_copilot_week2/migration/DEMO-WALKTHROUGH.md`
- [x] Gates green; DEV-LOG, arc file updated
**Acceptance tests:**
- `bash clinical_copilot_week2/migration/tools/check-doc.sh clinical_copilot_week2/migration/DEMO-WALKTHROUGH.md "## Login" "## Patient switch" "## Exceptions"`
**Touches:** `clinical_copilot_week2/migration/`

## Definition of Done for this arc
- [x] All slices ticked; the project Definition of Done in `MIGRATION-SPEC.md` is met
- [x] DEV-LOG has an arc-completion entry
