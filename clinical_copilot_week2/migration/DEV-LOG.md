# Development log

Newest entry first. One entry per slice, using the template in `MIGRATION-SPEC.md`.

## 2026-09-26 — Arc 01 / Story 01-03 / Slice 01-03-01 — API client, LoadError and the patient check

**Branch:** `dashboard-migration`
**Status:** ready-for-commit

### Worked on
- `web/src/api/client.ts`:
  - `createApiClient()` with `getResource` and `getBundle`, returning `Result<T>`
  - 401 calls `onUnauthenticated` (by default it goes to `/auth/login`)
  - a thrown fetch becomes `network`, and any other non-2xx becomes `http` with its status
  - a non-Bundle response or an OperationOutcome becomes `invalid-response`
- `assertBelongsTo(patientId, resources)`: a mismatched or missing patient reference becomes a `wrong-patient` LoadError (BM-004 guard).

### Decisions
- Moved the E2E "rejected session shows the login page, not empty cards" to slice 02-01-02: no card fetches data before the header exists, so there's nothing to show empty yet. BM-004 is struck through there, when the guard is first used.
- Reworded BM-004's mitigation for the chosen backend-for-frontend (a deferred minor from the final review).

### Tests
- Unit: 26 / 26 passing (the client adds 9)
- Lint, typecheck and Prettier: clean
- Seen failing first: the client tests, with the module missing.

### BUGS-MITIGATIONS.md updates
- BM-004: mitigation reworded; not yet struck through (see Decisions).

### Open questions / follow-ups
- None.

## 2026-09-26 — Arc 01 / Story 01-02 / Slice 01-02-02 — Allow-listed FHIR proxy

**Branch:** `dashboard-migration`
**Status:** ready-for-commit

### Worked on
- `server/fhirProxy.ts`, mounted at `/api/fhir/*`:
  - GET only (anything else gets 405)
  - paths must be `Resource` or `Resource/id` with an allow-listed type (Patient, AllergyIntolerance, Condition, MedicationRequest, CareTeam, Practitioner, RelatedPerson, Encounter), otherwise 400
  - 401 without a logged-in session
  - the token is refreshed through `ensureFreshToken`
  - `_include` and `_revinclude` are stripped
  - 20 s timeout, 502 on upstream failure
  - `cache-control: no-store`
- Test support: `tests/support/login.ts` (real OpenEMR login) and `tests/support/fixtures.ts` (fixture ids). `login.spec.ts` now uses the shared login helper.

### Decisions
- Dot-segment traversal (plain or `%2e`) is resolved by URL parsing before routing and lands outside `/api/fhir` (404). The test asserts "never forwarded, never 2xx" rather than a specific 400.
- Playwright runs with one worker: two OAuth logins for the same dev user at the same moment made OpenEMR reject one token exchange (HTTP 400). Serial runs are stable (3 of 3).

### Tests
- Unit: 17 / 17 passing (the proxy adds 6)
- E2E: 3 / 3 passing (smoke, login and logout, CareTeam contract)
- Lint, typecheck and Prettier: clean
- Seen failing first: all 6 proxy unit tests before `fhirProxy.ts` existed. The contract E2E was proven by turning stripping off: `_include` returned an empty Bundle (BM-029), so the test failed, then passed once stripping was restored.

### BUGS-MITIGATIONS.md updates
- BM-029 (`_include` empty Bundle): resolved by stripping `_include` and `_revinclude` in the proxy, with a contract test.

### Open questions / follow-ups
- None.

## 2026-09-26 — Arc 01 / Story 01-02 / Slice 01-02-01 — BFF login, callback, session and logout

**Branch:** `dashboard-migration`
**Status:** ready-for-commit

### Worked on
- `server/oauth.ts`: the OpenEMR token client (authorization code with PKCE, refresh; HTTP Basic client auth; 15 s timeout; validates the token response).
- `server/session.ts`: in-memory `SessionStore` with random ids, a sliding idle timeout, `sweep()` for abandoned logins, and `ensureFreshToken()`, which refreshes within 60 s of expiry.
- `server/auth.ts`: `GET /auth/login` (a new session per attempt, PKCE S256, `aud` set), `GET /auth/callback` (one-use state), `POST /auth/logout` (204, cookie cleared) and `GET /auth/me`. The cookie `pd_sid` is HttpOnly and SameSite=Lax, plus Secure when `PUBLIC_URL` is https.
- `server/config.ts`: fails fast on missing environment. The app's own confidential client, "Patient Dashboard Spike (app)", is registered with redirect `http://localhost:5180/auth/callback` (the `app` kind added to `spike/register-client.mjs`) and enabled on the dev database. Its id and secret are in the gitignored `patient-dashboard/.env`, and the dev certificate is in the gitignored `patient-dashboard/certs/`.
- SPA shell: "Log in with OpenEMR" when signed out, "Log out" when signed in.

### Decisions
- Included the final review's deferred point for this stage: Secure cookie over HTTPS, idle session timeout with a sweep, and POST-only logout.
- A new session id for every login attempt, so an id set before login is never reused after it.
- Playwright uses `http://localhost:5180` so the cookie host matches the OAuth redirect, and checks health on 127.0.0.1 because the BFF binds IPv4.

### Tests
- Unit: 11 / 11 passing (health 1, auth 5, session 5)
- E2E: 2 / 2 passing, including a real OpenEMR login and logout through the consent page, with Chromium trusting only the dev certificate by SPKI hash
- Lint, typecheck and Prettier: clean
- Seen failing first: the unit tests on the missing modules. `sweep` and the login E2E were written alongside their code, so each was proven by breaking the code on purpose (a stubbed sweep, a no-op logout), watching the test fail, then restoring.

### BUGS-MITIGATIONS.md updates
- None (BM-029 and BM-004 are in slices 01-02-02 and 01-03-01).

### Open questions / follow-ups
- Sessions are in memory, so a BFF restart logs everyone out. That's acceptable for the demo; a shared store would be needed for more than one instance.

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
