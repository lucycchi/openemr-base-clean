# ARC-06 — Editing: prescriptions in the dashboard, everything else in OpenEMR

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Status:** complete (2026-09-27)
**Estimate:** 8 h

**Goal:** Clinicians can add, change and discontinue prescriptions from the dashboard. Every other card has an "Edit in OpenEMR" button, because OpenEMR's write API cannot record those cards' clinical fields.

**Architecture:**
- **Server side:** writes go through new BFF routes (`server/prescriptionWrites.ts`, `server/openemrLink.ts`). They:
  - check each request's origin and body
  - confirm that the target record belongs to the patient on screen
  - call OpenEMR's Standard REST API with the signed-in user's own token
- **Page side:** the Prescriptions card gets:
  - an Add button in its header, as the old card's pencil sits in the header
  - Change and Discontinue buttons on each row
  - one form, used for both adding and changing
- **After a write:** the card data reloads through a `revision` counter on the patient view, so the old list stays on screen until the new one arrives.

**Tech Stack:** React 19 + TypeScript (Vite), Hono BFF, Vitest, Playwright, OpenEMR Standard REST API.

**Spec:** the "Decisions (the spec)" section below. It records the user's decisions of 2026-09-27 and the research they rest on. `PLANNING-BRIEF.md` and `MIGRATION-SPEC.md` still apply.

## Decisions (the spec)

These were agreed with the user on 2026-09-27, after three research reports on OpenEMR's write API.

1. **Prescriptions are edited in the dashboard: add, change, discontinue.**
   - *Change* adds the corrected prescription first, then discontinues the old one, because the API has no prescription update route. If the discontinue fails, both show, with a clear error. Showing none would be the unsafe outcome.
   - *Discontinue* is OpenEMR's soft delete (`active = 0`), the same as unticking "active" on the old form. It always asks for confirmation first.
2. **No prescriber number.** The API only accepts `provider_id`, OpenEMR's numeric user id, and the only routes that reveal it are admin-only (`/api/user`, `/api/practitioner`). So the dashboard leaves `provider_id` empty.
   - The form has an optional **Prescriber** box. It starts filled with the signed-in user's name when the names client can resolve it.
   - The typed text is saved in the prescription's `note` as `Prescriber: <text>`. The note shows in OpenEMR's prescription list and in FHIR `MedicationRequest.note`.
   - The defence says plainly that OpenEMR's structured prescriber field stays empty and that the typed name is not checked.
3. **Allergies, Medical Problems, Medications and Care Team get an "Edit in OpenEMR" button.**
   - **Why:** OpenEMR's write API cannot record:
     - an allergy's reaction, severity, outcome or verification
     - a problem's comments, outcome or verification
     - a medication's dosage instructions (it also gives new medications no uuid)
     - any care-team change

     Editing those cards in the dashboard would save incomplete clinical records.
   - **What the button does:** it opens OpenEMR's chart page for the patient (`demographics.php?set_pid=<pid>`) in a new tab with `noopener`.
   - **On-screen note, short:** "Changes are made in OpenEMR. Opens in a new tab; you may need to sign in, then click again."
   - **In the defence:** the full reasoning, including that a backend change (a custom module adding write routes) is a *possible follow-up*, not a promise.
4. **Nothing in the dashboard permanently deletes anything.** The API's allergy, problem and medication deletes are hard deletes that only superusers can do in the old UI (`deleter.php:282`), so the dashboard never calls them.
5. **Permissions.** The API checks `patients/med` for prescription writes and ignores `patients/rx`. In a default install, the same groups can write prescriptions as before: Administrators, Physicians, Clinicians and Emergency Login (`Installer.class.php:1095-1421`). No site setting is added. The defence records that a site which removes `patients/rx` but keeps `patients/med` would still allow prescription writes through the API.
6. **Defaults the old form sets, which the BFF sets too** (`Prescription.class.php:225-275`):
   - `date_added` and `date_modified` = the page's local date and time
   - `start_date` = the form's start date, today by default
   - `active` = 1, `per_refill` = 0
   - `request_intent` = `order` / `Order`
   - `usage_category` = `outpatient` / `Outpatient`

## Global Constraints

- The OpenEMR backend is not modified. Only its settings and API clients may change, and every such change is written down.
- Never commit secrets: `.env`, `certs/`, `spike/.env.local`, `spike/.env.droplet`.
- Never disable TLS verification.
- Every new unit test is written first and watched failing. Every browser test is proven red by breaking the code.
- Browser tests run serially (`workers: 1`) against the development-easy stack. Parity fixtures (TP-TYPICAL, TP-EMPTY, TP-NKA, TP-HISTORY, TP-DECEASED, TP-LONG, TP-ESCAPING) must never be written to. Write tests use the new TP-RXEDIT patient only.
- **Commits:** Claude commits and the user merges. Stage files by explicit path only, because another session shares this checkout. Use Conventional Commits and end each message with `Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>`.
- **Before touching the dev stack or the droplet:** ask the peer session (`uds:/run/user/1000/cc-socks/45833.sock`) whether a load test is running. Tell it before any droplet deploy.
- **Plain-English comments** for readers who do not read TypeScript, matching the comment pass (commit 336768d).
- **OpenEMR response quirks** the code must handle:
  - Prescription routes return real status codes: 201, 200, 400 and 500.
  - `GET /api/patient/:uuid` wraps the record in `data`, and `pid` may arrive as a string.

## Review Focus

1. **Double submit.** Clicking Save twice, or pressing Enter then clicking, must create one prescription. Pinned in Task 6: the Save button is disabled while saving.
2. **Change half-fails.** The new prescription is added but the old one cannot be discontinued. The user sees both, with an error naming the old one. Pinned in Task 3: `replace` answers 207 with `{ added, discontinued: false }`.
3. **Forged or stale ids.** A prescription uuid from another patient, or a medication-list entry's uuid (`source_table = 'lists'`), must never be discontinued. Pinned in Task 3: 404 without calling DELETE.
4. **Patient switched while a save is in flight.** The answer must not reload or change the new patient's view. Pinned in Task 7: the form closes and the result is ignored when the patient changes.
5. **Cross-site form posts.** A page on another site must not be able to trigger a write. Pinned in Task 3: writes need `Content-Type: application/json` and an `Origin` equal to `PUBLIC_URL`, otherwise 403.

---

## File structure

| File | Responsibility |
|---|---|
| `server/writeGuard.ts` (create) | The shared checks for every write: signed in, same origin, a JSON body, and a fresh token |
| `server/patientLookup.ts` (create) | Patient uuid → numeric pid through `GET /api/patient/:uuid`, moved out of `listDates.ts` so it can be shared |
| `server/prescriptionWrites.ts` (create) | `POST /api/prescriptions`, `POST /api/prescriptions/:uuid/discontinue`, `POST /api/prescriptions/:uuid/replace` |
| `server/prescriptionInput.ts` (create) | Parses and validates the form body into a typed `PrescriptionInput`, and builds OpenEMR's insert body |
| `server/openemrLink.ts` (create) | `GET /openemr/patient/:uuid` → 302 to OpenEMR's chart page for that patient |
| `server/auth.ts` (modify) | `/auth/me` also returns `userId`, for the prescriber prefill |
| `server/app.ts`, `server/index.ts`, `server/config.ts` (modify) | Wiring; `OEMR_PUBLIC_URL`, which defaults to `OEMR_BASE` |
| `web/src/api/client.ts` (modify) | `sendJson(method, path, body)` for the BFF's write routes |
| `web/src/api/prescriptionWrites.ts` (create) | `addPrescription`, `discontinuePrescription`, `replacePrescription` |
| `web/src/cards/CardFrame.tsx` (modify) | An `actions` slot at the right of the title bar, as in the old `card_base.html.twig` |
| `web/src/cards/PrescriptionForm.tsx` (create) | The add/change form |
| `web/src/cards/PrescriptionsCard.tsx` (modify) | The Add button, and Change and Discontinue on each row |
| `web/src/cards/EditInOpenEmr.tsx` (create) | The "Edit in OpenEMR" link and its note |
| `web/src/hooks/useBundleCard.ts`, `useMedicationCards.ts` (modify) | A `revision` input, so a card reloads after a write |
| `web/src/app/App.tsx` (modify) | Holds `revision`, and passes the reload and the prescriber default down |
| `clinical_copilot_week2/migration/fixtures/seed-rxedit.mjs` (create) | Creates TP-RXEDIT if it is missing, and records it in the fixture-ids file |
| `clinical_copilot_week2/migration/spike/register-client.mjs` (modify) | The `app` client also asks for `user/prescription.crds` |

---

## Stories

### Story 06-01 — The dashboard may write prescriptions

**Acceptance:** after re-registering, the dev app client's token carries `user/prescription.c`, `.r` and `.d`. The test patient TP-RXEDIT exists on the dev stack.

#### Task 1: Client scope and the write-test patient

**Files:**
- Modify: `clinical_copilot_week2/migration/spike/register-client.mjs:59-60`
- Create: `clinical_copilot_week2/migration/fixtures/seed-rxedit.mjs`
- Modify: `patient-dashboard/tests/support/fixtures.ts:3-4`
- Modify: `patient-dashboard/.env` (gitignored; not committed)

**Interfaces:**
- Produces: `fixture('TP-RXEDIT')` → `{ pid, puuid, fhirId }`; an app client with `user/prescription.crds`

- [x] **Step 1: Add the scope to the `app` client kind**

```js
    scope: ['openid', 'fhirUser', 'offline_access', 'api:fhir', ...READ.map((r) => `user/${r}.rs`),
      'api:oemr', 'user/patient.rs', 'user/medication.rs', 'user/allergy.rs', 'user/medical_problem.rs',
      // Prescriptions are added and discontinued from the dashboard (ARC-06); OpenEMR has no prescription update.
      'user/prescription.crds'],
```

- [x] **Step 2: Re-register the dev app client and enable it**

Ask the peer session first. Then run:

```bash
cd clinical_copilot_week2/migration/spike
NODE_EXTRA_CA_CERTS=../../../patient-dashboard/certs/dev-cert.pem node register-client.mjs app --replace
docker compose -f ../../../docker/development-easy/docker-compose.yml exec -T mysql \
  mariadb -uopenemr -popenemr openemr -e "UPDATE oauth_clients SET is_enabled = 1 WHERE client_id = '<APP_CLIENT_ID from .env.local>'"
```

Copy `APP_CLIENT_ID`, `APP_CLIENT_SECRET` and `APP_SCOPE` into `patient-dashboard/.env` as `OEMR_CLIENT_ID`, `OEMR_CLIENT_SECRET` and `OEMR_SCOPE`. Disable the old client under Admin > System > API Clients.

- [x] **Step 3: Write `seed-rxedit.mjs`**

It finds or creates "Rita RxEdit" (DOB 1970-01-01, Female) through the seed client, and adds `TP-RXEDIT` to the fixture-ids file. Reuse `readEnv`, `fetchText` and `OEMR_BASE` from `../spike/lib.mjs`, the same way `seed.mjs` does.

```js
import { readFileSync, writeFileSync } from 'node:fs';
import { readEnv, fetchText, OEMR_BASE } from '../spike/lib.mjs';

// Adds the one patient the write tests may change (ARC-06). Safe to re-run: an existing Rita RxEdit is reused.
const FILE = new URL(process.env.FIXTURE_IDS_FILE ?? 'fixture-ids.json', import.meta.url);
const ids = JSON.parse(readFileSync(FILE, 'utf8'));
const env = readEnv();

// The same password grant seed.mjs uses (seed.mjs:75-94), with the seed client.
const tokenRes = await fetchText(`${OEMR_BASE}/oauth2/default/token`, {
  method: 'POST',
  headers: {
    'Content-Type': 'application/x-www-form-urlencoded',
    Authorization: 'Basic ' + Buffer.from(`${env.SEED_CLIENT_ID}:${env.SEED_CLIENT_SECRET}`).toString('base64'),
  },
  body: new URLSearchParams({
    grant_type: 'password', client_id: env.SEED_CLIENT_ID, user_role: 'users',
    username: env.OEMR_USER, password: env.OEMR_PASS, scope: env.SEED_SCOPE,
  }),
});
if (tokenRes.status !== 200 || typeof tokenRes.json?.access_token !== 'string') {
  console.error(`FAIL: password grant returned HTTP ${tokenRes.status}`);
  process.exit(1);
}
const headers = { Authorization: `Bearer ${tokenRes.json.access_token}`, 'Content-Type': 'application/json', Accept: 'application/json' };

const found = await fetchText(`${OEMR_BASE}/apis/default/api/patient?fname=Rita&lname=RxEdit`, { headers });
let record = (found.json?.data ?? [])[0];
if (record === undefined) {
  const created = await fetchText(`${OEMR_BASE}/apis/default/api/patient`, {
    method: 'POST', headers,
    body: JSON.stringify({ fname: 'Rita', lname: 'RxEdit', DOB: '1970-01-01', sex: 'Female' }),
  });
  if (created.status < 200 || created.status >= 300) {
    console.error(`FAIL: creating the patient returned HTTP ${created.status}`);
    process.exit(1);
  }
  record = created.json.data;
}
ids.patients['TP-RXEDIT'] = { pid: Number(record.pid), puuid: record.uuid, fhirId: record.uuid, created: {} };
writeFileSync(FILE, `${JSON.stringify(ids, null, 2)}\n`);
console.log(`TP-RXEDIT is pid ${record.pid}`);
```

If `POST /api/patient` answers with only `{ pid }` or only `{ uuid }` in `data`, fetch `GET /api/patient?fname=Rita&lname=RxEdit` again to read both. `seed.mjs` shows which fields its create call reads back.
- [x] **Step 4: Run it, and extend the fixture type**

```bash
cd clinical_copilot_week2/migration/fixtures && node seed-rxedit.mjs && node seed-rxedit.mjs
```

Expected: the second run prints the same pid, so the script is idempotent.

In `tests/support/fixtures.ts`, add `'TP-RXEDIT'` to `FixtureKey`.

- [x] **Step 5: Confirm the new scope on a real login**

Run the existing login test: `npx playwright test --project=e2e tests/e2e/login.spec.ts`.
Expected: it passes. Then add a temporary log in `server/auth.ts` that prints `response.scope`, check it includes `prescription`, remove the log, and confirm with `git diff --stat server/auth.ts` that nothing is left.

- [x] **Step 6: Commit**

```bash
git add clinical_copilot_week2/migration/spike/register-client.mjs clinical_copilot_week2/migration/fixtures/seed-rxedit.mjs clinical_copilot_week2/migration/fixtures/fixture-ids.json patient-dashboard/tests/support/fixtures.ts
git commit -m "feat(dashboard): prescription write scope and the TP-RXEDIT write-test patient"
```

### Story 06-02 — Prescription writes in the BFF

**Acceptance:** the three routes add, discontinue and replace prescriptions for the patient on screen only. They refuse cross-site requests, bad input, other patients' prescriptions and medication-list entries.

#### Task 2: Shared write guard and patient lookup

**Files:**
- Create: `patient-dashboard/server/writeGuard.ts`
- Create: `patient-dashboard/server/patientLookup.ts`
- Modify: `patient-dashboard/server/listDates.ts`: `medicationRows` uses `lookupPid`
- Test: `patient-dashboard/tests/unit/server/writeGuard.test.ts`, `patient-dashboard/tests/unit/server/patientLookup.test.ts`

**Interfaces:**
- Produces:
  - `guardWrite(c: Context, deps: { store: SessionStore; oauth: OAuthClient; now: () => number; publicUrl: string }): Promise<{ ok: true; accessToken: string; session: Session } | { ok: false; response: Response }>`
  - `lookupPid(apiBase: string, accessToken: string, patientUuid: string, fetchImpl: typeof fetch): Promise<number>`, which throws `PatientLookupError` on a mismatch or failure

- [x] **Step 1: Write the failing tests**

```ts
// tests/unit/server/writeGuard.test.ts
import { Hono } from 'hono';
import { describe, expect, it } from 'vitest';
import { guardWrite } from '../../../server/writeGuard';
import { SessionStore } from '../../../server/session';
import type { OAuthClient } from '../../../server/oauth';

function app() {
    const now = () => 1_000_000;
    const store = new SessionStore({ ttlMs: 3_600_000, now });
    const session = store.create();
    session.tokens = { accessToken: 'at', expiresAt: now() + 3_600_000 };
    const routes = new Hono();
    routes.post('/w', async (c) => {
        const guard = await guardWrite(c, { store, oauth: {} as OAuthClient, now, publicUrl: 'https://dash.test' });
        return guard.ok ? c.json({ token: guard.accessToken }) : guard.response;
    });
    return { routes, cookie: `pd_sid=${session.id}` };
}
const post = (headers: Record<string, string>) => ({ method: 'POST', headers, body: '{}' });

describe('guardWrite', () => {
    it('lets a signed-in, same-origin JSON request through with the user token', async () => {
        const { routes, cookie } = app();
        const res = await routes.request('/w', post({ cookie, origin: 'https://dash.test', 'content-type': 'application/json' }));
        expect(await res.json()).toEqual({ token: 'at' });
    });
    it('refuses another origin, or no origin, with 403 (a form on another site cannot write)', async () => {
        const { routes, cookie } = app();
        for (const origin of ['https://evil.test', undefined]) {
            const headers: Record<string, string> = { cookie, 'content-type': 'application/json' };
            if (origin !== undefined) headers.origin = origin;
            expect((await routes.request('/w', post(headers))).status, String(origin)).toBe(403);
        }
    });
    it('refuses a body that is not JSON with 415', async () => {
        const { routes, cookie } = app();
        const res = await routes.request('/w', post({ cookie, origin: 'https://dash.test', 'content-type': 'text/plain' }));
        expect(res.status).toBe(415);
    });
    it('refuses a request with no session with 401', async () => {
        const { routes } = app();
        const res = await routes.request('/w', post({ origin: 'https://dash.test', 'content-type': 'application/json' }));
        expect(res.status).toBe(401);
    });
});
```

```ts
// tests/unit/server/patientLookup.test.ts
import { describe, expect, it } from 'vitest';
import { lookupPid, PatientLookupError } from '../../../server/patientLookup';

const UUID = 'a2d68325-7821-4a53-aa27-816ce437150f';
const answering = (status: number, body: unknown) =>
    (async () => new Response(JSON.stringify(body), { status })) as unknown as typeof fetch;

describe('lookupPid', () => {
    it('reads the numeric pid, which OpenEMR may send as text', async () => {
        expect(await lookupPid('https://o/api', 't', UUID, answering(200, { data: { uuid: UUID, pid: '36' } }))).toBe(36);
    });
    it('refuses an answer for another patient, or without a pid', async () => {
        for (const data of [{ uuid: 'other', pid: 36 }, { uuid: UUID }]) {
            await expect(lookupPid('https://o/api', 't', UUID, answering(200, { data }))).rejects.toBeInstanceOf(PatientLookupError);
        }
    });
    it('refuses an error answer', async () => {
        await expect(lookupPid('https://o/api', 't', UUID, answering(403, {}))).rejects.toBeInstanceOf(PatientLookupError);
    });
});
```

- [x] **Step 2: Run them to verify they fail**

Run: `cd patient-dashboard && npx vitest run tests/unit/server/writeGuard.test.ts tests/unit/server/patientLookup.test.ts`
Expected: FAIL, "Cannot find module '../../../server/writeGuard'" (and the same for patientLookup).

- [x] **Step 3: Implement**

```ts
// server/writeGuard.ts
/**
 * The checks every dashboard write passes before it reaches OpenEMR. In: the request and the session store.
 * Out: the signed-in user's fresh access token, or the refusal to send back:
 * - 401: not signed in
 * - 403: the request came from another site (its Origin is not the dashboard's own address)
 * - 415: the body is not JSON (a plain HTML form cannot send JSON)
 * - 502: OpenEMR did not answer the token refresh
 */
import type { Context } from 'hono';
import { getCookie } from 'hono/cookie';
import { SESSION_COOKIE } from './auth';
import type { OAuthClient } from './oauth';
import { ensureFreshToken } from './session';
import type { Session, SessionStore } from './session';

export interface WriteGuardDeps {
    store: SessionStore;
    oauth: OAuthClient;
    now: () => number;
    /** The dashboard's public address (PUBLIC_URL); a write's Origin must equal it. */
    publicUrl: string;
}

export async function guardWrite(
    c: Context,
    deps: WriteGuardDeps,
): Promise<{ ok: true; accessToken: string; session: Session } | { ok: false; response: Response }> {
    if (c.req.header('origin') !== new URL(deps.publicUrl).origin) {
        return { ok: false, response: c.json({ error: 'Cross-site request refused' }, 403) };
    }
    if (!(c.req.header('content-type') ?? '').startsWith('application/json')) {
        return { ok: false, response: c.json({ error: 'expected JSON' }, 415) };
    }
    const session = deps.store.get(getCookie(c, SESSION_COOKIE));
    let tokens;
    try {
        tokens = session === undefined ? undefined : await ensureFreshToken(session, deps.oauth, deps.now);
    } catch {
        return { ok: false, response: c.json({ error: 'OpenEMR did not respond' }, 502) };
    }
    if (session === undefined || tokens === undefined) {
        return { ok: false, response: c.json({ error: 'Not logged in' }, 401) };
    }
    return { ok: true, accessToken: tokens.accessToken, session };
}
```

```ts
// server/patientLookup.ts
/**
 * Turns a patient's uuid (the id FHIR and the page use) into OpenEMR's numeric patient number (pid), which
 * the medication-list and prescription routes need. In: the Standard API address, the user's token, the
 * uuid. Out: the pid. Throws PatientLookupError if OpenEMR refuses, answers for another patient, or sends no pid.
 */
export class PatientLookupError extends Error {}

const TIMEOUT_MS = 20_000;

export async function lookupPid(apiBase: string, accessToken: string, patientUuid: string, fetchImpl: typeof fetch): Promise<number> {
    const res = await fetchImpl(`${apiBase}/patient/${patientUuid}`, {
        method: 'GET',
        headers: { Authorization: `Bearer ${accessToken}`, Accept: 'application/json' },
        signal: AbortSignal.timeout(TIMEOUT_MS),
    });
    if (!res.ok) {
        throw new PatientLookupError(`patient lookup returned HTTP ${res.status}`);
    }
    const body = (await res.json()) as { data?: { uuid?: unknown; pid?: unknown } };
    const pid = Number(body.data?.pid);
    if (body.data?.uuid !== patientUuid || !Number.isInteger(pid) || pid <= 0) {
        throw new PatientLookupError('patient lookup did not match');
    }
    return pid;
}
```

In `server/listDates.ts`, replace the `medicationRows` pid lookup with `await lookupPid(apiBase, accessToken, patient, fetchImpl)`, converting `PatientLookupError` to `Unavailable`. Leave the `get(...)` helper for the rows themselves.

- [x] **Step 4: Run the server tests**

Run: `npx vitest run tests/unit/server`
Expected: all pass, including the existing `listDates.test.ts`.

- [x] **Step 5: Commit**

```bash
git add patient-dashboard/server/writeGuard.ts patient-dashboard/server/patientLookup.ts patient-dashboard/server/listDates.ts patient-dashboard/tests/unit/server/writeGuard.test.ts patient-dashboard/tests/unit/server/patientLookup.test.ts
git commit -m "feat(dashboard): shared write guard and patient number lookup for BFF writes"
```

#### Task 3: Prescription write routes

**Files:**
- Create: `patient-dashboard/server/prescriptionInput.ts`
- Create: `patient-dashboard/server/prescriptionWrites.ts`
- Modify: `patient-dashboard/server/app.ts`, `patient-dashboard/server/index.ts`
- Test: `patient-dashboard/tests/unit/server/prescriptionInput.test.ts`, `patient-dashboard/tests/unit/server/prescriptionWrites.test.ts`

**Interfaces:**
- Consumes: `guardWrite`, `lookupPid` (Task 2)
- Produces:
  - `parsePrescriptionInput(body: unknown, serverNowMs: number): { ok: true; value: PrescriptionInput } | { ok: false; errors: Record<string, string> }`
  - `PrescriptionInput = { drug: string; dosage: string; quantity: string; refills: number; startDate: string; dateAdded: string; prescriber: string }`
  - `openemrPrescriptionBody(input: PrescriptionInput, pid: number): Record<string, string | number>`
  - HTTP:
    - `POST /api/prescriptions?patient=<uuid>` → 201 `{ uuid }`
    - `POST /api/prescriptions/:uuid/discontinue?patient=<uuid>` → 200 `{ discontinued: uuid }`
    - `POST /api/prescriptions/:uuid/replace?patient=<uuid>` → 201 `{ added, discontinued: true }` or 207 `{ added, discontinued: false }`
    - Errors: 400 `{ errors }`, 401, 403, 404, 415, 502

- [x] **Step 1: Write the failing input tests**

```ts
// tests/unit/server/prescriptionInput.test.ts
import { describe, expect, it } from 'vitest';
import { openemrPrescriptionBody, parsePrescriptionInput } from '../../../server/prescriptionInput';

const NOW = Date.parse('2026-09-27T12:00:00Z');
const valid = {
    drug: 'Amoxicillin 500 mg', dosage: '1 capsule three times daily', quantity: '21', refills: 0,
    startDate: '2026-09-27', dateAdded: '2026-09-27 12:00:00', prescriber: 'Dr Donna Lee',
};

describe('parsePrescriptionInput', () => {
    it('accepts a complete form and trims text', () => {
        const parsed = parsePrescriptionInput({ ...valid, drug: '  Amoxicillin 500 mg ' }, NOW);
        expect(parsed).toEqual({ ok: true, value: { ...valid } });
    });
    it('names each problem: no drug, a quantity that is not a whole number, refills outside 0-20, a bad date', () => {
        const parsed = parsePrescriptionInput({ ...valid, drug: ' ', quantity: '2.5', refills: 21, startDate: '2026-02-30' }, NOW);
        expect(parsed.ok).toBe(false);
        expect(Object.keys(parsed.ok ? {} : parsed.errors).sort()).toEqual(['drug', 'quantity', 'refills', 'startDate']);
    });
    it('refuses a date added more than a day from the server clock, so a wrong page clock cannot backdate', () => {
        expect(parsePrescriptionInput({ ...valid, dateAdded: '2026-09-20 12:00:00' }, NOW).ok).toBe(false);
    });
    it('refuses a prescriber with a line break or over 100 characters, and unknown fields', () => {
        expect(parsePrescriptionInput({ ...valid, prescriber: 'a\nb' }, NOW).ok).toBe(false);
        expect(parsePrescriptionInput({ ...valid, prescriber: 'x'.repeat(101) }, NOW).ok).toBe(false);
        expect(parsePrescriptionInput({ ...valid, provider_id: 1 }, NOW).ok).toBe(false);
    });
    it('allows an empty quantity, dosage and prescriber', () => {
        expect(parsePrescriptionInput({ ...valid, quantity: '', dosage: '', prescriber: '' }, NOW).ok).toBe(true);
    });
});

describe('openemrPrescriptionBody', () => {
    it('sets what the old form sets, and puts the typed prescriber in the note', () => {
        expect(openemrPrescriptionBody(valid, 42)).toEqual({
            patient_id: 42, drug: 'Amoxicillin 500 mg', drug_dosage_instructions: '1 capsule three times daily',
            quantity: '21', refills: 0, per_refill: 0, start_date: '2026-09-27',
            date_added: '2026-09-27 12:00:00', date_modified: '2026-09-27 12:00:00', active: 1,
            request_intent: 'order', request_intent_title: 'Order',
            usage_category: 'outpatient', usage_category_title: 'Outpatient',
            note: 'Prescriber: Dr Donna Lee',
        });
    });
    it('leaves the note empty when no prescriber is typed', () => {
        expect(openemrPrescriptionBody({ ...valid, prescriber: '' }, 42).note).toBe('');
    });
});
```

- [x] **Step 2: Run it to verify it fails**

Run: `npx vitest run tests/unit/server/prescriptionInput.test.ts`
Expected: FAIL, "Cannot find module".

- [x] **Step 3: Implement `server/prescriptionInput.ts`**

```ts
/**
 * Checks the prescription form a page sends and turns it into the body OpenEMR's POST /api/prescription
 * takes. OpenEMR itself checks only that `drug` and `patient_id` are present (PrescriptionService.php:404),
 * so everything else is checked here. It also sets what the old form sets on its own
 * (Prescription.class.php:225-275).
 *
 * The prescriber goes in the note as "Prescriber: <text>", because OpenEMR's prescriber field only takes
 * OpenEMR's internal user number, which non-admin users cannot look up through the API (ARC-06 decision 2).
 */
export interface PrescriptionInput {
    drug: string;
    dosage: string;
    quantity: string;
    refills: number;
    /** YYYY-MM-DD */
    startDate: string;
    /** "YYYY-MM-DD HH:MM:SS" in the clinic's local time, from the page */
    dateAdded: string;
    prescriber: string;
}

const FIELDS = ['drug', 'dosage', 'quantity', 'refills', 'startDate', 'dateAdded', 'prescriber'];
const DATE = /^(\d{4})-(\d{2})-(\d{2})$/;
const DATETIME = /^(\d{4}-\d{2}-\d{2}) (\d{2}):(\d{2}):(\d{2})$/;
const DAY_MS = 86_400_000;

/** True when "YYYY-MM-DD" is a real calendar date (so 2026-02-30 is refused). */
function isRealDate(text: string): boolean {
    const match = DATE.exec(text);
    if (match === null) return false;
    const date = new Date(`${text}T00:00:00Z`);
    return !Number.isNaN(date.getTime()) && date.toISOString().slice(0, 10) === text;
}

export function parsePrescriptionInput(
    body: unknown,
    serverNowMs: number,
): { ok: true; value: PrescriptionInput } | { ok: false; errors: Record<string, string> } {
    const errors: Record<string, string> = {};
    if (typeof body !== 'object' || body === null || Array.isArray(body)) {
        return { ok: false, errors: { form: 'expected an object' } };
    }
    const record = body as Record<string, unknown>;
    for (const key of Object.keys(record)) {
        if (!FIELDS.includes(key)) errors[key] = 'not a prescription field';
    }
    const text = (key: string) => (typeof record[key] === 'string' ? (record[key] as string).trim() : undefined);
    const drug = text('drug');
    if (drug === undefined || drug.length < 2 || drug.length > 255) errors.drug = 'Enter the drug (2 to 255 characters)';
    const dosage = text('dosage') ?? '';
    if (dosage.length > 255) errors.dosage = 'Keep directions under 255 characters';
    const quantity = text('quantity') ?? '';
    if (quantity !== '' && !/^[1-9]\d{0,5}$/.test(quantity)) errors.quantity = 'Quantity must be a whole number';
    const refills = record.refills;
    if (typeof refills !== 'number' || !Number.isInteger(refills) || refills < 0 || refills > 20) errors.refills = 'Refills must be 0 to 20';
    const startDate = text('startDate') ?? '';
    if (!isRealDate(startDate)) errors.startDate = 'Enter a real start date';
    const dateAdded = text('dateAdded') ?? '';
    const added = DATETIME.exec(dateAdded);
    if (added === null || !isRealDate(added[1] ?? '') || Math.abs(Date.parse(dateAdded.replace(' ', 'T')) - serverNowMs) > DAY_MS) {
        errors.dateAdded = 'The page clock is wrong; reload and try again';
    }
    const prescriber = text('prescriber') ?? '';
    if (prescriber.length > 100 || /[\r\n]/.test(String(record.prescriber ?? ''))) errors.prescriber = 'Prescriber: one line, up to 100 characters';
    if (Object.keys(errors).length > 0) return { ok: false, errors };
    return {
        ok: true,
        value: { drug: drug as string, dosage, quantity, refills: refills as number, startDate, dateAdded, prescriber },
    };
}

export function openemrPrescriptionBody(input: PrescriptionInput, pid: number): Record<string, string | number> {
    return {
        patient_id: pid,
        drug: input.drug,
        drug_dosage_instructions: input.dosage,
        quantity: input.quantity,
        refills: input.refills,
        per_refill: 0,
        start_date: input.startDate,
        date_added: input.dateAdded,
        date_modified: input.dateAdded,
        active: 1,
        request_intent: 'order',
        request_intent_title: 'Order',
        usage_category: 'outpatient',
        usage_category_title: 'Outpatient',
        note: input.prescriber === '' ? '' : `Prescriber: ${input.prescriber}`,
    };
}
```

- [x] **Step 4: Run it to verify it passes**

Run: `npx vitest run tests/unit/server/prescriptionInput.test.ts`
Expected: PASS.

- [x] **Step 5: Write the failing route tests**

```ts
// tests/unit/server/prescriptionWrites.test.ts
import { describe, expect, it } from 'vitest';
import { createApp } from '../../../server/app';
import { SessionStore } from '../../../server/session';
import type { OAuthClient } from '../../../server/oauth';

const PATIENT = 'a2d68325-7821-4a53-aa27-816ce437150f';
const OTHER = 'b3e79436-8932-4b64-bb38-927df548261a';
const RX = 'c4f8a547-9a43-4c75-8c49-a38e0a59372b';
const NOW = Date.parse('2026-09-27T12:00:00Z');
const form = { drug: 'Amoxicillin 500 mg', dosage: '', quantity: '21', refills: 0, startDate: '2026-09-27', dateAdded: '2026-09-27 12:00:00', prescriber: '' };

type Call = { method: string; url: string; body?: unknown };

/** A fake OpenEMR: the patient lookup, one prescription record, and the insert and delete answers. */
function fakeOpenEmr(options: { rx?: Record<string, unknown>; insertStatus?: number; deleteStatus?: number } = {}) {
    const calls: Call[] = [];
    const fetchImpl = (async (url: string, init?: RequestInit) => {
        const method = init?.method ?? 'GET';
        calls.push({ method, url, ...(init?.body === undefined ? {} : { body: JSON.parse(String(init.body)) }) });
        if (url.endsWith(`/patient/${PATIENT}`)) return Response.json({ data: { uuid: PATIENT, pid: '42' } });
        if (method === 'GET' && url.endsWith(`/prescription/${RX}`)) {
            return Response.json({ data: [options.rx ?? { uuid: RX, puuid: PATIENT, source_table: 'prescriptions', active: '1' }] });
        }
        if (method === 'POST' && url.endsWith('/prescription')) {
            return Response.json({ data: { id: 9, uuid: 'new-uuid' } }, { status: options.insertStatus ?? 201 });
        }
        if (method === 'DELETE') return Response.json({ data: { message: 'record deleted' } }, { status: options.deleteStatus ?? 200 });
        return new Response('', { status: 404 });
    }) as unknown as typeof fetch;
    return { calls, fetchImpl };
}

function setup(openemr = fakeOpenEmr()) {
    const now = () => NOW;
    const store = new SessionStore({ ttlMs: 3_600_000, now });
    const session = store.create();
    session.tokens = { accessToken: 'at', expiresAt: NOW + 3_600_000 };
    const app = createApp({
        prescriptionWrites: {
            store, oauth: {} as OAuthClient, now, publicUrl: 'https://dash.test',
            apiBase: 'https://oemr.test/apis/default/api', fetchImpl: openemr.fetchImpl,
        },
    });
    const send = (path: string, body: unknown = form) =>
        app.request(path, {
            method: 'POST',
            headers: { cookie: `pd_sid=${session.id}`, origin: 'https://dash.test', 'content-type': 'application/json' },
            body: JSON.stringify(body),
        });
    return { send, calls: openemr.calls };
}

describe('POST /api/prescriptions', () => {
    it('adds the prescription for the patient on screen, with the old form defaults', async () => {
        const { send, calls } = setup();
        const res = await send(`/api/prescriptions?patient=${PATIENT}`);
        expect(res.status).toBe(201);
        expect(await res.json()).toEqual({ uuid: 'new-uuid' });
        const insert = calls.find((call) => call.method === 'POST');
        expect(insert?.body).toMatchObject({ patient_id: 42, drug: 'Amoxicillin 500 mg', active: 1, request_intent: 'order' });
    });
    it('answers 400 with the problems and sends nothing to OpenEMR when the form is wrong', async () => {
        const { send, calls } = setup();
        const res = await send(`/api/prescriptions?patient=${PATIENT}`, { ...form, drug: '' });
        expect(res.status).toBe(400);
        expect(Object.keys(((await res.json()) as { errors: object }).errors)).toEqual(['drug']);
        expect(calls).toEqual([]);
    });
    it('answers 400 for a patient that is not a uuid', async () => {
        const { send } = setup();
        expect((await send('/api/prescriptions?patient=36')).status).toBe(400);
    });
    it('answers 502 when OpenEMR refuses the insert', async () => {
        const { send } = setup(fakeOpenEmr({ insertStatus: 400 }));
        expect((await send(`/api/prescriptions?patient=${PATIENT}`)).status).toBe(502);
    });
});

describe('POST /api/prescriptions/:uuid/discontinue', () => {
    it('discontinues an active prescription of the patient on screen', async () => {
        const { send, calls } = setup();
        const res = await send(`/api/prescriptions/${RX}/discontinue?patient=${PATIENT}`, {});
        expect(res.status).toBe(200);
        expect(calls.some((call) => call.method === 'DELETE' && call.url.endsWith(`/prescription/${RX}`))).toBe(true);
    });
    it("never discontinues another patient's prescription or a medication-list entry (answers 404, no DELETE)", async () => {
        for (const rx of [
            { uuid: RX, puuid: OTHER, source_table: 'prescriptions', active: '1' },
            { uuid: RX, puuid: PATIENT, source_table: 'lists', active: '1' },
        ]) {
            const openemr = fakeOpenEmr({ rx });
            const { send } = setup(openemr);
            expect((await send(`/api/prescriptions/${RX}/discontinue?patient=${PATIENT}`, {})).status).toBe(404);
            expect(openemr.calls.some((call) => call.method === 'DELETE')).toBe(false);
        }
    });
});

describe('POST /api/prescriptions/:uuid/replace', () => {
    it('adds the corrected prescription first, then discontinues the old one', async () => {
        const { send, calls } = setup();
        const res = await send(`/api/prescriptions/${RX}/replace?patient=${PATIENT}`);
        expect(res.status).toBe(201);
        expect(await res.json()).toEqual({ added: 'new-uuid', discontinued: true });
        const order = calls.filter((call) => call.method !== 'GET').map((call) => call.method);
        expect(order).toEqual(['POST', 'DELETE']);
    });
    it('reports 207 when the new one was added but the old one could not be discontinued', async () => {
        const { send } = setup(fakeOpenEmr({ deleteStatus: 500 }));
        const res = await send(`/api/prescriptions/${RX}/replace?patient=${PATIENT}`);
        expect(res.status).toBe(207);
        expect(await res.json()).toEqual({ added: 'new-uuid', discontinued: false });
    });
    it('adds nothing when the old prescription is not this patient’s', async () => {
        const openemr = fakeOpenEmr({ rx: { uuid: RX, puuid: OTHER, source_table: 'prescriptions', active: '1' } });
        const { send } = setup(openemr);
        expect((await send(`/api/prescriptions/${RX}/replace?patient=${PATIENT}`)).status).toBe(404);
        expect(openemr.calls.some((call) => call.method === 'POST')).toBe(false);
    });
});
```

- [x] **Step 6: Run it to verify it fails**

Run: `npx vitest run tests/unit/server/prescriptionWrites.test.ts`
Expected: FAIL. `createApp` does not accept `prescriptionWrites`, so the routes answer 404.

- [x] **Step 7: Implement `server/prescriptionWrites.ts` and wire it**

```ts
/**
 * The dashboard's prescription writes, passed on to OpenEMR's Standard REST API with the user's own token.
 * - POST /api/prescriptions?patient=<uuid>: add
 * - POST /api/prescriptions/<uuid>/discontinue?patient=<uuid>: mark inactive (OpenEMR's soft delete)
 * - POST /api/prescriptions/<uuid>/replace?patient=<uuid>: "change": add the corrected one first, then
 *   discontinue the old one. If that last step fails, the answer is 207, so the page can say both exist.
 *   Adding first means a failure leaves two prescriptions, never none.
 * Before any change to an existing prescription, it is read back to confirm it belongs to the patient on
 * screen and is a real prescription, not a medication-list entry (OpenEMR's prescription list mixes both).
 */
import { Hono } from 'hono';
import type { OAuthClient } from './oauth';
import { lookupPid, PatientLookupError } from './patientLookup';
import { openemrPrescriptionBody, parsePrescriptionInput } from './prescriptionInput';
import type { SessionStore } from './session';
import { guardWrite } from './writeGuard';

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
const TIMEOUT_MS = 20_000;

export interface PrescriptionWritesDeps {
    store: SessionStore;
    oauth: OAuthClient;
    now: () => number;
    publicUrl: string;
    apiBase: string;
    fetchImpl?: typeof fetch;
}

export function prescriptionWritesRoutes(deps: PrescriptionWritesDeps): Hono {
    const fetchImpl = deps.fetchImpl ?? fetch;
    const routes = new Hono();

    const call = (accessToken: string, method: string, path: string, body?: unknown) =>
        fetchImpl(`${deps.apiBase}/${path}`, {
            method,
            headers: { Authorization: `Bearer ${accessToken}`, Accept: 'application/json', 'Content-Type': 'application/json' },
            ...(body === undefined ? {} : { body: JSON.stringify(body) }),
            signal: AbortSignal.timeout(TIMEOUT_MS),
        });

    /** True when the prescription exists, is a real (not list) prescription, is active, and belongs to the patient. */
    async function ownsActivePrescription(accessToken: string, patient: string, rx: string): Promise<boolean> {
        const res = await call(accessToken, 'GET', `prescription/${rx}`);
        if (!res.ok) return false;
        const body = (await res.json()) as { data?: unknown };
        const rows = Array.isArray(body.data) ? body.data : [body.data];
        const row = rows.find((r): r is Record<string, unknown> => typeof r === 'object' && r !== null && (r as { uuid?: unknown }).uuid === rx);
        return row !== undefined && row.puuid === patient && row.source_table === 'prescriptions' && String(row.active) === '1';
    }

    /** Adds one prescription; the new uuid, or undefined if OpenEMR refused. */
    async function add(accessToken: string, patient: string, body: unknown): Promise<string | undefined> {
        const input = parsePrescriptionInput(body, deps.now());
        if (!input.ok) throw new InputError(input.errors);
        const pid = await lookupPid(deps.apiBase, accessToken, patient, fetchImpl);
        const res = await call(accessToken, 'POST', 'prescription', openemrPrescriptionBody(input.value, pid));
        if (res.status !== 201) return undefined;
        const created = (await res.json()) as { data?: { uuid?: unknown } };
        return typeof created.data?.uuid === 'string' ? created.data.uuid : undefined;
    }

    const discontinue = async (accessToken: string, rx: string) => (await call(accessToken, 'DELETE', `prescription/${rx}`)).ok;

    routes.post('/', async (c) => {
        const guard = await guardWrite(c, deps);
        if (!guard.ok) return guard.response;
        const patient = c.req.query('patient') ?? '';
        if (!UUID.test(patient)) return c.json({ error: 'patient must be a patient uuid' }, 400);
        try {
            const uuid = await add(guard.accessToken, patient, await c.req.json().catch(() => undefined));
            return uuid === undefined ? c.json({ error: 'OpenEMR did not save the prescription' }, 502) : c.json({ uuid }, 201);
        } catch (error) {
            return failure(c, error);
        }
    });

    routes.post('/:rx/discontinue', async (c) => {
        const guard = await guardWrite(c, deps);
        if (!guard.ok) return guard.response;
        const patient = c.req.query('patient') ?? '';
        const rx = c.req.param('rx');
        if (!UUID.test(patient) || !UUID.test(rx)) return c.json({ error: 'patient and prescription must be uuids' }, 400);
        try {
            if (!(await ownsActivePrescription(guard.accessToken, patient, rx))) return c.json({ error: 'No such active prescription' }, 404);
            return (await discontinue(guard.accessToken, rx))
                ? c.json({ discontinued: rx })
                : c.json({ error: 'OpenEMR did not discontinue the prescription' }, 502);
        } catch (error) {
            return failure(c, error);
        }
    });

    routes.post('/:rx/replace', async (c) => {
        const guard = await guardWrite(c, deps);
        if (!guard.ok) return guard.response;
        const patient = c.req.query('patient') ?? '';
        const rx = c.req.param('rx');
        if (!UUID.test(patient) || !UUID.test(rx)) return c.json({ error: 'patient and prescription must be uuids' }, 400);
        try {
            if (!(await ownsActivePrescription(guard.accessToken, patient, rx))) return c.json({ error: 'No such active prescription' }, 404);
            const added = await add(guard.accessToken, patient, await c.req.json().catch(() => undefined));
            if (added === undefined) return c.json({ error: 'OpenEMR did not save the prescription' }, 502);
            const discontinued = await discontinue(guard.accessToken, rx).catch(() => false);
            return c.json({ added, discontinued }, discontinued ? 201 : 207);
        } catch (error) {
            return failure(c, error);
        }
    });

    return routes;
}

class InputError extends Error {
    constructor(readonly errors: Record<string, string>) {
        super('invalid prescription');
    }
}

function failure(c: import('hono').Context, error: unknown): Response {
    if (error instanceof InputError) return c.json({ errors: error.errors }, 400);
    if (error instanceof PatientLookupError) return c.json({ error: 'The patient could not be found' }, 502);
    console.error('Prescription write failed', { error: (error as Error).name });
    return c.json({ error: 'OpenEMR did not respond' }, 502);
}
```

Wiring: in `server/app.ts`, add `prescriptionWrites?: PrescriptionWritesDeps` to `AppOptions`, and `app.route('/api/prescriptions', prescriptionWritesRoutes(options.prescriptionWrites))` before the static handler. In `server/index.ts`, pass `{ store, oauth, now, publicUrl: config.publicUrl, apiBase: \`${config.oemrBase}/apis/default/api\` }`.

- [x] **Step 8: Run the server tests**

Run: `npx vitest run tests/unit/server && npm run typecheck && npm run lint`
Expected: all pass, clean.

- [x] **Step 9: Commit**

```bash
git add patient-dashboard/server/prescriptionInput.ts patient-dashboard/server/prescriptionWrites.ts patient-dashboard/server/app.ts patient-dashboard/server/index.ts patient-dashboard/tests/unit/server/prescriptionInput.test.ts patient-dashboard/tests/unit/server/prescriptionWrites.test.ts
git commit -m "feat(dashboard): BFF routes to add, discontinue and change prescriptions"
```

#### Task 4: "Edit in OpenEMR" redirect and the prescriber default

**Files:**
- Create: `patient-dashboard/server/openemrLink.ts`
- Modify: `patient-dashboard/server/auth.ts`: `/auth/me` adds `userId`
- Modify: `patient-dashboard/server/config.ts`: `oemrPublicUrl`, from `OEMR_PUBLIC_URL`, defaulting to `OEMR_BASE`
- Modify: `patient-dashboard/server/app.ts`, `patient-dashboard/server/index.ts`
- Test: `patient-dashboard/tests/unit/server/openemrLink.test.ts`, new cases in `auth.test.ts` and `config.test.ts`

**Interfaces:**
- Consumes: `lookupPid` (Task 2)
- Produces:
  - `GET /openemr/patient/:uuid` → 302 `Location: <oemrPublicUrl>/interface/patient_file/summary/demographics.php?set_pid=<pid>`, 401 when signed out, 400 for a bad uuid, 502 when the lookup fails
  - `GET /auth/me` → `{ authenticated: boolean, userId?: string }`

- [x] **Step 1: Write the failing tests**

```ts
// tests/unit/server/openemrLink.test.ts
import { describe, expect, it } from 'vitest';
import { createApp } from '../../../server/app';
import { SessionStore } from '../../../server/session';
import type { OAuthClient } from '../../../server/oauth';

const PATIENT = 'a2d68325-7821-4a53-aa27-816ce437150f';

function setup(signedIn = true) {
    const now = () => 1_000_000;
    const store = new SessionStore({ ttlMs: 3_600_000, now });
    const session = store.create();
    if (signedIn) session.tokens = { accessToken: 'at', expiresAt: now() + 3_600_000 };
    const fetchImpl = (async () => Response.json({ data: { uuid: PATIENT, pid: 36 } })) as unknown as typeof fetch;
    const app = createApp({
        openemrLink: { store, oauth: {} as OAuthClient, now, apiBase: 'https://o/apis/default/api', oemrPublicUrl: 'https://o', fetchImpl },
    });
    return { app, cookie: `pd_sid=${session.id}` };
}

describe('GET /openemr/patient/:uuid (the "Edit in OpenEMR" button)', () => {
    it("sends the browser to that patient's chart page in OpenEMR", async () => {
        const { app, cookie } = setup();
        const res = await app.request(`/openemr/patient/${PATIENT}`, { headers: { cookie } });
        expect(res.status).toBe(302);
        expect(res.headers.get('location')).toBe('https://o/interface/patient_file/summary/demographics.php?set_pid=36');
    });
    it('answers 401 when signed out and 400 for something that is not a uuid', async () => {
        const out = setup(false);
        expect((await out.app.request(`/openemr/patient/${PATIENT}`, { headers: { cookie: out.cookie } })).status).toBe(401);
        const { app, cookie } = setup();
        expect((await app.request('/openemr/patient/36', { headers: { cookie } })).status).toBe(400);
    });
});
```

Add to `auth.test.ts`, inside `describe('BFF login flow')`:

```ts
    it('/auth/me also names the signed-in user, for the prescriber box', async () => {
        const { app } = setup(false, undefined, idToken('user-uuid-1'));
        const login = await startLogin(app);
        const callback = await app.request(`/auth/callback?code=c&state=${login.state}`, { headers: { cookie: login.cookie } });
        const me = await app.request('/auth/me', { headers: { cookie: setCookieOf(callback) } });
        expect(await me.json()).toEqual({ authenticated: true, userId: 'user-uuid-1' });
    });
```

Also update the existing `/auth/me` expectations in `auth.test.ts`, which equal `{ authenticated: true }`. When no ID token was given they keep that exact shape, so only tests that pass `idToken(...)` change.

- [x] **Step 2: Run to verify they fail**

Run: `npx vitest run tests/unit/server/openemrLink.test.ts tests/unit/server/auth.test.ts`
Expected: FAIL. The route answers 404, and `userId` is missing.

- [x] **Step 3: Implement**

```ts
// server/openemrLink.ts
/**
 * The "Edit in OpenEMR" button's destination. OpenEMR's chart page needs its own patient number (pid), which
 * the page does not know, so the button points here and this route looks the pid up and redirects:
 * GET /openemr/patient/<uuid> → 302 to <OpenEMR>/interface/patient_file/summary/demographics.php?set_pid=<pid>.
 * OpenEMR has no link that opens a patient inside its full menu frame (main.php takes only a one-time token),
 * so this opens the chart page on its own. If the user is not signed in to OpenEMR they see its login page,
 * and after signing in OpenEMR goes to its home screen, not this patient (library/auth.inc.php:138-160).
 */
import { Hono } from 'hono';
import { getCookie } from 'hono/cookie';
import { SESSION_COOKIE } from './auth';
import type { OAuthClient } from './oauth';
import { lookupPid } from './patientLookup';
import { ensureFreshToken } from './session';
import type { SessionStore } from './session';

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

export interface OpenEmrLinkDeps {
    store: SessionStore;
    oauth: OAuthClient;
    now: () => number;
    apiBase: string;
    /** OpenEMR's address as the user's browser reaches it (OEMR_PUBLIC_URL, default OEMR_BASE). */
    oemrPublicUrl: string;
    fetchImpl?: typeof fetch;
}

export function openemrLinkRoutes(deps: OpenEmrLinkDeps): Hono {
    const routes = new Hono();
    routes.get('/patient/:uuid', async (c) => {
        const patient = c.req.param('uuid');
        if (!UUID.test(patient)) return c.text('Not a patient id', 400);
        const session = deps.store.get(getCookie(c, SESSION_COOKIE));
        let tokens;
        try {
            tokens = session === undefined ? undefined : await ensureFreshToken(session, deps.oauth, deps.now);
        } catch {
            return c.text('OpenEMR did not respond. Try again.', 502);
        }
        if (tokens === undefined) return c.text('Your dashboard session has ended. Sign in again.', 401);
        try {
            const pid = await lookupPid(deps.apiBase, tokens.accessToken, patient, deps.fetchImpl ?? fetch);
            return c.redirect(`${deps.oemrPublicUrl}/interface/patient_file/summary/demographics.php?set_pid=${pid}`, 302);
        } catch {
            return c.text('OpenEMR could not find this patient.', 502);
        }
    });
    return routes;
}
```

- In `server/auth.ts`, change the `/me` answer to `c.json(session?.userId === undefined || !authenticated ? { authenticated } : { authenticated, userId: session.userId })`.
- In `server/config.ts`, add `oemrPublicUrl: (env.OEMR_PUBLIC_URL ?? env.OEMR_BASE).replace(/\/+$/, '')`, with a `config.test.ts` case for the default.
- Wire it in `app.ts` as `app.route('/openemr', openemrLinkRoutes(...))`, and in `index.ts`.

- [x] **Step 4: Run the server tests, type check and lint**

Run: `npx vitest run tests/unit/server && npm run typecheck && npm run lint`
Expected: PASS, clean.

- [x] **Step 5: Commit**

```bash
git add patient-dashboard/server/openemrLink.ts patient-dashboard/server/auth.ts patient-dashboard/server/config.ts patient-dashboard/server/app.ts patient-dashboard/server/index.ts patient-dashboard/tests/unit/server/openemrLink.test.ts patient-dashboard/tests/unit/server/auth.test.ts patient-dashboard/tests/unit/server/config.test.ts
git commit -m "feat(dashboard): Edit in OpenEMR redirect and the signed-in user id for the prescriber box"
```

### Story 06-03 — Editing on the page

**Acceptance:**
- A clinician can add, change and discontinue a prescription on TP-RXEDIT from the Prescriptions card, and the old dashboard shows the same result.
- The other four cards each show "Edit in OpenEMR" with its note.

#### Task 5: Page-side write calls, card reloads and the frame's action slot

**Files:**
- Modify: `patient-dashboard/web/src/api/client.ts`: add `sendJson`
- Create: `patient-dashboard/web/src/api/prescriptionWrites.ts`
- Modify: `patient-dashboard/web/src/hooks/useBundleCard.ts`, `patient-dashboard/web/src/hooks/useMedicationCards.ts`: `revision` input
- Modify: `patient-dashboard/web/src/cards/CardFrame.tsx`: `actions?: ReactNode`
- Test: `patient-dashboard/tests/unit/api/prescriptionWrites.test.ts`, `patient-dashboard/tests/unit/hooks/useMedicationCards.test.tsx` (new case), `patient-dashboard/tests/unit/cards/CardFrame.test.tsx` (new case)

**Interfaces:**
- Produces:
  - `ApiClient.sendJson(method: 'POST', path: string, body: unknown): Promise<{ status: number; body: unknown }>`. It never throws. A network failure gives `{ status: 0, body: undefined }`, and a 401 calls `onUnauthenticated` as reads do.
  - `addPrescription(client, patientId, form: PrescriptionForm): Promise<WriteOutcome>`
  - `discontinuePrescription(client, patientId, rxId): Promise<WriteOutcome>`
  - `replacePrescription(client, patientId, rxId, form): Promise<WriteOutcome>`
  - `WriteOutcome = { kind: 'saved' } | { kind: 'partly-saved'; message: string } | { kind: 'invalid'; errors: Record<string, string> } | { kind: 'failed'; message: string }`
  - `PrescriptionForm = { drug: string; dosage: string; quantity: string; refills: number; startDate: string; dateAdded: string; prescriber: string }`
  - `useMedicationCards(client, patientId, now, revision = 0)` and `useBundleCard(..., extraQuery = '', revision = 0)`
  - `CardFrame` gets `actions?: ReactNode`

- [x] **Step 1: Write the failing tests**

```ts
// tests/unit/api/prescriptionWrites.test.ts
import { describe, expect, it } from 'vitest';
import { addPrescription, discontinuePrescription, replacePrescription } from '../../../web/src/api/prescriptionWrites';
import type { ApiClient } from '../../../web/src/api/client';

const form = { drug: 'Amoxicillin', dosage: '', quantity: '21', refills: 0, startDate: '2026-09-27', dateAdded: '2026-09-27 12:00:00', prescriber: '' };
const clientAnswering = (status: number, body: unknown, seen: unknown[] = []): ApiClient =>
    ({
        sendJson: async (method: string, path: string, sent: unknown) => {
            seen.push({ method, path, sent });
            return { status, body };
        },
    }) as unknown as ApiClient;

describe('prescription writes from the page', () => {
    it('adds to the patient on screen', async () => {
        const seen: unknown[] = [];
        expect(await addPrescription(clientAnswering(201, { uuid: 'u' }, seen), 'p1', form)).toEqual({ kind: 'saved' });
        expect(seen).toEqual([{ method: 'POST', path: 'prescriptions?patient=p1', sent: form }]);
    });
    it("turns the server's 400 into the form's field messages", async () => {
        expect(await addPrescription(clientAnswering(400, { errors: { drug: 'Enter the drug' } }), 'p1', form)).toEqual({
            kind: 'invalid',
            errors: { drug: 'Enter the drug' },
        });
    });
    it('says plainly when a change added the new prescription but could not stop the old one', async () => {
        const outcome = await replacePrescription(clientAnswering(207, { added: 'n', discontinued: false }), 'p1', 'rx', form);
        expect(outcome.kind).toBe('partly-saved');
    });
    it('reports any other answer, or no answer, as not saved', async () => {
        expect((await discontinuePrescription(clientAnswering(502, {}), 'p1', 'rx')).kind).toBe('failed');
        expect((await discontinuePrescription(clientAnswering(0, undefined), 'p1', 'rx')).kind).toBe('failed');
    });
});
```

Add to `tests/unit/hooks/useMedicationCards.test.tsx`, reusing the file's own `fakeClient`, `typicalDates` and `TYPICAL`:

```tsx
    it('asks again when the revision changes, keeping the old list on screen until the new one arrives', async () => {
        const paths: string[] = [];
        const client = fakeClient({ ok: true, value: typicalDates }, paths);
        const { result, rerender } = renderHook(
            ({ revision }) => useMedicationCards(client, TYPICAL, '2026-09-26 12:00:00', revision),
            { initialProps: { revision: 0 } },
        );
        await waitFor(() => expect(result.current.prescriptions.status).toBe('ready'));
        const before = paths.length;
        rerender({ revision: 1 });
        expect(result.current.prescriptions.status).toBe('ready');
        await waitFor(() => expect(paths.length).toBeGreaterThan(before));
    });
```

Add to `tests/unit/cards/CardFrame.test.tsx`:

```tsx
    it('draws actions at the right of the title bar, as the old card draws its pencil', () => {
        render(
            <CardFrame card="prescriptions" title="Prescriptions" state="ready" patientId="p1" actions={<button type="button">Add</button>}>
                <p>body</p>
            </CardFrame>,
        );
        const heading = screen.getByRole('heading', { name: /Prescriptions/ });
        expect(heading.querySelector('button:last-child')?.textContent).toBe('Add');
    });
```

- [x] **Step 2: Run to verify they fail**

Run: `npx vitest run tests/unit/api/prescriptionWrites.test.ts tests/unit/hooks/useMedicationCards.test.tsx tests/unit/cards/CardFrame.test.tsx`
Expected: FAIL, from the missing module, the unchanged call count and the missing actions.

- [x] **Step 3: Implement**

```ts
// web/src/api/prescriptionWrites.ts
/**
 * The page's side of prescription editing: sends the form to the BFF (server/prescriptionWrites.ts) and turns
 * its answer into one of four outcomes the card can show: saved, partly saved (a change added the new
 * prescription but could not stop the old one), invalid (with a message per field), or failed.
 */
import type { ApiClient } from './client';

export interface PrescriptionForm {
    drug: string;
    dosage: string;
    quantity: string;
    refills: number;
    startDate: string;
    dateAdded: string;
    prescriber: string;
}

export type WriteOutcome =
    | { kind: 'saved' }
    | { kind: 'partly-saved'; message: string }
    | { kind: 'invalid'; errors: Record<string, string> }
    | { kind: 'failed'; message: string };

const FAILED: WriteOutcome = { kind: 'failed', message: "Couldn't save. Nothing was changed; try again." };

function outcome(answer: { status: number; body: unknown }): WriteOutcome {
    if (answer.status === 200 || answer.status === 201) return { kind: 'saved' };
    if (answer.status === 207) {
        return {
            kind: 'partly-saved',
            message: 'The new prescription was saved, but the old one could not be discontinued. Discontinue it from the list.',
        };
    }
    const errors = (answer.body as { errors?: unknown } | undefined)?.errors;
    if (answer.status === 400 && typeof errors === 'object' && errors !== null) {
        return { kind: 'invalid', errors: errors as Record<string, string> };
    }
    return FAILED;
}

const q = (patientId: string) => `patient=${encodeURIComponent(patientId)}`;

export async function addPrescription(client: ApiClient, patientId: string, form: PrescriptionForm): Promise<WriteOutcome> {
    return outcome(await client.sendJson('POST', `prescriptions?${q(patientId)}`, form));
}

export async function discontinuePrescription(client: ApiClient, patientId: string, rxId: string): Promise<WriteOutcome> {
    return outcome(await client.sendJson('POST', `prescriptions/${encodeURIComponent(rxId)}/discontinue?${q(patientId)}`, {}));
}

export async function replacePrescription(client: ApiClient, patientId: string, rxId: string, form: PrescriptionForm): Promise<WriteOutcome> {
    return outcome(await client.sendJson('POST', `prescriptions/${encodeURIComponent(rxId)}/replace?${q(patientId)}`, form));
}
```

- In `web/src/api/client.ts`, add `sendJson` to the `ApiClient` interface and to `createApiClient`. It calls `fetchImpl(\`${bffPath}/${path}\`, { method, credentials: 'same-origin', headers: { 'content-type': 'application/json' }, body: JSON.stringify(body) })`. A 401 calls `onUnauthenticated()` and returns `{ status: 401, body: undefined }`. Otherwise it returns `{ status, body: parsed JSON or undefined }`, and a thrown fetch returns `{ status: 0, body: undefined }`. The browser sets `Origin` on same-origin POSTs, so `guardWrite` accepts it.
- In `useBundleCard`, add a `revision = 0` parameter after `extraQuery`, and include it in the effect's dependency list. In `useListDates` and `useMedicationCards`, add `revision = 0` and pass it through. The "tagged by patient" return means the previous ready state stays on screen while the reload runs.
- In `CardFrame`, render `{actions}` inside the `<h3>` after the toggle button. The `<h3>` is already `d-flex justify-content-between`, so the actions sit at the right.

- [x] **Step 4: Run to verify they pass, then the full unit suite**

Run: `npm test`
Expected: all pass.

- [x] **Step 5: Commit**

```bash
git add patient-dashboard/web/src/api/client.ts patient-dashboard/web/src/api/prescriptionWrites.ts patient-dashboard/web/src/hooks/useBundleCard.ts patient-dashboard/web/src/hooks/useMedicationCards.ts patient-dashboard/web/src/cards/CardFrame.tsx patient-dashboard/tests/unit/api/prescriptionWrites.test.ts patient-dashboard/tests/unit/hooks/useMedicationCards.test.tsx patient-dashboard/tests/unit/cards/CardFrame.test.tsx
git commit -m "feat(dashboard): page-side prescription writes, card reloads and a title-bar action slot"
```

#### Task 6: The prescription form

**Files:**
- Create: `patient-dashboard/web/src/cards/PrescriptionForm.tsx`
- Test: `patient-dashboard/tests/unit/cards/PrescriptionForm.test.tsx`

**Interfaces:**
- Consumes: `PrescriptionForm`, `WriteOutcome` (Task 5)
- Produces: `<PrescriptionFormPanel initial={Partial<PrescriptionForm>} prescriberDefault={string} today={string} nowText={() => string} onSave={(form: PrescriptionForm) => Promise<WriteOutcome>} onCancel={() => void} />`

- [x] **Step 1: Write the failing tests**

```tsx
// tests/unit/cards/PrescriptionForm.test.tsx
// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { PrescriptionFormPanel } from '../../../web/src/cards/PrescriptionForm';

afterEach(cleanup);
const base = { prescriberDefault: 'Donna Lee', today: '2026-09-27', nowText: () => '2026-09-27 12:00:00', onCancel: vi.fn() };

describe('PrescriptionFormPanel', () => {
    it('starts with today, 0 refills and the signed-in prescriber, and sends what was typed', async () => {
        const onSave = vi.fn(async () => ({ kind: 'saved' }) as const);
        render(<PrescriptionFormPanel {...base} initial={{}} onSave={onSave} />);
        expect((screen.getByLabelText('Prescriber') as HTMLInputElement).value).toBe('Donna Lee');
        fireEvent.change(screen.getByLabelText('Drug'), { target: { value: 'Amoxicillin 500 mg' } });
        fireEvent.change(screen.getByLabelText('Quantity'), { target: { value: '21' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save' }));
        await waitFor(() => expect(onSave).toHaveBeenCalledTimes(1));
        expect(onSave).toHaveBeenCalledWith({
            drug: 'Amoxicillin 500 mg', dosage: '', quantity: '21', refills: 0,
            startDate: '2026-09-27', dateAdded: '2026-09-27 12:00:00', prescriber: 'Donna Lee',
        });
    });
    it('saves once however often Save is clicked while saving (Review Focus 1)', async () => {
        let finish: (value: { kind: 'saved' }) => void = () => undefined;
        const onSave = vi.fn(() => new Promise<{ kind: 'saved' }>((resolve) => (finish = resolve)));
        render(<PrescriptionFormPanel {...base} initial={{ drug: 'Amoxicillin' }} onSave={onSave} />);
        const save = screen.getByRole('button', { name: 'Save' });
        fireEvent.click(save);
        fireEvent.click(save);
        fireEvent.submit(save.closest('form') as HTMLFormElement);
        expect(onSave).toHaveBeenCalledTimes(1);
        expect((save as HTMLButtonElement).disabled).toBe(true);
        finish({ kind: 'saved' });
    });
    it('shows the server message beside each field it names, and keeps what was typed', async () => {
        const onSave = vi.fn(async () => ({ kind: 'invalid', errors: { quantity: 'Quantity must be a whole number' } }) as const);
        render(<PrescriptionFormPanel {...base} initial={{ drug: 'Amoxicillin', quantity: '2.5' }} onSave={onSave} />);
        fireEvent.click(screen.getByRole('button', { name: 'Save' }));
        expect(await screen.findByText('Quantity must be a whole number')).toBeTruthy();
        expect((screen.getByLabelText('Quantity') as HTMLInputElement).value).toBe('2.5');
    });
    it('explains where the prescriber is saved', () => {
        render(<PrescriptionFormPanel {...base} initial={{}} onSave={vi.fn()} />);
        expect(screen.getByText(/saved in the prescription's note/)).toBeTruthy();
    });
});
```

- [x] **Step 2: Run to verify it fails**

Run: `npx vitest run tests/unit/cards/PrescriptionForm.test.tsx`
Expected: FAIL, "Cannot find module".

- [x] **Step 3: Implement `web/src/cards/PrescriptionForm.tsx`**

Fields, in the old form's order: Drug, Directions (`dosage`), Quantity, Refills (a select of 0–20, as the old form's `refills_array`), Start date (`type="date"`), and Prescriber. Under Prescriber, in `small text-muted`: "Saved in the prescription's note. OpenEMR's own prescriber field stays empty; see the dashboard's notes." Use Bootstrap `form-group`, `form-control` and `btn btn-primary` / `btn btn-link`.

Behaviour:
- A `saving` state, set before `onSave` is called and cleared after. `submit` returns early while `saving`, and Save is `disabled={saving}`.
- An `invalid` outcome shows `errors[field]` under that field (`invalid-feedback d-block`), and `errors.form` or `errors.dateAdded` above the buttons.
- A `failed` or `partly-saved` outcome shows its message in `role="alert"`.
- A `saved` outcome is handled by the card (Task 7).
- `dateAdded` is read from `nowText()` at the moment Save is pressed, not when the form opens.

- [x] **Step 4: Run to verify it passes**

Run: `npx vitest run tests/unit/cards/PrescriptionForm.test.tsx`
Expected: PASS.

- [x] **Step 5: Commit**

```bash
git add patient-dashboard/web/src/cards/PrescriptionForm.tsx patient-dashboard/tests/unit/cards/PrescriptionForm.test.tsx
git commit -m "feat(dashboard): the prescription add and change form"
```

#### Task 7: The editable Prescriptions card, "Edit in OpenEMR" on the other cards, and wiring

**Files:**
- Modify: `patient-dashboard/web/src/cards/PrescriptionsCard.tsx`
- Create: `patient-dashboard/web/src/cards/EditInOpenEmr.tsx`
- Modify: `patient-dashboard/web/src/cards/AllergiesCard.tsx`, `ProblemListCard.tsx`, `MedicationsCard.tsx`, `CareTeamCard.tsx`: pass `actions={<EditInOpenEmr patientId={patientId} />}`
- Modify: `patient-dashboard/web/src/app/App.tsx`
- Test: `patient-dashboard/tests/unit/cards/PrescriptionsCard.test.tsx` (new cases), `patient-dashboard/tests/unit/cards/EditInOpenEmr.test.tsx`

**Interfaces:**
- Consumes: Tasks 5 and 6
- Produces:
  - `PrescriptionsCard` props: `{ patientId; state; editing?: { prescriberDefault: string; today: string; nowText: () => string; add(form): Promise<WriteOutcome>; change(rxId, form): Promise<WriteOutcome>; discontinue(rxId): Promise<WriteOutcome> } }`. Without `editing`, the card is read-only, as today.
  - `<EditInOpenEmr patientId={string} />`

- [x] **Step 1: Write the failing tests**

```tsx
// tests/unit/cards/EditInOpenEmr.test.tsx
// @vitest-environment jsdom
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { EditInOpenEmr } from '../../../web/src/cards/EditInOpenEmr';

afterEach(cleanup);

describe('EditInOpenEmr', () => {
    it("opens the patient's chart in OpenEMR in a new tab, with no link back to the dashboard", () => {
        render(<EditInOpenEmr patientId="a2d68325-7821-4a53-aa27-816ce437150f" />);
        const link = screen.getByRole('link', { name: 'Edit in OpenEMR' });
        expect(link.getAttribute('href')).toBe('/openemr/patient/a2d68325-7821-4a53-aa27-816ce437150f');
        expect(link.getAttribute('target')).toBe('_blank');
        expect(link.getAttribute('rel')).toBe('noopener noreferrer');
        expect(link.getAttribute('title')).toBe(
            'Changes are made in OpenEMR. Opens in a new tab; you may need to sign in, then click again.',
        );
    });
});
```

Add to `tests/unit/cards/PrescriptionsCard.test.tsx`:

```tsx
    const editing = (overrides = {}) => ({
        prescriberDefault: 'Donna Lee', today: '2026-09-27', nowText: () => '2026-09-27 12:00:00',
        add: vi.fn(async () => ({ kind: 'saved' }) as const),
        change: vi.fn(async () => ({ kind: 'saved' }) as const),
        discontinue: vi.fn(async () => ({ kind: 'saved' }) as const),
        ...overrides,
    });

    it('offers Add in the title bar, and Change and Discontinue on each row, when editing is allowed', () => {
        render(<PrescriptionsCard patientId="p1" state={{ status: 'ready', data: [{ ...omeprazole, dosage: '' }] }} editing={editing()} />);
        expect(screen.getByRole('button', { name: 'Add prescription' })).toBeTruthy();
        expect(screen.getByRole('button', { name: `Change ${omeprazole.name}` })).toBeTruthy();
        expect(screen.getByRole('button', { name: `Discontinue ${omeprazole.name}` })).toBeTruthy();
    });
    it('asks before discontinuing, and only discontinues on Yes', async () => {
        const e = editing();
        render(<PrescriptionsCard patientId="p1" state={{ status: 'ready', data: [{ ...omeprazole, dosage: '' }] }} editing={e} />);
        fireEvent.click(screen.getByRole('button', { name: `Discontinue ${omeprazole.name}` }));
        expect(screen.getByRole('alertdialog').textContent).toContain(`Discontinue ${omeprazole.name}?`);
        fireEvent.click(screen.getByRole('button', { name: 'Keep it' }));
        expect(e.discontinue).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('button', { name: `Discontinue ${omeprazole.name}` }));
        fireEvent.click(screen.getByRole('button', { name: 'Yes, discontinue' }));
        await waitFor(() => expect(e.discontinue).toHaveBeenCalledWith(omeprazole.id));
    });
    it('opens Change with the row filled in and sends the change for that row', async () => {
        const e = editing();
        render(<PrescriptionsCard patientId="p1" state={{ status: 'ready', data: [{ ...omeprazole, dosage: '1 daily', quantity: '30' }] }} editing={e} />);
        fireEvent.click(screen.getByRole('button', { name: `Change ${omeprazole.name}` }));
        expect((screen.getByLabelText('Drug') as HTMLInputElement).value).toBe(omeprazole.name);
        expect((screen.getByLabelText('Quantity') as HTMLInputElement).value).toBe('30');
        fireEvent.click(screen.getByRole('button', { name: 'Save' }));
        await waitFor(() => expect(e.change).toHaveBeenCalledWith(omeprazole.id, expect.objectContaining({ drug: omeprazole.name })));
    });
    it('stays read-only with no editing prop, so nothing changes for other callers', () => {
        render(<PrescriptionsCard patientId="p1" state={{ status: 'ready', data: [{ ...omeprazole, dosage: '' }] }} />);
        expect(screen.queryByRole('button', { name: 'Add prescription' })).toBeNull();
    });
```

(Add `vi`, `fireEvent` and `waitFor` to that file's imports.)

- [x] **Step 2: Run to verify they fail**

Run: `npx vitest run tests/unit/cards/EditInOpenEmr.test.tsx tests/unit/cards/PrescriptionsCard.test.tsx`
Expected: FAIL.

- [x] **Step 3: Implement**

`EditInOpenEmr.tsx`:

```tsx
/**
 * The "Edit in OpenEMR" link on the Allergies, Medical Problems, Medications and Care Team cards. OpenEMR's
 * write API cannot record those cards' clinical fields (an allergy's reaction and severity, a medication's
 * dosage, any care-team change), so editing happens in OpenEMR's own screens. The link goes through the BFF
 * (server/openemrLink.ts), which finds OpenEMR's patient number and redirects. It opens in a new tab so the
 * dashboard stays where it was; `noopener` stops OpenEMR's sign-out script from reaching back into this tab.
 */
const NOTE = 'Changes are made in OpenEMR. Opens in a new tab; you may need to sign in, then click again.';

export function EditInOpenEmr({ patientId }: { patientId: string }) {
    return (
        <a
            className="btn btn-link btn-sm p-0"
            href={`/openemr/patient/${encodeURIComponent(patientId)}`}
            target="_blank"
            rel="noopener noreferrer"
            title={NOTE}
        >
            Edit in OpenEMR
        </a>
    );
}
```

Also render the note as visible text once per card, in `small text-muted` under each of those four cards' content. The `title` alone is invisible on touch screens.

`PrescriptionsCard.tsx`:
- State: `mode: { kind: 'list' } | { kind: 'add' } | { kind: 'change'; row: MedicationView } | { kind: 'confirm'; row: MedicationView }`.
- **Add:** `actions={editing && <button className="btn btn-link btn-sm p-0" aria-label="Add prescription">Add</button>}`.
- **Row buttons:** each row gets a last cell holding `Change` (`aria-label={\`Change ${row.name}\`}`) and `Discontinue` (`aria-label={\`Discontinue ${row.name}\`}`).
- **Discontinue:** opens an inline `role="alertdialog"` reading `Discontinue <name>?`, with `Yes, discontinue` (`btn-danger`) and `Keep it`.
- **Change:** opens `PrescriptionFormPanel` with `initial={{ drug: row.name, dosage: row.dosage, quantity: row.quantity }}`.
- **After a write:** a `saved` outcome returns to the list. A `partly-saved` outcome returns to the list and shows its message in `role="alert"` above the table.

`App.tsx` (`PatientView`):
- Hold `const [revision, setRevision] = useState(0)`, and pass `revision` into `useMedicationCards(client, patientId, asOfTime, revision)`.
- Build `editing` for `PrescriptionsCard`. Each write calls the Task 5 function with `patientId`, then `setRevision((r) => r + 1)` on `saved` or `partly-saved`.
- `today`: `localToday()`; `nowText`: `localNow`; `prescriberDefault`: from Step 4 below.
- Because `PatientView` is keyed by `patientId`, a patient switch unmounts it. An in-flight write's result then has nowhere to land, which covers Review Focus 4. The card must not call `setState` after unmount: guard with a mounted ref, as the hooks already do.

- [x] **Step 4: The prescriber default**

In `App`:
- keep `userId` from `/auth/me`, which Task 4 adds
- look up `display-names?ref=Practitioner/<userId>` once through `client.getJson`
- use the returned name when it is not `"Name unavailable"` or `"Name couldn't be loaded"`, and `''` otherwise

Add a unit test in `tests/unit/app/` for the small parser that does this (`prescriberNameFrom(body: unknown, userId: string): string`), covering: a name found, a name unavailable, and a malformed answer.

- [x] **Step 5: Run the unit suite, type check, lint and format**

Run: `npm test && npm run typecheck && npm run lint && npx prettier --check .`
Expected: all pass, clean.

- [x] **Step 6: Commit**

```bash
git add patient-dashboard/web/src/cards/PrescriptionsCard.tsx patient-dashboard/web/src/cards/EditInOpenEmr.tsx patient-dashboard/web/src/cards/AllergiesCard.tsx patient-dashboard/web/src/cards/ProblemListCard.tsx patient-dashboard/web/src/cards/MedicationsCard.tsx patient-dashboard/web/src/cards/CareTeamCard.tsx patient-dashboard/web/src/app/App.tsx patient-dashboard/web/src/app/prescriberName.ts patient-dashboard/tests/unit/cards/PrescriptionsCard.test.tsx patient-dashboard/tests/unit/cards/EditInOpenEmr.test.tsx patient-dashboard/tests/unit/app/prescriberName.test.ts
git commit -m "feat(dashboard): add, change and discontinue prescriptions; Edit in OpenEMR on the other cards"
```

#### Task 8: Browser tests, including the old dashboard seeing the writes

**Files:**
- Create: `patient-dashboard/tests/e2e/prescriptionEdits.spec.ts`
- Modify: `patient-dashboard/tests/e2e/readonly.spec.ts`: becomes "no delete, and edit only where decided"
- Test: the same files

**Interfaces:**
- Consumes: `fixture('TP-RXEDIT')`, `logInThroughOpenEmr`, `PHYSICIAN`, `openOldDashboard`, `readOldCard`

- [x] **Step 1: Write the failing browser tests**

```ts
// tests/e2e/prescriptionEdits.spec.ts
import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';
import { fixture } from '../support/fixtures';
import { logInThroughOpenEmr, PHYSICIAN } from '../support/login';
import { openOldDashboard, readOldCard } from '../support/oldDashboard';

// Only TP-RXEDIT is written to; the parity fixtures are never changed (ARC-06 Global Constraints).
const RX = fixture('TP-RXEDIT');
const card = (page: Page) => page.locator('[data-card="prescriptions"]');
const drug = `E2E drug ${Date.now().toString(36)}`;

/** Discontinues every prescription on TP-RXEDIT through the card, so each test starts from none. */
async function clearPrescriptions(page: Page) {
    for (;;) {
        const discontinue = card(page).getByRole('button', { name: /^Discontinue / }).first();
        if ((await discontinue.count()) === 0) return;
        await discontinue.click();
        await page.getByRole('button', { name: 'Yes, discontinue' }).click();
        await expect(page.getByRole('alertdialog')).toHaveCount(0);
        await expect(card(page)).toHaveAttribute('data-state', 'ready');
    }
}

test.beforeEach(async ({ page }) => {
    await logInThroughOpenEmr(page, PHYSICIAN);
    await page.goto(`/patient/${RX.fhirId}`);
    await expect(card(page)).toHaveAttribute('data-state', 'ready');
    await clearPrescriptions(page);
});

test('a physician adds a prescription, and the old dashboard shows it with the same drug and quantity', async ({ page, browser }) => {
    await card(page).getByRole('button', { name: 'Add prescription' }).click();
    await page.getByLabel('Drug').fill(drug);
    await page.getByLabel('Quantity').fill('21');
    await page.getByRole('button', { name: 'Save' }).click();
    const row = card(page).locator('[data-item="prescription"]', { hasText: drug });
    await expect(row.locator('[data-field="quantity"]')).toHaveText('21');

    const old = await openOldDashboard(browser, RX.pid);
    const oldRx = await readOldCard(old, 'prescriptions_ps_expand');
    expect(oldRx.items.join('\n')).toContain(drug);
    await old.context().close();
});

test('Change adds the corrected prescription and discontinues the old one', async ({ page }) => {
    await card(page).getByRole('button', { name: 'Add prescription' }).click();
    await page.getByLabel('Drug').fill(drug);
    await page.getByLabel('Quantity').fill('21');
    await page.getByRole('button', { name: 'Save' }).click();
    await card(page).getByRole('button', { name: `Change ${drug}` }).click();
    await page.getByLabel('Quantity').fill('30');
    await page.getByRole('button', { name: 'Save' }).click();
    const rows = card(page).locator('[data-item="prescription"]', { hasText: drug });
    await expect(rows).toHaveCount(1);
    await expect(rows.locator('[data-field="quantity"]')).toHaveText('30');
});

test('Discontinue asks first, and a discontinued prescription leaves the card and the old dashboard', async ({ page, browser }) => {
    await card(page).getByRole('button', { name: 'Add prescription' }).click();
    await page.getByLabel('Drug').fill(drug);
    await page.getByRole('button', { name: 'Save' }).click();
    await card(page).getByRole('button', { name: `Discontinue ${drug}` }).click();
    await page.getByRole('button', { name: 'Keep it' }).click();
    await expect(card(page).locator('[data-item="prescription"]', { hasText: drug })).toHaveCount(1);
    await card(page).getByRole('button', { name: `Discontinue ${drug}` }).click();
    await page.getByRole('button', { name: 'Yes, discontinue' }).click();
    await expect(card(page).locator('[data-item="prescription"]', { hasText: drug })).toHaveCount(0);

    const old = await openOldDashboard(browser, RX.pid);
    expect((await readOldCard(old, 'prescriptions_ps_expand')).items.join('\n')).not.toContain(drug);
    await old.context().close();
});
```

Rewrite `readonly.spec.ts` (BM-013):

```ts
test('clinical cards offer no delete, and edit only where decided (BM-013, ARC-06)', async ({ page }) => {
    await logInThroughOpenEmr(page);
    await page.goto(`/patient/${fixture('TP-TYPICAL').fhirId}`);
    for (const name of ['allergies', 'problems', 'medications', 'care-team']) {
        const card = page.locator(`[data-card="${name}"]`);
        await expect(card, name).toHaveAttribute('data-state', 'ready');
        await expect(card.getByRole('button'), name).toHaveCount(1); // the collapse toggle only
        await expect(card.getByRole('link', { name: 'Edit in OpenEMR' }), name).toHaveCount(1);
        await expect(card.locator('input, select, textarea, form'), name).toHaveCount(0);
    }
    await expect(page.getByRole('button', { name: /delete/i })).toHaveCount(0);
});
```

Before relying on `readOldCard(old, 'prescriptions_ps_expand')`, check how `tests/parity/prescriptions.spec.ts` reads the old prescriptions card, and use the same helper and card id.

- [x] **Step 2: Run to verify they fail**

Run: `npx playwright test --project=e2e tests/e2e/prescriptionEdits.spec.ts tests/e2e/readonly.spec.ts`
Expected: FAIL. There is no "Add prescription" button yet on the running build, and no Edit in OpenEMR links. If Tasks 5–7 are already merged, prove red by breaking the code instead: comment out the `actions` render in `CardFrame`, watch the tests fail, and restore it.

- [x] **Step 3: Make them pass**

Fix what the tests show, in the owning task's files. Then run the whole suite: `npx playwright test`.
Expected: every end-to-end and parity test passes. Parity fixtures are untouched, and the parity counts are unchanged.

- [x] **Step 4: Commit**

```bash
git add patient-dashboard/tests/e2e/prescriptionEdits.spec.ts patient-dashboard/tests/e2e/readonly.spec.ts
git commit -m "test(dashboard): prescription edits end to end, checked against the old dashboard"
```

### Story 06-04 — Documented and deployed

**Acceptance:**
- The defence, the bug catalogue, the compliance report, the prescriptions module audit and the dev log describe exactly what was built and why.
- The droplet runs it, with a smoke test that adds and discontinues a prescription on the droplet's TP-RXEDIT.

#### Task 9: Documents

**Files:**
- Modify: `PATIENT_DASHBOARD_MIGRATION.md`
- Modify: `clinical_copilot_week2/migration/BUGS-MITIGATIONS.md`
- Modify: `clinical_copilot_week2/migration/CHALLENGE-COMPLIANCE.md`
- Modify: `clinical_copilot_week2/migration/modules/prescriptions.md`, `allergies.md`, `problem-list.md`, `medications.md`, `care-team.md`: the Controls tables
- Modify: `clinical_copilot_week2/migration/DEV-LOG.md`, this arc file

- [x] **Step 1: Bug catalogue rows**

Add these rows, using the table's existing column order:
- **BM-062:** OpenEMR's write API cannot record an allergy's reaction, severity, outcome or verification, a problem's comments, or a medication's dosage instructions (whitelists in `AllergyIntoleranceRestController.php:38-44`, `ConditionRestController.php:38-43`, `ListService.php:186-209`).
  - Port action: `Edit in OpenEMR`.
  - Mitigation: those three cards link to OpenEMR's chart page.
- **BM-063:** no API route can change a care team.
  - Port action: `Edit in OpenEMR`.
  - Mitigation: a possible custom-module follow-up.
- **BM-064:** there is no prescription update route; `POST /api/prescription` sets none of the old form's defaults.
  - Port action: `fix in the new app`.
  - Mitigation: change = add, then discontinue; the BFF sets the defaults.
- **BM-065:** prescription writes check `patients/med`, not `patients/rx`, and the prescriber number is admin-only.
  - Port action: `accepted`.
  - Mitigation: no default group widens; the prescriber is typed into the note, and OpenEMR's prescriber field stays empty.
- **BM-066:** the API's allergy, problem and medication deletes are hard deletes that only superusers can do in the old UI.
  - Port action: `not used`.
  - Mitigation: the dashboard never deletes.

Update BM-013's text: the dashboard now edits prescriptions, and every other card is read-only with a link.

- [x] **Step 2: The defence**

In `PATIENT_DASHBOARD_MIGRATION.md`:
- **"What was ported":** Prescriptions now says "add, change, discontinue", and the other four rows say "Edit in OpenEMR".
- **"Tradeoffs":** a new bullet, *Editing through OpenEMR's write API*, with:
  - the fields it cannot record
  - no prescription update, so change is add-then-discontinue
  - the prescriber held in the note, not OpenEMR's prescriber field
  - `patients/med` rather than `patients/rx`
  - the "Edit in OpenEMR" experience: no menu, the sign-in, landing on the home screen
  - "a backend change (a custom module with write routes) is a possible follow-up"
- **"Not ported":** replace the "edit workflows" line with the precise list.
- **Parity evidence:** add the write tests and their counts.

- [x] **Step 3: Compliance, module audits, dev log, arc file**

- `CHALLENGE-COMPLIANCE.md`: rows 4 and 10 and the Verdict. The port is no longer read-only.
- Each module file's Controls table: the new behaviour for its edit control.
- `DEV-LOG.md`: one entry per story, plus an arc-completion entry.
- This arc file: tick every step, and set Status to complete.

- [x] **Step 4: Commit**

```bash
git add PATIENT_DASHBOARD_MIGRATION.md clinical_copilot_week2/migration/BUGS-MITIGATIONS.md clinical_copilot_week2/migration/CHALLENGE-COMPLIANCE.md clinical_copilot_week2/migration/modules/prescriptions.md clinical_copilot_week2/migration/modules/allergies.md clinical_copilot_week2/migration/modules/problem-list.md clinical_copilot_week2/migration/modules/medications.md clinical_copilot_week2/migration/modules/care-team.md clinical_copilot_week2/migration/DEV-LOG.md clinical_copilot_week2/migration/arcs/ARC-06-EDITING.md
git commit -m "docs(dashboard): record prescription editing and Edit in OpenEMR, BM-062 to BM-066"
```

#### Task 10: Droplet: client scope, test patient, deploy, smoke test

This task writes to the shared droplet. **Ask the user for the go-ahead before Step 2, and tell the peer session before Step 3.**

**Files:**
- Modify: `patient-dashboard/tests/deployed/deployed.spec.ts`
- Modify: `clinical_copilot_week2/migration/fixtures/fixture-ids.droplet.json` (via the seed script)
- Modify: `patient-dashboard/deploy/README.md`

- [x] **Step 1: Write the failing deployed smoke test**

```ts
test('a physician adds and discontinues a prescription on the droplet', async ({ page }) => {
    await logInThroughOpenEmr(page, USER);
    await page.getByLabel('Find a patient').fill('RxEdit');
    await page.getByRole('search').evaluate((form) => (form as HTMLFormElement).requestSubmit());
    await page.getByRole('button', { name: /^Rita RxEdit/ }).click();
    const card = page.locator('[data-card="prescriptions"]');
    const drug = `Smoke drug ${Date.now().toString(36)}`;
    await card.getByRole('button', { name: 'Add prescription' }).click();
    await page.getByLabel('Drug').fill(drug);
    await page.getByRole('button', { name: 'Save' }).click();
    await expect(card.locator('[data-item="prescription"]', { hasText: drug })).toHaveCount(1);
    await card.getByRole('button', { name: `Discontinue ${drug}` }).click();
    await page.getByRole('button', { name: 'Yes, discontinue' }).click();
    await expect(card.locator('[data-item="prescription"]', { hasText: drug })).toHaveCount(0);
});
```

Run: `npx playwright test -c playwright.deployed.config.ts`
Expected: the new test FAILS, because there is no Add button on the droplet yet. The other five pass.

- [x] **Step 2: Re-register the droplet app client, then seed TP-RXEDIT**

After the user's go-ahead:

```bash
cd clinical_copilot_week2/migration/spike
B=https://146-190-139-37.sslip.io
OEMR_BASE=$B SPIKE_ENV_FILE=.env.droplet APP_REDIRECT=https://dashboard.146-190-139-37.sslip.io/auth/callback node register-client.mjs app --replace
```

Then:
- Enable the new client on the droplet: `UPDATE oauth_clients SET is_enabled = 1 WHERE client_id = '<new id>'`.
- Put the new id, secret and scope in `~/patient-dashboard/.env` on the droplet.
- Disable the old client.
- Run `OEMR_BASE=$B SPIKE_ENV_FILE=.env.droplet FIXTURE_IDS_FILE=fixture-ids.droplet.json node ../fixtures/seed-rxedit.mjs`.

- [x] **Step 3: Deploy and run the smoke tests**

Tell the peer session first. Then run `patient-dashboard/deploy/deploy.sh`, then `npx playwright test -c playwright.deployed.config.ts`.
Expected: 6 of 6 pass.

- [x] **Step 4: Record and commit**

`deploy/README.md` gets:
- the new scope
- the TP-RXEDIT seed step
- the note that the "Edit in OpenEMR" link needs the dashboard and OpenEMR on the same site, because OpenEMR's session cookie is `SameSite=Strict`

```bash
git add patient-dashboard/tests/deployed/deployed.spec.ts patient-dashboard/deploy/README.md clinical_copilot_week2/migration/fixtures/fixture-ids.droplet.json
git commit -m "feat(dashboard): prescription editing on the droplet, with a smoke test"
```

## Definition of Done for this arc

- [x] Tasks 1–10 ticked, and every step's Expected line compared with real output
- [x] `npm test`, `npm run lint`, `npm run typecheck` and `npx prettier --check .` are clean
- [x] `npx playwright test` is fully green, with the parity counts unchanged
- [x] The deployed smoke tests pass: 6 of 6
- [x] BM-062 to BM-066 are in the catalogue, and BM-013 is updated
- [x] The DEV-LOG has an arc-completion entry
