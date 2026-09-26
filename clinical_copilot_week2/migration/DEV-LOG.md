# Development log

Newest entry first. One entry per slice, using the template in `MIGRATION-SPEC.md`.

## 2026-09-26 — Arc 01 / Story 01-01 / Slice 01-01-01 — Scaffold patient-dashboard

**Branch:** `dashboard-migration`
**Status:** ready-for-commit

### Worked on
- Created `patient-dashboard/`: React 19 + TypeScript SPA under `web/` (Vite 8), Hono BFF under `server/` (`createApp()` in `server/app.ts`, entry `server/index.ts` on 127.0.0.1:5180), Vitest, Playwright, ESLint (typescript-eslint strict and react-hooks) and Prettier.
- The BFF serves `GET /healthz` and the built SPA from `dist/web`, with an index.html fallback for client routes. The Vite dev server (port 5181) proxies `/auth` and `/api` to the BFF.

### Decisions
- TypeScript `~6.0.3`: typescript-eslint 8.70 supports TypeScript below 6.1, and TypeScript 7 is the new native compiler.
- Playwright pinned to 1.62.1, which matches the Chromium build already cached on the host (revision 1234).
- Formatting follows the repo's `.editorconfig` (4-space indentation).

### Tests
- Unit: 1 / 1 passing (`tests/unit/server/health.test.ts`)
- E2E: 1 / 1 passing (`tests/e2e/smoke.spec.ts`)
- Lint, typecheck and Prettier: clean
- Both tests were seen failing first: missing `server/app`, and the build could not resolve `web/index.html`.

### BUGS-MITIGATIONS.md updates
- None.

### Open questions / follow-ups
- The host needed `libnss3` for Playwright's Chromium; the user installed it on 2026-09-26.
- npm 11 did not run esbuild's postinstall script; `tsx` and Vite work without it.

## 2026-09-26 — Planning phase complete (Tasks 0–18 of the docs plan)

**Branch:** `dashboard-migration`
**Status:** ready-for-commit

### Worked on
- Auth spikes against the dev stack (`API-SPIKE.md`): the browser-only public client and the backend-for-frontend.
- Seven synthetic test patients plus 34 encounters (`TEST-PATIENTS.md`, `fixtures/`).
- Inventory of the old dashboard (`INVENTORY.md`), and seven field-level module audits (`modules/`).
- Bug catalogue: 39 rows (`BUGS-MITIGATIONS.md`). BM-036 to BM-039 were added after the final branch review, to record parity exceptions the audits had found but not catalogued.
- Migration options (`MIGRATION-OPTIONS.md`), the graded defence (`PATIENT_DASHBOARD_MIGRATION.md`), this spec, and five arc files.

### Decisions
- Gate 0: keep both auth options open until Gate 3.
- Gate 1: six full audits and six light reviews; no scope cuts.
- Gate 2a: extra section is Encounter history (the recommendation was Vitals).
- Gate 2: FHIR only with safe workarounds; the header adds sex and a status derived from the death date; two medication cards split on intent; problems shown unless inactive, with resolved ones labelled; allergy risk level; never claim "No Known Allergies"; "Name unavailable" for unresolved names; the parity choices were accepted; a hidden-cards config file.
- Gate 3: Option B (backend-for-frontend) with React + TypeScript (Vite) and a Node BFF, in `patient-dashboard/`, run on the dev stack and the droplet.
- Gate 4: the defence was approved, written in the first person.

### Tests
- Document checkers: every module doc passes `check-module-doc.sh`; the catalogue passes `check-bugs.sh` (39 rows); the options, defence and test-patients docs pass `check-doc.sh`.
- Spikes: A passed through the proxy and failed directly, as expected (CORS preflight 404). B passed.

### BUGS-MITIGATIONS.md updates
- Catalogue created: BM-001 to BM-039. None resolved yet.

### Open questions / follow-ups
- BM-033 (encounter sensitivity through FHIR) was read from the code only; test it with a restricted user if time allows.
- The dev stack's prescription UI is unusable by hand (BM-021, BM-022); prescriptions for fixtures are added through `tmp/seed_rx.php`.
