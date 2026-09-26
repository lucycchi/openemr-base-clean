# Development log

Newest entry first. One entry per slice, using the template in `MIGRATION-SPEC.md`.

## 2026-09-26 — Planning phase complete (Tasks 0–18 of the docs plan)

**Branch:** `dashboard-migration`
**Status:** ready-for-commit

### Worked on
- Auth spikes against the dev stack (`API-SPIKE.md`): the browser-only public client and the backend-for-frontend.
- Seven synthetic test patients plus 34 encounters (`TEST-PATIENTS.md`, `fixtures/`).
- Inventory of the old dashboard (`INVENTORY.md`), and seven field-level module audits (`modules/`).
- Bug catalogue: 35 rows (`BUGS-MITIGATIONS.md`).
- Migration options (`MIGRATION-OPTIONS.md`), the graded defence (`PATIENT_DASHBOARD_MIGRATION.md`), this spec, and five arc files.

### Decisions
- Gate 0: keep both auth options open until Gate 3.
- Gate 1: six full audits and six light reviews; no scope cuts.
- Gate 2a: extra section is Encounter history (the recommendation was Vitals).
- Gate 2: FHIR only with safe workarounds; the header adds sex and a status derived from the death date; two medication cards split on intent; problems shown unless inactive, with resolved ones labelled; allergy risk level; never claim "No Known Allergies"; "Name unavailable" for unresolved names; the parity choices were accepted; a hidden-cards config file.
- Gate 3: Option B (backend-for-frontend) with React + TypeScript (Vite) and a Node BFF, in `patient-dashboard/`, run on the dev stack and the droplet.
- Gate 4: the defence was approved, written in the first person.

### Tests
- Document checkers: every module doc passes `check-module-doc.sh`; the catalogue passes `check-bugs.sh` (35 rows); the options, defence and test-patients docs pass `check-doc.sh`.
- Spikes: A passed through the proxy and failed directly, as expected (CORS preflight 404). B passed.

### BUGS-MITIGATIONS.md updates
- Catalogue created: BM-001 to BM-035. None resolved yet.

### Open questions / follow-ups
- BM-033 (encounter sensitivity through FHIR) was read from the code only; test it with a restricted user if time allows.
- The dev stack's prescription UI is unusable by hand (BM-021, BM-022); prescriptions for fixtures are added through `tmp/seed_rx.php`.
