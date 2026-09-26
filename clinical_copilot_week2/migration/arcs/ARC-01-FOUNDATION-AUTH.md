# ARC-01 — Foundation and auth

**Status:** in-progress
**Estimate:** 10 h

## Goal
A running `patient-dashboard/` app: the BFF logs a clinician in through OpenEMR (OAuth2 authorization code with PKCE, confidential client), holds tokens server-side, serves the SPA, and proxies allow-listed FHIR reads. The SPA has an API client with typed load errors, and the parity harness can read any field from the old dashboard. Every later arc builds on this.

## Out of scope
The actual cards (ARC-02 to ARC-04) and the droplet deploy (ARC-05).

## BUGS-MITIGATIONS items resolved
- BM-004 — another patient's request returns an empty Bundle (enforced by the patient-reference check in the API client and hooks)
- BM-029 — `_include` returns an empty Bundle (the proxy strips it; a contract test guards the plain search)

## Stories

### Story 01-01 — The app builds, runs and tests
**Acceptance:** `npm run dev` serves the SPA through the BFF, and `npm test`, `npm run lint` and `npm run typecheck` are clean.

#### Slice 01-01-01 — Scaffold patient-dashboard
- [x] Tests first (named below), watched failing
- [x] Implement: Vite React-TS app under `web/`, Hono server under `server/`, Vitest, Playwright, ESLint, Prettier, strict tsconfig
- [x] Gates green
- [x] DEV-LOG, arc file updated
**Acceptance tests:**
- `tests/unit/server/health.test.ts :: GET /healthz returns 200 {"ok":true}`
- `tests/e2e/smoke.spec.ts :: the SPA shell loads from the BFF origin`
**Touches:** `patient-dashboard/`

### Story 01-02 — A clinician can log in and out
**Acceptance:** log in at OpenEMR, land on the dashboard shell, log out, and be asked to log in again. The browser never holds an access token. *(Done: slices 01-02-01 and 01-02-02.)*

#### Slice 01-02-01 — BFF login, callback, session and logout
- [x] Tests first, watched failing
- [x] Implement `server/auth.ts` and `server/session.ts` from `spike/bff/server.mjs`: PKCE, one-use state, HttpOnly SameSite=Lax cookie (Secure over HTTPS), refresh before expiry, idle session timeout with a sweep, POST-only logout that clears the session
- [x] Gates green; DEV-LOG, arc file updated
**Acceptance tests:**
- `tests/unit/server/auth.test.ts :: callback rejects a reused or mismatched state`
- `tests/unit/server/auth.test.ts :: token response is stored server-side and never sent to the browser`
- `tests/unit/server/session.test.ts :: refresh runs when the access token is within 60 s of expiry`
- `tests/e2e/login.spec.ts :: login then logout returns to the login page`
**Touches:** `server/`, `tests/unit/server/`, `tests/e2e/`

#### Slice 01-02-02 — Allow-listed FHIR proxy
- [x] Tests first, watched failing
- [x] Implement `server/fhirProxy.ts`: GET only; allow-list Patient, AllergyIntolerance, Condition, MedicationRequest, CareTeam, Practitioner, RelatedPerson, Encounter; strip `_include` and `_revinclude`; 401 when not logged in
- [x] Gates green; DEV-LOG, arc file, BM-029 updated
**Acceptance tests:**
- `tests/unit/server/fhirProxy.test.ts :: non-allow-listed resource returns 400`
- `tests/unit/server/fhirProxy.test.ts :: _include is removed before forwarding`
- `tests/unit/server/fhirProxy.test.ts :: POST is rejected`
- `tests/e2e/contract.spec.ts :: CareTeam search for TP-TYPICAL returns exactly 1 CareTeam`
**Resolves:** BM-029
**Touches:** `server/`

### Story 01-03 — Errors never look like empty data
**Acceptance:** a failed or rejected request shows "Couldn't load <card>" with a retry option, never an empty-state message.

#### Slice 01-03-01 — API client, LoadError and the patient check
- [x] Tests first, watched failing
- [x] Implement `web/src/api/client.ts`: `Result<T, LoadError>`; 401 sends the user to login; network or 5xx becomes a LoadError; `assertBelongsTo(patientId, resources)` turns a mismatch into a LoadError
- [x] Gates green; DEV-LOG, arc file updated. BM-004 is struck through in slice 02-01-02, when the first card uses the guard.
**Acceptance tests:**
- `tests/unit/api/client.test.ts :: 401 triggers re-login`
- `tests/unit/api/client.test.ts :: fetch failure maps to LoadError`
- `tests/unit/api/client.test.ts :: resource for another patient maps to LoadError (BM-004)`
- *(moved to slice 02-01-02, which has the first card that fetches data)* `tests/e2e/errors.spec.ts :: rejected session shows the login page, not empty cards`
**Resolves:** BM-004 (guard; enforced from slice 02-01-02)
**Touches:** `web/src/api/`

### Story 01-04 — Parity can be measured
**Acceptance:** a Playwright helper returns the text, tooltip and highlight of any listed field from the old dashboard and from the new app, for any fixture key.

#### Slice 01-04-01 — Parity harness and cert trust
- [ ] Tests first, watched failing
- [ ] Implement `tests/support/oldDashboard.ts`: login at http://localhost:8300, dismiss alerts, open by pid, read cards including collapsed ones. Also `newApp.ts`, `fixtures.ts` (reads `fixtures/*.json`) and `cert.ts` (SPKI hash for `--ignore-certificate-errors-spki-list`)
- [ ] Gates green; DEV-LOG, arc file updated
**Acceptance tests:**
- `tests/parity/harness.spec.ts :: old dashboard reads TP-TYPICAL allergies as ["Penicillin (Moderate)", "Peanuts ()"]`
- `tests/parity/harness.spec.ts :: collapsed medications card items are readable`
**Touches:** `tests/support/`, `tests/parity/`

#### Slice 01-04-02 — Hidden-cards configuration
- [ ] Tests first, watched failing
- [ ] Implement `config/hidden-cards.json` (keys match `hide_dashboard_cards`: card_allergies, card_medicalproblems, card_medication, card_prescriptions, card_care_team) read by the SPA shell
- [ ] Gates green; DEV-LOG, arc file updated
**Acceptance tests:**
- `tests/unit/app/hiddenCards.test.ts :: a key in hidden-cards.json removes that card`
**Touches:** `config/`, `web/src/app/`

## Definition of Done for this arc
- [ ] All slices ticked; BM-004 and BM-029 struck through
- [ ] DEV-LOG has an arc-completion entry
