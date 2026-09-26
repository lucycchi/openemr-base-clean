# Patient Dashboard Migration Docs Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Produce every document needed before building the new patient dashboard: the auth spikes, test fixtures, inventory, per-module audits, bug catalogue, migration options, the graded defence document and the migration spec. The plan stops at a user gate after each phase.

**Architecture:** This plan mostly produces documents. Its code is limited to throwaway spike scripts that prove login and data access, and a seed script that creates synthetic test patients. Documents go in `clinical_copilot_week2/migration/`, except `PATIENT_DASHBOARD_MIGRATION.md`, which goes in the repo root. Shell checkers act as the tests for the documents: each one fails while a document is incomplete and passes once it's done, and each exits non-zero on any failure. The auth architecture and the framework are decided by the user at gates, not by this plan.

**Tech Stack:** Node 24 (on the host at `~/.nvm`) for the spikes and seeding, with no npm dependencies; Bash and awk for the checkers; a real browser for the auth spikes; the OpenEMR dev stack.

**Spec:** `clinical_copilot_week2/migration/PLANNING-BRIEF.md`. Read it in full before Task 0. The challenge PDF it cites is `clinical_copilot_week2/AgentForge — Clinical Co-Pilot W2 — Surprise Challenge_ Modernize the Patient Dashboard (1).pdf`.

**Review history:**
- **Version 1: Codex review (2026-09-26), 28 findings.** The two with the biggest effect:
  - OpenEMR refuses `user/*` scopes for public clients (`src/RestControllers/AuthorizationController.php:325-330`), so the spike now tests two auth architectures.
  - FHIR search bundles carry only a `self` link, and `_count` defaults to 0 (`src/Services/FHIR/FhirResourcesService.php:33`, `src/Services/Search/SearchQueryConfig.php:52`), so the paging concern became a list-completeness concern.
- **Version 2: Fable review (2026-09-26).** Two blockers are fixed in version 3:
  - API routes are dispatched at request-listener priority 40, before `CORSListener` at 25 (`src/RestControllers/Subscriber/RoutesExtensionListener.php:28`, `CORSListener.php:20`). A browser preflight to the FHIR API therefore never gets a proper CORS answer. Spike A now tests calling the API directly (expected to fail) and through a same-origin proxy.
  - Medication dates must be `Y-m-d H:i:s` (`src/Services/ListService.php:42-43`).

**Time budget:** the week is short, so hold to these:
- Phase 0 (Tasks 0–5): one day, including Docker set-up and manual fixture entry.
- Phases 1–2 (Tasks 6–15): one and a half days.
- Phases 3–5 (Tasks 16–18): half a day.

The cut list, applied in this order if a phase runs over:
1. Merge the Medications and Prescriptions audits into one sitting (Tasks 11–12).
2. Cut the light review to the three most likely extra sections.
3. Skip the `TP-LONG` checks for allergies.

Confirm the cut list with the user at Gate 1.

## Global Constraints

- Do not modify the OpenEMR PHP backend, database schema or API. Admin-UI configuration changes are allowed, and each one must be recorded in `API-SPIKE.md` `## Settings changed`.
- Do not redesign the interface. Feature parity with the current dashboard is the standard.
- Data comes from OpenEMR's REST and FHIR APIs. The challenge says the clinical cards pull "live data from the FHIR API", so using the Standard REST API for any clinical card needs the user's explicit approval at Gate 2.
- All documents go in `clinical_copilot_week2/migration/`, except `PATIENT_DASHBOARD_MIGRATION.md`, which goes in the repo root.
- A "module" is one thing the user sees on the dashboard plus all the code behind it, not one PHP file.
- Every bug gets exactly one port action: `keep for parity`, `fix in the new app` or `out of scope`.
- Stop at each gate and wait for the user. Ask every question as a full plain-language sentence, with no shorthand labels or option codes.
- Any blocker found before a gate must end in one of three outcomes: resolved and re-verified, accepted by the user as a written limitation, or a stop. Never carry an unresolved blocker into the next phase.
- The user chooses the auth architecture and the framework at Gate 3. Do not choose either for them.
- `spike/.env.local`, `spike/dev-cert.pem` and `spike/results-*.json` are gitignored. Never commit client secrets, tokens or passwords. Test patients are synthetic, with no real patient data anywhere.
- Never disable TLS verification (no `curl -k`, no `NODE_TLS_REJECT_UNAUTHORIZED=0`). Trust the dev certificate explicitly.
- Commits go to the branch agreed in Task 0. They use Conventional Commits with an `Assisted-by: Claude Code` trailer, and end with the executing session's own `Co-Authored-By` attribution line. The commands below show this session's line; swap in your own if it differs.
- When a document value is genuinely unknown, write `{{FILL}}`. `check-doc.sh` fails on it, so nothing unfinished slips through.
- Markdown table cells must not contain a literal `|`, because the checkers split rows on it.

## Review Focus

These are the five failure modes most likely to hurt a clinician using the finished dashboard, with the check each is pinned to.

1. **Patient-scoped tokens and patient switching.** Under the browser-only architecture, a token covers one patient. A request whose `patient=` names a different patient is not refused. The server quietly substitutes the launched patient and returns HTTP 200 (`apis/routes/_rest_routes_fhir_r4_us_core_3_1_0.inc.php:75-77`, `src/RestControllers/Authorization/BearerTokenAuthorizationStrategy.php:434-446`). So an app that switches patients without a new launch would show the old patient's data under the new patient's name. *Pinned in:* Task 4's spike asks for another patient's allergies and records whether the entries come back as the launched patient's. Task 18 requires a patient-switching E2E test that checks every card's data belongs to the patient named in the header.
2. **FHIR fields that are hard-coded or derived differently from what the card shows.** Known cases: `Patient.active` is always `true` (`FhirPatientService.php:212`); `CareTeam.status` defaults to `active` when missing or invalid (`FhirCareTeamService.php:126`); allergy `clinicalStatus` is derived from `outcome` and `enddate` (`FhirAllergyIntoleranceService.php:117-128`). *Pinned in:* every mapping row needs a "Checked against" value that names a fixture key and the value actually observed, and `check-module-doc.sh` rejects rows without one.
3. **A card showing a different set of rows from the old dashboard.** Examples: the `MedicationRequest` UNION leaves out list rows linked to prescriptions (`PrescriptionService.php:260`); `Condition` also returns non-problem-list categories; the old cards filter out some rows. *Pinned in:* a mandatory `List completeness` mapping row, checked against `TP-TYPICAL`, `TP-HISTORY` and `TP-LONG` counts (enforced by `check-module-doc.sh`).
4. **An error shown as an empty card, which reads as "no allergies".** *Pinned in:* Tasks 4 and 5 record the exact status and body of an out-of-scope read and a bad-token read. Task 18 turns them into required error-state tests.
5. **A card that is visible in one dashboard but hidden in the other.** The old dashboard hides cards through the `hide_dashboard_cards` global, read directly from SQL in `getHiddenDashboardCards()` at `demographics.php:138`, as well as through ACL checks and other globals. *Pinned in:* every module doc must fill in `**Visibility rules:**` (enforced by the checker), and Gate 2 asks the user how the new app gets each rule.

---

## File map

| File | Created in | Purpose |
|---|---|---|
| `migration/spike/.gitignore` | Task 1 | keeps secrets, the cert and results out of git |
| `migration/spike/lib.mjs` | Task 1 | shared env, fetch-with-timeout and results helpers |
| `migration/spike/check-config.mjs` | Task 1 | FHIR discovery and CORS preflight record |
| `migration/spike/register-client.mjs` | Task 2 | registers the `public`, `bff` or `seed` client |
| `migration/fixtures/seed.mjs` | Task 3 | creates the synthetic test patients through the Standard API |
| `migration/fixtures/.gitignore` | Task 3 | keeps `fixture-ids.partial.json` out of git |
| `migration/fixtures/fixture-ids.json` | Task 3 | fixture key → pid, uuid, FHIR id (synthetic, committed) |
| `migration/TEST-PATIENTS.md` | Task 3 | fixture roster and manual UI steps |
| `migration/spike/public/serve.mjs`, `public/index.html` | Task 4 | Spike A: browser-only public client, called directly and through a same-origin proxy |
| `migration/spike/bff/server.mjs` | Task 5 | Spike B: backend-for-frontend with a confidential client |
| `migration/API-SPIKE.md` | Tasks 1–5 | settings, clients, scopes, results, blockers |
| `migration/tools/check-doc.sh` | Task 1 | generic heading and placeholder checker |
| `migration/INVENTORY.md` | Task 6 | tree and scope table |
| `migration/tools/check-inventory.sh` | Task 6 | partial coverage check for the inventory |
| `migration/modules/_TEMPLATE.md` | Task 7 | audit template |
| `migration/tools/check-module-doc.sh` | Task 7 | module doc checker |
| `migration/tools/check-bugs.sh` | Task 7 | catalogue checker |
| `migration/BUGS-MITIGATIONS.md` | Task 7, filled in Tasks 8–15 | bug catalogue |
| `migration/modules/*.md` | Tasks 8–13, 15 | one audit per in-scope module |
| `migration/EXTRA-SECTION-OPTIONS.md` | Task 14 | extra-section comparison |
| `migration/MIGRATION-OPTIONS.md` | Task 16 | auth and framework routes |
| `PATIENT_DASHBOARD_MIGRATION.md` (repo root) | Task 17 | graded defence |
| `migration/MIGRATION-SPEC.md`, `DEV-LOG.md`, `arcs/*.md` | Task 18 | build playbook |

`migration/` means `clinical_copilot_week2/migration/`. All commands run from the repo root, `/home/lucyc/gauntlet/openemr-base-clean`, unless a step says otherwise.

---

## Phase 0: Set-up and auth spikes

### Task 0: Agree the branch and who commits

**Files:** none.

- [ ] **Step 1: Ask the user two questions, before any commit**

1. "The repo is on the `pdf_reader` branch. Should this work go on a new branch, for example `dashboard-migration` created from the current commit, or stay on `pdf_reader`?"
2. "Should I commit each task to that branch myself and leave merging to `main` to you, or do you want to review and commit every change yourself?"

- [ ] **Step 2: Act on the answers**

If the user chooses a new branch:

```bash
git switch -c dashboard-migration
```

Write both answers into `migration/API-SPIKE.md` under `## Working agreement` in Task 1. If the user chose to commit everything themselves, every "Commit" step below becomes "stage the files and tell the user they are ready".

### Task 1: Environment, certificate and config record

**Files:**
- Create: `clinical_copilot_week2/migration/spike/.gitignore`
- Create: `clinical_copilot_week2/migration/spike/lib.mjs`
- Create: `clinical_copilot_week2/migration/spike/check-config.mjs`
- Create: `clinical_copilot_week2/migration/tools/check-doc.sh`
- Create: `clinical_copilot_week2/migration/API-SPIKE.md`

**Interfaces:**
- Produces:
  - `lib.mjs` exports `readEnv(): Record<string,string>`, `writeEnv(env): void` (file mode 0600), `OEMR_BASE: string`, `FHIR_BASE: string`, `fetchText(url, init?, timeoutMs = 15000): Promise<{status, headers, text, json}>` (never throws on HTTP errors; throws on network errors and timeouts), and `writeResults(name, obj): void` (writes `spike/results-<name>.json`).
  - `check-doc.sh FILE HEADING...` exits 0 only when every heading is present and no placeholder is found.
  - `API-SPIKE.md` with the headings `## Working agreement`, `## Settings changed`, `## Discovery and CORS`, `## Clients`, `## Spike A: browser-only public client`, `## Spike B: backend-for-frontend`, `## Error responses`, `## Blockers and decisions`.

- [ ] **Step 1: Start the dev stack**

`docker` isn't on the WSL path at the moment. In Docker Desktop, open Settings → Resources → WSL Integration and turn it on for this distro. Then:

```bash
cd /home/lucyc/gauntlet/openemr-base-clean/docker/development-easy && docker compose up --detach --wait
curl -s -o /dev/null -w '%{http_code}\n' --max-time 10 http://localhost:8300/
```

Expected: `200` or `302`. If it prints `000`, stop and tell the user that the dev stack won't start.

- [ ] **Step 2: Save and check the dev certificate**

```bash
mkdir -p clinical_copilot_week2/migration/spike
cd clinical_copilot_week2/migration/spike
openssl s_client -connect localhost:9300 -servername localhost </dev/null 2>/dev/null | openssl x509 > dev-cert.pem
openssl x509 -in dev-cert.pem -noout -subject -ext subjectAltName
NODE_EXTRA_CA_CERTS=dev-cert.pem node -e "fetch('https://localhost:9300/', { redirect: 'manual' }).then((r) => console.log('node TLS ok, HTTP', r.status)).catch((e) => { console.error('node TLS FAIL:', e.cause?.code ?? e.message); process.exit(1); })"
```

Expected: the certificate's subject or SAN names `localhost`, and Node prints `node TLS ok, HTTP 200` or `302`. The Node check matters because Node and the browser are what the spikes use, and Node needs the name in the SAN. If Node reports `ERR_TLS_CERT_ALTNAME_INVALID` or an untrusted certificate, stop and ask the user how to proceed.

Also ask the user to trust `dev-cert.pem` in the browser they'll use for Tasks 4 and 5. They can import it into the OS or browser certificate store, or visit https://localhost:9300 once and accept the certificate. Without this, browser `fetch` calls to OpenEMR fail with a network error.

- [ ] **Step 3: Enable the API and record every setting**

Log in at https://localhost:9300 as `admin` / `pass`. Under Administration → Config → Connectors:
- tick "Enable OpenEMR Standard REST API" (`rest_api`) and "Enable OpenEMR Standard FHIR REST API" (`rest_fhir_api`)
- read "Site Address" (`site_addr_oath`). The dev compose file already sets it (`docker/development-easy/docker-compose.yml:86`). Record the value, and only change it if it isn't `https://localhost:9300`.
- set "Enable OAuth2 Password Grant" (`oauth_password_grant`) to **"On for Users Role"** (value 1). It's a four-way dropdown (`library/globals.inc.php:3281-3284`), and the seeder's `user_role=users` works only with value 1 or 3. This is for fixture seeding on the dev stack only.
- read and record the current value of `oauth_app_manual_approval`. Do not change it.

Record each setting's old and new value under `## Settings changed`.

- [ ] **Step 4: Write the gitignore and the shared helpers**

`clinical_copilot_week2/migration/spike/.gitignore`:

```
.env.local
dev-cert.pem
results-*.json
```

`clinical_copilot_week2/migration/spike/lib.mjs`:

```js
// Shared helpers for the throwaway auth spikes and the fixture seeder.
// Not product code. Run every script with NODE_EXTRA_CA_CERTS pointing at
// spike/dev-cert.pem; TLS verification stays on.
import { readFileSync, writeFileSync, existsSync } from 'node:fs';

const SPIKE_DIR = new URL('./', import.meta.url);
const ENV_FILE = new URL('.env.local', SPIKE_DIR);

export const OEMR_BASE = process.env.OEMR_BASE ?? 'https://localhost:9300';
export const FHIR_BASE = `${OEMR_BASE}/apis/default/fhir`;

export function readEnv() {
  if (!existsSync(ENV_FILE)) return {};
  return Object.fromEntries(
    readFileSync(ENV_FILE, 'utf8')
      .split('\n')
      .filter((line) => line.includes('='))
      .map((line) => {
        const i = line.indexOf('=');
        return [line.slice(0, i).trim(), line.slice(i + 1).trim()];
      }),
  );
}

export function writeEnv(env) {
  const body = Object.entries(env).map(([k, v]) => `${k}=${v}`).join('\n') + '\n';
  writeFileSync(ENV_FILE, body, { mode: 0o600 });
}

// Returns the response whatever its status. Network failures and timeouts throw.
export async function fetchText(url, init = {}, timeoutMs = 15000) {
  const res = await fetch(url, { ...init, signal: AbortSignal.timeout(timeoutMs) });
  const text = await res.text();
  let json = null;
  try {
    json = JSON.parse(text);
  } catch {
    json = null;
  }
  return { status: res.status, headers: res.headers, text, json };
}

export function writeResults(name, results) {
  writeFileSync(new URL(`results-${name}.json`, SPIKE_DIR), JSON.stringify(results, null, 2) + '\n');
}
```

- [ ] **Step 5: Write the config record script**

`clinical_copilot_week2/migration/spike/check-config.mjs`:

```js
// Throwaway spike: checks FHIR discovery and records the CORS preflight
// answers. The preflight is expected to fail: API routes are dispatched
// (RoutesExtensionListener, priority 40) before CORSListener (priority 25)
// can answer OPTIONS. Node does not enforce CORS, so Task 4 tests it in a
// real browser. Only the discovery checks decide PASS.
import { fetchText, FHIR_BASE, writeResults } from './lib.mjs';

const checks = [];
const observations = {};
const check = (name, ok, detail = '') => {
  checks.push({ name, ok: Boolean(ok), detail });
  console.log(`${ok ? 'ok  ' : 'FAIL'} ${name} ${detail}`);
};

try {
  const disc = await fetchText(`${FHIR_BASE}/.well-known/smart-configuration`);
  check('discovery HTTP 200', disc.status === 200, `HTTP ${disc.status}`);
  check('discovery is JSON', disc.json !== null);
  check('S256 PKCE advertised', disc.json?.code_challenge_methods_supported?.includes('S256'));
  observations.authorizationEndpoint = disc.json?.authorization_endpoint;
  observations.tokenEndpoint = disc.json?.token_endpoint;
  observations.capabilities = disc.json?.capabilities ?? [];

  for (const origin of ['http://localhost:5174', 'http://localhost:5175', 'https://unrelated.example']) {
    const pre = await fetchText(`${FHIR_BASE}/Patient`, {
      method: 'OPTIONS',
      headers: {
        Origin: origin,
        'Access-Control-Request-Method': 'GET',
        'Access-Control-Request-Headers': 'authorization',
      },
    });
    const allowOrigin = pre.headers.get('access-control-allow-origin');
    const allowHeaders = (pre.headers.get('access-control-allow-headers') ?? '')
      .split(',')
      .map((h) => h.trim().toLowerCase());
    const usable = pre.status >= 200 && pre.status < 300 && allowOrigin === origin && allowHeaders.includes('authorization');
    observations[origin] = {
      status: pre.status,
      usableByABrowser: usable,
      allowOrigin,
      allowHeaders,
      allowMethods: pre.headers.get('access-control-allow-methods'),
      allowCredentials: pre.headers.get('access-control-allow-credentials'),
      bodyStart: pre.text.slice(0, 200),
    };
    console.log(`observed: preflight from ${origin} -> HTTP ${pre.status}, usable by a browser: ${usable}`);
  }
} catch (e) {
  check('requests completed', false, `${e.name}: ${e.message}`);
}

const pass = checks.length > 0 && checks.every((c) => c.ok);
writeResults('config', { pass, checks, observations });
console.log(JSON.stringify(observations, null, 2));
console.log(pass ? 'PASS' : 'FAIL');
process.exitCode = pass ? 0 : 1;
```

- [ ] **Step 6: Run it**

```bash
cd clinical_copilot_week2/migration/spike && NODE_EXTRA_CA_CERTS=dev-cert.pem node check-config.mjs
```

Expected: `PASS` on the discovery checks, and three `observed:` lines. Each preflight is expected to show a non-2xx status and `usable by a browser: false`. Copy the observations into `## Discovery and CORS`; they are the evidence for the CORS rows in Task 7. If a preflight turns out to be usable, write that down too, because it means the Apache configuration answers OPTIONS itself, and the direct browser route in Task 4 may work. If discovery fails, record it under `## Blockers and decisions`.

- [ ] **Step 7: Write the generic document checker**

`clinical_copilot_week2/migration/tools/check-doc.sh`:

```bash
#!/usr/bin/env bash
# Fails unless FILE has every required heading and no {{FILL}} or TBD
# marker. Writers put {{FILL}} wherever a value is still unknown; angle
# brackets are left alone because templates and code samples use them.
# Usage: check-doc.sh FILE "## Heading" ...
set -uo pipefail
f="${1:?usage: check-doc.sh FILE HEADING...}"
shift
[[ -r "$f" ]] || { echo "FAIL: cannot read $f"; exit 1; }
fail=0
for h in "$@"; do
  grep -qF -- "$h" "$f" || { echo "MISSING: $h"; fail=1; }
done
grep -nE '\{\{FILL\}\}|\bTBD\b' "$f"
case $? in
  0) echo "FAIL: placeholder text above"; fail=1 ;;
  1) ;;
  *) echo "FAIL: grep could not read $f"; fail=1 ;;
esac
if [[ $fail -eq 0 ]]; then echo "PASS: $f"; fi
exit $fail
```

- [ ] **Step 8: Commit**

```bash
git add clinical_copilot_week2/migration/spike/.gitignore clinical_copilot_week2/migration/spike/lib.mjs clinical_copilot_week2/migration/spike/check-config.mjs clinical_copilot_week2/migration/tools/check-doc.sh clinical_copilot_week2/migration/API-SPIKE.md
git commit --trailer "Assisted-by: Claude Code" -m "docs(migration): dev environment, discovery and CORS record" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

### Task 2: Register the three OAuth clients

**Files:**
- Create: `clinical_copilot_week2/migration/spike/register-client.mjs`
- Modify: `clinical_copilot_week2/migration/API-SPIKE.md` (`## Clients`)

**Interfaces:**
- Consumes: `lib.mjs` (Task 1).
- Produces: `.env.local` keys `PUBLIC_CLIENT_ID`, `PUBLIC_SCOPE`, `BFF_CLIENT_ID`, `BFF_CLIENT_SECRET`, `BFF_SCOPE`, `SEED_CLIENT_ID`, `SEED_CLIENT_SECRET`, `SEED_SCOPE`. Tasks 3–5 read them.

Background facts, already confirmed in the code:
- Public clients may not register `user/` or `system/` scopes (`AuthorizationController.php:325-330`).
- `token_endpoint_auth_method` accepts only `client_secret_basic`, `client_secret_post` or `private_key_jwt` (`:274`), so this script leaves it out.
- Every scope must exist on the server, or registration fails with `invalid_scope` (`:439-454`).
- SMART v2 `.rs` scopes exist for the resources in `ServerScopeListEntity::fhirResourceScopesV2()`. That list has no `Appointment` or `Medication`, so neither is requested.
- The `bff` and `seed` clients are confidential with user scopes, so they are registered **disabled** (`ScopeRepository.php:334-345`, `ClientRepository.php:79-88`). A disabled client fails every grant with `invalid_client` (`CustomPasswordGrant.php:124`), so an administrator must enable both before Tasks 3 and 5.
- The `public` client needs approval only for the exact `launch` scope, or when `oauth_app_manual_approval` is on. Record what actually happens.

- [ ] **Step 1: Write the registration script**

`clinical_copilot_week2/migration/spike/register-client.mjs`:

```js
// Throwaway spike: registers one OAuth client and saves its id (and secret,
// for confidential clients) to .env.local, which is gitignored.
// Usage: NODE_EXTRA_CA_CERTS=dev-cert.pem node register-client.mjs <public|bff|seed> [--replace]
import { readEnv, writeEnv, fetchText, OEMR_BASE } from './lib.mjs';

const READ = ['Patient', 'AllergyIntolerance', 'Condition', 'MedicationRequest', 'CareTeam', 'Practitioner',
  'Organization', 'RelatedPerson', 'Encounter', 'Observation', 'Immunization', 'DocumentReference', 'DiagnosticReport'];

const KINDS = {
  public: {
    type: 'public',
    redirect: 'http://localhost:5174/callback',
    scope: ['openid', 'fhirUser', 'api:fhir', 'launch/patient', ...READ.map((r) => `patient/${r}.rs`)],
  },
  bff: {
    type: 'private',
    redirect: 'http://localhost:5175/callback',
    scope: ['openid', 'fhirUser', 'offline_access', 'api:fhir', ...READ.map((r) => `user/${r}.rs`)],
  },
  seed: {
    type: 'private',
    redirect: 'http://localhost:5176/unused',
    scope: ['openid', 'api:oemr', 'api:fhir', 'user/Patient.rs', 'user/patient.crus', 'user/allergy.cruds',
      'user/medical_problem.cruds', 'user/medication.cruds', 'user/encounter.crus', 'user/vital.crus'],
  },
};

const kind = process.argv[2];
const spec = KINDS[kind];
if (!spec) {
  console.error('usage: register-client.mjs <public|bff|seed> [--replace]');
  process.exit(2);
}
const prefix = kind.toUpperCase();
const env = readEnv();
if (env[`${prefix}_CLIENT_ID`] && !process.argv.includes('--replace')) {
  console.error(`${prefix}_CLIENT_ID is already set. Re-run with --replace, then disable the old client under API Clients.`);
  process.exit(1);
}

const body = {
  application_type: spec.type,
  client_name: `Patient Dashboard Spike (${kind})`,
  redirect_uris: [spec.redirect],
  scope: spec.scope.join(' '),
};

let res;
try {
  res = await fetchText(`${OEMR_BASE}/oauth2/default/registration`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  });
} catch (e) {
  console.error(`FAIL: registration request failed: ${e.name}: ${e.message}`);
  process.exit(1);
}
if ((res.status !== 200 && res.status !== 201) || typeof res.json?.client_id !== 'string') {
  console.error(`FAIL: registration returned HTTP ${res.status}`);
  console.error(res.text.slice(0, 1000));
  process.exit(1);
}
if (spec.type === 'private' && typeof res.json.client_secret !== 'string') {
  console.error('FAIL: confidential client registered without a client_secret');
  process.exit(1);
}

env[`${prefix}_CLIENT_ID`] = res.json.client_id;
env[`${prefix}_SCOPE`] = body.scope;
if (spec.type === 'private') env[`${prefix}_CLIENT_SECRET`] = res.json.client_secret;
writeEnv(env);
console.log(`PASS: registered the ${kind} client (HTTP ${res.status}).`);
console.log(`Returned fields: ${Object.keys(res.json).sort().join(', ')}`);
console.log('Now open the API Clients admin page and record whether this client is enabled.');
```

- [ ] **Step 2: Register all three clients**

```bash
cd clinical_copilot_week2/migration/spike
for kind in public bff seed; do NODE_EXTRA_CA_CERTS=dev-cert.pem node register-client.mjs $kind || echo "REGISTRATION FAILED: $kind"; done
```

Expected: three `PASS` lines. For each failure, the script prints the server's error body. The usual cause is an unsupported scope named in an `invalid_scope` error. Remove that scope from `KINDS`, write the removal under `## Blockers and decisions`, and run again for that kind only.

- [ ] **Step 3: Enable the clients and record the approval rule**

Open the "API Clients" admin page (the menu item is defined at `interface/main/tabs/menu/menus/standard.json:1152`). Enable the `bff` and `seed` clients, which start disabled. Record whether the `public` client started enabled, and enable it if it didn't.

Under `## Clients`, write one row per client with its kind, application type, requested scopes, returned fields, and whether manual enabling was needed. Do not record IDs or secrets.

- [ ] **Step 4: Add the seeding login to .env.local**

```bash
cd clinical_copilot_week2/migration/spike
printf 'OEMR_USER=admin\nOEMR_PASS=pass\n' >> .env.local
chmod 600 .env.local
git check-ignore .env.local dev-cert.pem
```

Expected: both paths printed, which means both are ignored.

- [ ] **Step 5: Commit**

```bash
git add clinical_copilot_week2/migration/spike/register-client.mjs clinical_copilot_week2/migration/API-SPIKE.md
git commit --trailer "Assisted-by: Claude Code" -m "docs(migration): register the spike OAuth clients" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

### Task 3: Seed the synthetic test patients

**Files:**
- Create: `clinical_copilot_week2/migration/fixtures/.gitignore`, containing the single line `fixture-ids.partial.json`
- Create: `clinical_copilot_week2/migration/fixtures/seed.mjs`
- Create: `clinical_copilot_week2/migration/fixtures/fixture-ids.json` (written by the script)
- Create: `clinical_copilot_week2/migration/TEST-PATIENTS.md`

**Interfaces:**
- Consumes: the `SEED_*`, `OEMR_USER` and `OEMR_PASS` keys (Task 2); `lib.mjs`.
- Produces: `fixture-ids.json` shaped as `{ generatedAt, patients: { "<KEY>": { pid, puuid, fhirId, created: { allergies, problems, medications } } } }` for the keys `TP-TYPICAL`, `TP-EMPTY`, `TP-NKA`, `TP-HISTORY`, `TP-DECEASED`, `TP-LONG` and `TP-ESCAPING`. Every later task refers to patients by these keys.

- [ ] **Step 1: Write the seeder**

`clinical_copilot_week2/migration/fixtures/seed.mjs`:

```js
// Seeds synthetic test patients on the dev stack through the Standard REST API
// and writes fixture-ids.json. Records the API cannot create are listed in
// TEST-PATIENTS.md under "Manual steps". Dev stack only; no real patient data.
// Usage: NODE_EXTRA_CA_CERTS=../spike/dev-cert.pem node seed.mjs [--fresh]
import { existsSync, writeFileSync } from 'node:fs';
import { readEnv, fetchText, OEMR_BASE, FHIR_BASE } from '../spike/lib.mjs';

const OUT = new URL('fixture-ids.json', import.meta.url);
const PARTIAL = new URL('fixture-ids.partial.json', import.meta.url);
if (existsSync(OUT) && !process.argv.includes('--fresh')) {
  console.error('fixture-ids.json already exists. Use --fresh to create a new, separately named set.');
  process.exit(1);
}
const env = readEnv();
for (const k of ['SEED_CLIENT_ID', 'SEED_CLIENT_SECRET', 'SEED_SCOPE', 'OEMR_USER', 'OEMR_PASS']) {
  if (!env[k]) {
    console.error(`FAIL: ${k} missing from spike/.env.local`);
    process.exit(1);
  }
}
const suffix = process.argv.includes('--fresh') ? `-${Date.now().toString(36)}` : '';
const day = (daysAgo) => new Date(Date.now() - daysAgo * 864e5).toISOString().slice(0, 10);
// The medication endpoint validates dates as Y-m-d H:i:s (src/Services/ListService.php:42-43);
// allergies and problems take Y-m-d.
const withTime = (record) => ({
  ...record,
  ...(record.begdate && { begdate: `${record.begdate} 00:00:00` }),
  ...(record.enddate && { enddate: `${record.enddate} 00:00:00` }),
});
const many = (n, label, start) =>
  Array.from({ length: n }, (_, i) => ({ title: `${label} ${String(i + 1).padStart(2, '0')}`, begdate: day(start + i) }));

const FIXTURES = [
  {
    key: 'TP-TYPICAL',
    patient: { fname: 'Tessa', lname: `Typical${suffix}`, DOB: '1958-03-14', sex: 'Female' },
    allergies: [{ title: 'Penicillin', begdate: day(3000) }, { title: 'Peanuts', begdate: day(2000) }],
    problems: [
      { title: 'Type 2 diabetes mellitus', begdate: day(2500) },
      { title: 'Essential hypertension', begdate: day(1800) },
      { title: 'Hyperlipidaemia', begdate: day(900) },
    ],
    medications: [
      { title: 'Metformin 500 mg', begdate: day(2400) },
      { title: 'Lisinopril 10 mg', begdate: day(1700) },
      { title: 'Atorvastatin 20 mg', begdate: day(800) },
    ],
  },
  { key: 'TP-EMPTY', patient: { fname: 'Evan', lname: `Empty${suffix}`, DOB: '1990-07-01', sex: 'Male' } },
  { key: 'TP-NKA', patient: { fname: 'Nora', lname: `NoKnownAllergies${suffix}`, DOB: '1975-11-23', sex: 'Female' } },
  {
    key: 'TP-HISTORY',
    patient: { fname: 'Hugo', lname: `History${suffix}`, DOB: '1949-02-02', sex: 'Male' },
    allergies: [{ title: 'Sulfa drugs', begdate: day(4000), enddate: day(1000) }],
    problems: [{ title: 'Community-acquired pneumonia', begdate: day(700), enddate: day(650) }],
    medications: [{ title: 'Amoxicillin 500 mg', begdate: day(700), enddate: day(690) }],
  },
  { key: 'TP-DECEASED', patient: { fname: 'Dora', lname: `Deceased${suffix}`, DOB: '1932-05-09', sex: 'Female' } },
  {
    key: 'TP-LONG',
    patient: { fname: 'Lena', lname: `LongLists${suffix}`, DOB: '1944-09-30', sex: 'Female' },
    allergies: many(25, 'Long-list allergen', 100),
    problems: many(60, 'Long-list problem', 200),
    medications: many(60, 'Long-list medication', 300),
  },
  {
    key: 'TP-ESCAPING',
    patient: { fname: 'Zoë', lname: `O'Brien-Núñez${suffix}`, DOB: '1988-12-12', sex: 'Female' },
    allergies: [{ title: 'Latex <b>x</b>', begdate: day(100) }],
  },
];

const tokenRes = await fetchText(`${OEMR_BASE}/oauth2/default/token`, {
  method: 'POST',
  headers: {
    'Content-Type': 'application/x-www-form-urlencoded',
    Authorization: 'Basic ' + Buffer.from(`${env.SEED_CLIENT_ID}:${env.SEED_CLIENT_SECRET}`).toString('base64'),
  },
  body: new URLSearchParams({
    grant_type: 'password',
    client_id: env.SEED_CLIENT_ID,
    user_role: 'users',
    username: env.OEMR_USER,
    password: env.OEMR_PASS,
    scope: env.SEED_SCOPE,
  }),
});
if (tokenRes.status !== 200 || typeof tokenRes.json?.access_token !== 'string') {
  console.error(`FAIL: password grant returned HTTP ${tokenRes.status}: ${tokenRes.text.slice(0, 500)}`);
  process.exit(1);
}
const token = tokenRes.json.access_token;

async function api(method, path, body) {
  const res = await fetchText(`${OEMR_BASE}/apis/default/api${path}`, {
    method,
    headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json', Accept: 'application/json' },
    body: body === undefined ? undefined : JSON.stringify(body),
  });
  if (res.status < 200 || res.status >= 300) {
    throw new Error(`${method} ${path} returned HTTP ${res.status}: ${res.text.slice(0, 500)}`);
  }
  return res.json;
}

const out = { generatedAt: new Date().toISOString(), patients: {} };
try {
  for (const fx of FIXTURES) {
    const created = await api('POST', '/patient', fx.patient);
    const puuid = created?.data?.uuid;
    let pid = created?.data?.pid;
    if (!puuid) throw new Error(`${fx.key}: patient create returned no uuid: ${JSON.stringify(created).slice(0, 300)}`);
    if (!pid) pid = (await api('GET', `/patient/${puuid}`))?.data?.pid;
    if (!pid) throw new Error(`${fx.key}: could not read the numeric pid`);
    const entry = { pid, puuid, fhirId: null, created: { allergies: 0, problems: 0, medications: 0 } };
    out.patients[fx.key] = entry;
    for (const a of fx.allergies ?? []) { await api('POST', `/patient/${puuid}/allergy`, a); entry.created.allergies++; }
    for (const p of fx.problems ?? []) { await api('POST', `/patient/${puuid}/medical_problem`, p); entry.created.problems++; }
    for (const m of fx.medications ?? []) { await api('POST', `/patient/${pid}/medication`, withTime(m)); entry.created.medications++; }
    const fhir = await fetchText(`${FHIR_BASE}/Patient/${puuid}`, {
      headers: { Authorization: `Bearer ${token}`, Accept: 'application/fhir+json' },
    });
    if (fhir.status !== 200 || fhir.json?.id !== puuid) {
      throw new Error(`${fx.key}: FHIR Patient/${puuid} returned HTTP ${fhir.status}; the uuid is not the FHIR id`);
    }
    entry.fhirId = fhir.json.id;
    console.log(`ok ${fx.key}: pid ${pid}, ${JSON.stringify(entry.created)}`);
  }
} catch (e) {
  writeFileSync(PARTIAL, JSON.stringify(out, null, 2) + '\n');
  console.error(`FAIL: ${e.message}`);
  console.error('Patients created so far are listed in fixture-ids.partial.json.');
  process.exit(1);
}
writeFileSync(OUT, JSON.stringify(out, null, 2) + '\n');
console.log(`PASS: seeded ${Object.keys(out.patients).length} patients`);
```

- [ ] **Step 2: Run it**

```bash
cd clinical_copilot_week2/migration/fixtures && NODE_EXTRA_CA_CERTS=../spike/dev-cert.pem node seed.mjs
```

Expected: seven `ok` lines, then `PASS: seeded 7 patients`. If it fails, the error names the call and the server's response. Fix the cause, record it under `## Blockers and decisions` in `API-SPIKE.md`, delete the partially created patients in the UI (their pids are in `fixture-ids.partial.json`), delete the partial file, and run the script again.

- [ ] **Step 3: Do the manual UI steps**

The Standard API can't create these, so add them in the OpenEMR UI and tick each one off in `TEST-PATIENTS.md`:
- **TP-TYPICAL:**
  - Edit Penicillin: set reaction "Hives" and severity "Moderate".
  - Add two active prescriptions (Amlodipine 5 mg, Omeprazole 20 mg), ticking the option that also adds the drug to the medication list for Amlodipine only. Amlodipine then exercises the linked-row case (Review Focus 3).
  - Add a care team of one practitioner and one related person.
- **TP-HISTORY:** add one prescription and then discontinue it.
- **TP-DECEASED:** set a deceased date in Demographics.
- **TP-NKA:** left until Task 9, which finds out how the old UI records "no known allergies".

- [ ] **Step 4: Write TEST-PATIENTS.md**

Write one section per fixture key, listing:
- the exact records the seeder created and the manual steps done
- which Review Focus item or module edge case it exercises
- its pid and FHIR id, copied from `fixture-ids.json`

End with a `## Manual steps` checklist containing the Step 3 items, ticked. These are synthetic records, so committing the ids is fine.

- [ ] **Step 5: Check and commit**

```bash
bash clinical_copilot_week2/migration/tools/check-doc.sh clinical_copilot_week2/migration/TEST-PATIENTS.md "TP-TYPICAL" "TP-EMPTY" "TP-NKA" "TP-HISTORY" "TP-DECEASED" "TP-LONG" "TP-ESCAPING" "## Manual steps"
git add clinical_copilot_week2/migration/fixtures/.gitignore clinical_copilot_week2/migration/fixtures/seed.mjs clinical_copilot_week2/migration/fixtures/fixture-ids.json clinical_copilot_week2/migration/TEST-PATIENTS.md
git commit --trailer "Assisted-by: Claude Code" -m "test(migration): seed synthetic test patients for the audit and parity tests" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

Expected: `PASS` from the checker before committing.

### Task 4: Spike A, the browser-only public client

**Files:**
- Create: `clinical_copilot_week2/migration/spike/public/serve.mjs`
- Create: `clinical_copilot_week2/migration/spike/public/index.html`
- Modify: `clinical_copilot_week2/migration/API-SPIKE.md` (`## Spike A`, `## Error responses`)

**Interfaces:**
- Consumes: `PUBLIC_CLIENT_ID` and `PUBLIC_SCOPE` (Task 2); `fixture-ids.json` (Task 3).
- Produces: `spike/results-public.json` with `{ pass, directPass, checks[], probes[] }`. `pass` covers the token checks and the proxied checks, and `directPass` covers the direct checks.

This tests a SMART standalone launch: the clinician logs in, picks a patient, and gets a token that covers only that patient. Everything runs in the browser, so CORS and the browser's certificate trust are tested for real.

Every FHIR check runs twice:
- **direct:** the page calls `https://localhost:9300` from `http://localhost:5174`. This is expected to fail at the CORS preflight (see `API-SPIKE.md` `## Discovery and CORS`).
- **proxied:** the page calls its own origin, and `serve.mjs` forwards `/apis/` to OpenEMR. This is how a browser-only app would work without backend changes, for example with Vite's `server.proxy` in development and a reverse proxy in deployment.

The token exchange goes direct. It's a form-encoded POST (a CORS "simple request"), and the `/oauth2` path answers OPTIONS itself (`AuthorizationController.php:1355`).

- [ ] **Step 1: Write the server**

`clinical_copilot_week2/migration/spike/public/serve.mjs`:

```js
// Throwaway spike server for Spike A on http://127.0.0.1:5174. Serves the page,
// forwards /apis/ to OpenEMR so the page can call the API from its own origin,
// and saves the page's results to results-public.json. It passes the page's
// Authorization header through but never stores or logs it.
// Usage: NODE_EXTRA_CA_CERTS=dev-cert.pem node public/serve.mjs   (run from the spike directory)
import { createServer } from 'node:http';
import { readFileSync } from 'node:fs';
import { readEnv, writeResults, fetchText, OEMR_BASE } from '../lib.mjs';

const PORT = 5174;
const env = readEnv();
if (!env.PUBLIC_CLIENT_ID || !env.PUBLIC_SCOPE) {
  console.error('FAIL: PUBLIC_CLIENT_ID or PUBLIC_SCOPE missing; run register-client.mjs public');
  process.exit(1);
}
const ids = JSON.parse(readFileSync(new URL('../../fixtures/fixture-ids.json', import.meta.url), 'utf8')).patients;
const config = JSON.stringify({
  base: OEMR_BASE,
  clientId: env.PUBLIC_CLIENT_ID,
  scope: env.PUBLIC_SCOPE,
  expectedPatientId: ids['TP-TYPICAL'].fhirId,
  otherPatientId: ids['TP-HISTORY'].fhirId,
});
const page = readFileSync(new URL('index.html', import.meta.url));

async function readBody(req) {
  let body = '';
  for await (const chunk of req) {
    body += chunk;
    if (body.length > 1e6) throw new Error('body too large');
  }
  return body;
}

const server = createServer(async (req, res) => {
  try {
    const url = new URL(req.url, `http://localhost:${PORT}`);
    if (req.method === 'GET' && (url.pathname === '/' || url.pathname === '/callback')) {
      res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' }).end(page);
      return;
    }
    if (req.method === 'GET' && url.pathname === '/config.json') {
      res.writeHead(200, { 'Content-Type': 'application/json' }).end(config);
      return;
    }
    if (req.method === 'GET' && url.pathname.startsWith('/apis/default/fhir/')) {
      const headers = { Accept: 'application/fhir+json' };
      if (req.headers.authorization) headers.Authorization = req.headers.authorization;
      const upstream = await fetchText(`${OEMR_BASE}${url.pathname}${url.search}`, { headers });
      res.writeHead(upstream.status, { 'Content-Type': 'application/json' }).end(upstream.text);
      return;
    }
    if (req.method === 'POST' && url.pathname === '/results') {
      const results = JSON.parse(await readBody(req));
      writeResults('public', results);
      console.log(results.pass ? 'PASS' : 'FAIL', `(proxied); direct: ${results.directPass ? 'PASS' : 'FAIL'}; results-public.json written`);
      res.writeHead(204).end();
      return;
    }
    res.writeHead(404).end();
  } catch (e) {
    console.error('spike server error:', e.message);
    if (!res.headersSent) res.writeHead(500).end();
  }
});
server.on('error', (e) => {
  console.error(`FAIL: cannot listen on port ${PORT}: ${e.message}`);
  process.exit(1);
});
server.listen(PORT, '127.0.0.1', () => console.log(`Open http://localhost:${PORT} in the browser that trusts dev-cert.pem.`));
```

- [ ] **Step 2: Write the page**

`clinical_copilot_week2/migration/spike/public/index.html`:

```html
<!doctype html>
<meta charset="utf-8">
<title>Spike A: public client</title>
<h1>Spike A: browser-only public client</h1>
<p>Log in as admin, pick the patient <strong>Tessa Typical</strong>, and leave every permission ticked on the consent page.</p>
<p><button id="start">Start login</button></p>
<pre id="out"></pre>
<script type="module">
const cfg = await (await fetch('/config.json')).json();
const AUD = `${cfg.base}/apis/default/fhir`;
const ROUTES = { direct: AUD, proxied: `${location.origin}/apis/default/fhir` };
const out = document.getElementById('out');
const log = (line) => { out.textContent += line + '\n'; };
const b64url = (bytes) => btoa(String.fromCharCode(...bytes)).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');

document.getElementById('start').onclick = async () => {
  const verifier = b64url(crypto.getRandomValues(new Uint8Array(32)));
  const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(verifier));
  const state = b64url(crypto.getRandomValues(new Uint8Array(16)));
  sessionStorage.setItem('pkce', JSON.stringify({ verifier, state }));
  const url = new URL(`${cfg.base}/oauth2/default/authorize`);
  url.search = new URLSearchParams({
    response_type: 'code', client_id: cfg.clientId, redirect_uri: `${location.origin}/callback`,
    scope: cfg.scope, state, code_challenge: b64url(new Uint8Array(digest)), code_challenge_method: 'S256', aud: AUD,
  }).toString();
  location.assign(url);
};

// Browser fetch: a CORS rejection or an untrusted certificate shows up as a TypeError with no status.
async function call(label, url, init = {}) {
  try {
    const res = await fetch(url, { ...init, signal: AbortSignal.timeout(15000) });
    const text = await res.text();
    let json = null;
    try { json = JSON.parse(text); } catch { json = null; }
    return { label, status: res.status, json, body: text.slice(0, 500) };
  } catch (e) {
    return { label, status: 0, json: null, body: `${e.name}: ${e.message}` };
  }
}

if (location.pathname === '/callback') {
  const params = new URLSearchParams(location.search);
  const saved = JSON.parse(sessionStorage.getItem('pkce') ?? 'null');
  sessionStorage.removeItem('pkce');
  const checks = [];
  const probes = [];
  const check = (route, name, ok, detail = '') => {
    checks.push({ route, name, ok: Boolean(ok), detail });
    log(`${ok ? 'ok  ' : 'FAIL'} [${route}] ${name} ${detail}`);
  };

  if (params.get('error')) {
    check('auth', 'authorization', false, `${params.get('error')}: ${params.get('error_description') ?? ''}`);
  } else if (!saved || params.get('state') !== saved.state) {
    check('auth', 'state', false, 'missing or mismatched state; was the callback reloaded or reused?');
  } else if (!params.get('code')) {
    check('auth', 'code', false, 'no code in the callback');
  } else {
    const tok = await call('token', `${cfg.base}/oauth2/default/token`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({
        grant_type: 'authorization_code', code: params.get('code'), redirect_uri: `${location.origin}/callback`,
        client_id: cfg.clientId, code_verifier: saved.verifier,
      }),
    });
    const t = tok.json ?? {};
    const granted = (t.scope ?? '').split(' ');
    check('auth', 'token endpoint answered the browser', tok.status === 200, `HTTP ${tok.status} ${tok.status === 0 ? tok.body : ''}`);
    check('auth', 'access token issued', typeof t.access_token === 'string');
    check('auth', 'patient context is Tessa Typical', t.patient === cfg.expectedPatientId, `patient=${t.patient}`);
    check('auth', 'every requested scope granted', cfg.scope.split(' ').every((s) => granted.includes(s)), `granted: ${t.scope ?? ''}`);
    probes.push({ route: 'auth', label: 'token', status: tok.status, body: `expires_in=${t.expires_in}; refresh_token=${typeof t.refresh_token === 'string'}; id_token=${typeof t.id_token === 'string'}` });

    if (typeof t.access_token === 'string' && t.patient) {
      const auth = { headers: { Authorization: `Bearer ${t.access_token}`, Accept: 'application/fhir+json' } };
      const refersTo = (e, id) => (e.resource?.patient?.reference ?? '').endsWith(`Patient/${id}`);
      for (const [route, fhir] of Object.entries(ROUTES)) {
        const pat = await call('Patient', `${fhir}/Patient/${t.patient}`, auth);
        check(route, 'Patient read', pat.status === 200 && pat.json?.resourceType === 'Patient' && pat.json?.id === t.patient, `HTTP ${pat.status} ${pat.status === 0 ? pat.body : ''}`);

        const al = await call('AllergyIntolerance', `${fhir}/AllergyIntolerance?patient=${t.patient}`, auth);
        const mine = al.json?.entry ?? [];
        check(route, 'allergies belong to the launched patient', al.status === 200 && al.json?.resourceType === 'Bundle' && mine.length > 0
          && mine.every((e) => e.resource?.resourceType === 'AllergyIntolerance' && refersTo(e, t.patient)), `HTTP ${al.status}, ${mine.length} entries`);

        // Asking for another patient must never return that patient's data. The server is
        // expected to substitute the launched patient instead (Review Focus 1); record which.
        const other = await call('other patient', `${fhir}/AllergyIntolerance?patient=${cfg.otherPatientId}`, auth);
        const otherEntries = other.json?.entry ?? [];
        check(route, "another patient's data never returned", !otherEntries.some((e) => refersTo(e, cfg.otherPatientId)), `HTTP ${other.status}`);
        const substituted = other.status === 200 && otherEntries.length > 0 && otherEntries.every((e) => refersTo(e, t.patient));
        log(`note [${route}] other-patient request ${substituted ? 'silently returned the LAUNCHED patient\'s allergies' : 'did not return the launched patient\'s allergies'}`);

        const oos = await call('out of scope', `${fhir}/Procedure?patient=${t.patient}`, auth);
        check(route, 'out-of-scope read refused', oos.status === 401 || oos.status === 403, `HTTP ${oos.status}`);

        const bad = await call('bad token', `${fhir}/Patient/${t.patient}`, { headers: { Authorization: 'Bearer not-a-real-token' } });
        check(route, 'bad token refused', bad.status === 401, `HTTP ${bad.status}`);

        for (const p of [pat, al, other, oos, bad]) probes.push({ route, label: p.label, status: p.status, body: p.body });
        probes.push({ route, label: 'other patient substituted', status: other.status, body: String(substituted) });
      }
    }
  }

  const pass = checks.length > 0 && checks.filter((c) => c.route !== 'direct').every((c) => c.ok)
    && checks.some((c) => c.route === 'proxied');
  const directPass = checks.some((c) => c.route === 'direct') && checks.filter((c) => c.route === 'direct').every((c) => c.ok);
  log(`${pass ? 'PASS' : 'FAIL'} (auth + proxied); direct: ${directPass ? 'PASS' : 'FAIL'}`);
  await fetch('/results', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ pass, directPass, checks, probes }) });
}
</script>
```

- [ ] **Step 3: Run the spike in the user's browser**

```bash
cd clinical_copilot_week2/migration/spike && NODE_EXTRA_CA_CERTS=dev-cert.pem node public/serve.mjs
```

Ask the user to open http://localhost:5174 in the browser that trusts the dev certificate, click "Start login", log in as `admin` / `pass`, pick Tessa Typical, and leave every permission ticked on the consent page.

Expected: `PASS (auth + proxied)`, with direct most likely `FAIL`, because the direct FHIR calls come back as `status 0` (blocked at the preflight). Any failure in the auth or proxied checks is a finding. Stop the server with Ctrl-C when done.

- [ ] **Step 4: Record the results**

Copy the checks from `results-public.json` into `## Spike A`, split into direct and proxied, along with whether a patient picker appeared and whether the client needed manual enabling. Copy the `out of scope` and `bad token` status and body into `## Error responses`.

If the other-patient request came back as the launched patient's data, write that under `## Blockers and decisions` as a safety observation; Task 7 turns it into a catalogue row. If the direct route failed at the preflight, record that a browser-only app needs a same-origin proxy in both development and deployment.

- [ ] **Step 5: Commit**

```bash
git add clinical_copilot_week2/migration/spike/public/serve.mjs clinical_copilot_week2/migration/spike/public/index.html clinical_copilot_week2/migration/API-SPIKE.md
git commit --trailer "Assisted-by: Claude Code" -m "docs(migration): spike A, browser-only public client, direct and proxied" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

### Task 5: Spike B, the backend-for-frontend

**Files:**
- Create: `clinical_copilot_week2/migration/spike/bff/server.mjs`
- Modify: `clinical_copilot_week2/migration/API-SPIKE.md` (`## Spike B`, `## Error responses`)

**Interfaces:**
- Consumes: `BFF_CLIENT_ID`, `BFF_CLIENT_SECRET` and `BFF_SCOPE` (Task 2); `fixture-ids.json` (Task 3).
- Produces: `spike/results-bff.json` with `{ pass, pageChecks[], serverChecks[], info }`.

This tests a small server that holds a confidential client with `user/*` scopes. Tokens stay in server memory, and the browser only holds an HttpOnly session cookie. The page reads two patients one after the other, to prove that switching patients doesn't need a second login.

- [ ] **Step 1: Write the server**

`clinical_copilot_week2/migration/spike/bff/server.mjs`:

```js
// Throwaway spike, not product code: a minimal backend-for-frontend on
// http://localhost:5175. It holds a confidential client, keeps tokens in
// server memory and gives the browser only an HttpOnly session cookie.
// Usage: NODE_EXTRA_CA_CERTS=dev-cert.pem node bff/server.mjs   (run from the spike directory)
import { createServer } from 'node:http';
import { randomBytes, createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { readEnv, fetchText, writeResults, OEMR_BASE, FHIR_BASE } from '../lib.mjs';

const PORT = 5175;
const ORIGIN = `http://localhost:${PORT}`;
const env = readEnv();
for (const k of ['BFF_CLIENT_ID', 'BFF_CLIENT_SECRET', 'BFF_SCOPE']) {
  if (!env[k]) {
    console.error(`FAIL: ${k} missing; run register-client.mjs bff`);
    process.exit(1);
  }
}
const fixtures = JSON.parse(readFileSync(new URL('../../fixtures/fixture-ids.json', import.meta.url), 'utf8')).patients;
const fhirIds = Object.fromEntries(Object.entries(fixtures).map(([key, v]) => [key, v.fhirId]));
const ALLOWED = new Set(['Patient', 'AllergyIntolerance', 'Condition', 'MedicationRequest', 'CareTeam', 'Procedure']);
const BASIC = 'Basic ' + Buffer.from(`${env.BFF_CLIENT_ID}:${env.BFF_CLIENT_SECRET}`).toString('base64');
const sessions = new Map();

const PAGE = `<!doctype html><meta charset="utf-8"><title>Spike B: BFF</title>
<h1>Spike B: backend-for-frontend</h1>
<p><a href="/login">1. Log in</a> &nbsp; <button id="run">2. Run checks</button></p><pre id="out"></pre>
<script type="module">
const out = document.getElementById('out');
const checks = [];
const check = (name, ok, detail = '') => { checks.push({ name, ok: Boolean(ok), detail }); out.textContent += (ok ? 'ok   ' : 'FAIL ') + name + ' ' + detail + '\\n'; };
const get = async (path) => { const r = await fetch('/api/fhir/' + path); let j = null; try { j = await r.json(); } catch {} return { status: r.status, json: j }; };
document.getElementById('run').onclick = async () => {
  const ids = await (await fetch('/fixtures')).json();
  for (const key of ['TP-TYPICAL', 'TP-HISTORY']) {
    const id = ids[key];
    const p = await get('Patient/' + id);
    check(key + ': Patient read', p.status === 200 && p.json?.id === id, 'HTTP ' + p.status);
    const a = await get('AllergyIntolerance?patient=' + id);
    const e = a.json?.entry ?? [];
    check(key + ': allergies belong to this patient', a.status === 200 && e.length > 0 && e.every((x) => (x.resource?.patient?.reference ?? '').endsWith('Patient/' + id)), e.length + ' entries');
  }
  const oos = await get('Procedure?patient=' + ids['TP-TYPICAL']);
  check('out-of-scope read refused', oos.status === 401 || oos.status === 403, 'HTTP ' + oos.status);
  const pass = checks.length > 0 && checks.every((c) => c.ok);
  out.textContent += (pass ? 'PAGE PASS' : 'PAGE FAIL') + '\\n';
  await fetch('/results', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ pass, checks }) });
};
</script>`;

const sidOf = (req) => /(?:^|;\s*)sid=([A-Za-z0-9_-]+)/.exec(req.headers.cookie ?? '')?.[1];
const send = (res, status, type, body, extra = {}) => res.writeHead(status, { 'Content-Type': type, ...extra }).end(body);

const server = createServer(async (req, res) => {
  try {
    const url = new URL(req.url, ORIGIN);
    const sid = sidOf(req);
    const session = sid ? sessions.get(sid) : undefined;

    if (req.method === 'GET' && url.pathname === '/') return send(res, 200, 'text/html; charset=utf-8', PAGE);
    if (req.method === 'GET' && url.pathname === '/fixtures') return send(res, 200, 'application/json', JSON.stringify(fhirIds));

    if (req.method === 'GET' && url.pathname === '/login') {
      const newSid = randomBytes(24).toString('base64url');
      const verifier = randomBytes(32).toString('base64url');
      const state = randomBytes(16).toString('base64url');
      sessions.set(newSid, { state, verifier });
      const auth = new URL(`${OEMR_BASE}/oauth2/default/authorize`);
      auth.search = new URLSearchParams({
        response_type: 'code', client_id: env.BFF_CLIENT_ID, redirect_uri: `${ORIGIN}/callback`, scope: env.BFF_SCOPE,
        state, code_challenge: createHash('sha256').update(verifier).digest('base64url'), code_challenge_method: 'S256', aud: FHIR_BASE,
      }).toString();
      return send(res, 302, 'text/plain', '', { Location: auth.toString(), 'Set-Cookie': `sid=${newSid}; HttpOnly; SameSite=Lax; Path=/` });
    }

    if (req.method === 'GET' && url.pathname === '/callback') {
      if (!session?.state) return send(res, 400, 'text/plain', 'No login in progress. Start again at /login.');
      const { state, verifier } = session;
      session.state = undefined;
      if (url.searchParams.get('error')) return send(res, 400, 'text/plain', `Authorization failed: ${url.searchParams.get('error')}`);
      if (url.searchParams.get('state') !== state || !url.searchParams.get('code')) return send(res, 400, 'text/plain', 'State mismatch or missing code.');
      const tok = await fetchText(`${OEMR_BASE}/oauth2/default/token`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', Authorization: BASIC },
        body: new URLSearchParams({ grant_type: 'authorization_code', code: url.searchParams.get('code'), redirect_uri: `${ORIGIN}/callback`, code_verifier: verifier }),
      });
      const granted = (tok.json?.scope ?? '').split(' ');
      session.serverChecks = [
        { name: 'token endpoint HTTP 200', ok: tok.status === 200, detail: `HTTP ${tok.status}${tok.status === 200 ? '' : ': ' + tok.text.slice(0, 300)}` },
        { name: 'every requested scope granted', ok: env.BFF_SCOPE.split(' ').every((s) => granted.includes(s)), detail: `granted: ${tok.json?.scope ?? ''}` },
      ];
      session.info = { expiresIn: tok.json?.expires_in, refreshTokenIssued: typeof tok.json?.refresh_token === 'string' };
      session.token = typeof tok.json?.access_token === 'string' ? tok.json.access_token : undefined;
      return send(res, 302, 'text/plain', '', { Location: '/' });
    }

    if (req.method === 'GET' && url.pathname.startsWith('/api/fhir/')) {
      if (!session?.token) return send(res, 401, 'application/json', '{"error":"not logged in"}');
      const rest = url.pathname.slice('/api/fhir/'.length);
      if (!ALLOWED.has(rest.split('/')[0])) return send(res, 400, 'application/json', '{"error":"resource not allowed by the spike proxy"}');
      const upstream = await fetchText(`${FHIR_BASE}/${rest}${url.search}`, { headers: { Authorization: `Bearer ${session.token}`, Accept: 'application/fhir+json' } });
      return send(res, upstream.status, 'application/json', upstream.text);
    }

    if (req.method === 'POST' && url.pathname === '/results') {
      let body = '';
      for await (const chunk of req) {
        body += chunk;
        if (body.length > 1e6) return send(res, 413, 'text/plain', '');
      }
      const page = JSON.parse(body);
      const bad = await fetchText(`${FHIR_BASE}/Patient`, { headers: { Authorization: 'Bearer not-a-real-token' } });
      const serverChecks = [
        ...(session?.serverChecks ?? [{ name: 'logged in', ok: false, detail: 'no session; log in first' }]),
        { name: 'bad token refused', ok: bad.status === 401, detail: `HTTP ${bad.status}` },
      ];
      const pass = page.pass === true && serverChecks.every((c) => c.ok);
      writeResults('bff', { pass, pageChecks: page.checks, serverChecks, info: { ...(session?.info ?? {}), badTokenBody: bad.text.slice(0, 300) } });
      console.log(pass ? 'PASS' : 'FAIL', '(results-bff.json written)');
      return send(res, 204, 'text/plain', '');
    }

    return send(res, 404, 'text/plain', 'not found');
  } catch (e) {
    console.error('spike server error:', e);
    if (!res.headersSent) send(res, 500, 'text/plain', 'spike server error; see the terminal');
  }
});
server.on('error', (e) => {
  console.error(`FAIL: cannot listen on port ${PORT}: ${e.message}`);
  process.exit(1);
});
server.listen(PORT, '127.0.0.1', () => console.log(`Open ${ORIGIN} in a browser.`));
```

- [ ] **Step 2: Run the spike**

```bash
cd clinical_copilot_week2/migration/spike && NODE_EXTRA_CA_CERTS=dev-cert.pem node bff/server.mjs
```

Ask the user to open http://localhost:5175, click "Log in", log in as `admin` / `pass`, leave every permission ticked on the consent page, then click "Run checks". Expected: `PAGE PASS` on the page and `PASS` in the terminal. Stop the server with Ctrl-C when done.

- [ ] **Step 3: Record the results**

Copy `results-bff.json` into `## Spike B`, including the token lifetime, whether a refresh token was issued, and whether the client needed manual enabling. Add the bad-token body to `## Error responses`. Then check the spike record is complete:

```bash
bash clinical_copilot_week2/migration/tools/check-doc.sh clinical_copilot_week2/migration/API-SPIKE.md "## Working agreement" "## Settings changed" "## Discovery and CORS" "## Clients" "## Spike A: browser-only public client" "## Spike B: backend-for-frontend" "## Error responses" "## Blockers and decisions"; echo "exit $?"
```

Expected: `PASS` and `exit 0`.

- [ ] **Step 4: Commit**

```bash
git add clinical_copilot_week2/migration/spike/bff/server.mjs clinical_copilot_week2/migration/API-SPIKE.md
git commit --trailer "Assisted-by: Claude Code" -m "docs(migration): spike B, backend-for-frontend with a confidential client" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

- [ ] **Gate 0.** Show the user the pass or fail result of both spikes and every blocker. Ask:

"Spike A, the browser-only option with one patient per login, <passed or failed> through a same-origin proxy, and <passed or failed> when calling OpenEMR directly. Spike B, the small-server option covering any patient, <passed or failed>. Do you want to keep both options open for the framework decision at Gate 3, or drop one now?"

If the direct route failed, say plainly that a browser-only app would still need a small proxy in front of OpenEMR. That narrows the gap between the two options.

Record the answer under `## Blockers and decisions`. If both spikes failed, stop, and agree a fix with the user before going further. If the user asks for a fix, apply it and re-run the affected spike before moving on.

---

## Phase 1: Inventory

### Task 6: Inventory tree for the dashboard

**Files:**
- Create: `clinical_copilot_week2/migration/tools/check-inventory.sh`
- Create: `clinical_copilot_week2/migration/INVENTORY.md`

**Interfaces:**
- Produces: `INVENTORY.md` with three sections:
  - `## Tree`
  - `## Scope`, a table with the columns `| Module | Depth | Entry lines | Templates | Fragments | Toggles |`. Depth is `full audit`, `light review` or `out of scope`.
  - `## Out of scope, and why`

- [ ] **Step 1: Write the checker**

`clinical_copilot_week2/migration/tools/check-inventory.sh`:

```bash
#!/usr/bin/env bash
# Partial coverage check. Fails if a template or fragment that demographics.php,
# stats.php or a loaded fragment renders directly is missing from INVENTORY.md,
# or if a required scope row is missing. It cannot see transitive includes or
# event listeners, so the tree still needs a careful human read.
set -uo pipefail
root="$(git rev-parse --show-toplevel)" || exit 1
dir="$root/interface/patient_file/summary"
inv="$root/clinical_copilot_week2/migration/INVENTORY.md"
[[ -r "$inv" ]] || { echo "FAIL: cannot read $inv"; exit 1; }
fail=0

fragments=$(grep -oE 'placeHtml\("[^"]+"' "$dir/demographics.php" | sed -E 's/placeHtml\("//; s/"$//' | sort -u)
sources=("$dir/demographics.php" "$dir/stats.php")
for fr in $fragments; do
  [[ -f "$dir/$fr" ]] && sources+=("$dir/$fr")
done
targets=$( {
  printf '%s\n' $fragments
  grep -hoE "render\(['\"]patient/[^'\"]+" "${sources[@]}" | sed -E "s/render\(['\"]//"
  grep -hoE "TEMPLATE_FILE = ['\"]patient/[^'\"]+" "$root"/src/Patient/Cards/*.php | sed -E "s/.*['\"]//"
  echo "controllers/C_Prescription.class.php"
  echo "templates/prescription/general_fragment.html"
} | sort -u )

count=0
for t in $targets; do
  count=$((count + 1))
  grep -qF -- "$t" "$inv" || { echo "MISSING: $t"; fail=1; }
done
for word in getHiddenDashboardCards hide_dashboard_cards aclCheckCore patient_data_template.php patient_data_view_model.js erx_enable; do
  grep -qF -- "$word" "$inv" || { echo "MISSING: $word"; fail=1; }
done
for m in "Header" "Allergies" "Problem List" "Medications" "Prescriptions" "Care Team" "Vitals" "Labs" "Notes" "Immunizations" "Appointments" "Encounters"; do
  grep -qE "^\| *$m *\|" "$inv" || { echo "MISSING scope row: $m"; fail=1; }
done
if [[ $fail -eq 0 ]]; then echo "PASS: $count targets listed (partial coverage check)"; fi
exit $fail
```

- [ ] **Step 2: Run it to see it fail**

```bash
bash clinical_copilot_week2/migration/tools/check-inventory.sh; echo "exit $?"
```

Expected: `FAIL: cannot read .../INVENTORY.md` and `exit 1`.

- [ ] **Step 3: Collect the raw material**

```bash
f=interface/patient_file/summary/demographics.php
grep -nE "require|include" $f
grep -nE "render\('patient/|placeHtml\(|dispatch\(" $f
grep -nE "hiddenCards|getHiddenDashboardCards|hide_dashboard_cards" $f
grep -nE "aclCheckCore|AclMain::" $f
grep -oE "getBoolean\('[a-z_]+'\)|getString\('[a-z_]+'\)|\\\$GLOBALS\['[a-z_]+'\]" $f | sort | uniq -c | sort -rn
grep -nE "<script|Header::setupHeader" $f
grep -rln "patient_data_view_model" interface library src
```

Repeat the `render(`, `aclCheckCore` and globals lines for `interface/patient_file/summary/stats.php` and for each fragment named in the `placeHtml(` calls. Then read `demographics.php` from top to bottom once, to catch anything the greps missed.

- [ ] **Step 4: Write INVENTORY.md**

1. **`## Tree`:** rooted at `demographics.php`. List includes, each card (with its template, service or view-card class, and controller fragment where there is one, such as the prescriptions fragment `controllers/C_Prescription.class.php` → `templates/prescription/general_fragment.html`), fragments loaded after the page opens, JavaScript, and events dispatched. Add a second root for the identity bar: `interface/main/tabs/templates/patient_data_template.php` and `interface/main/tabs/js/patient_data_view_model.js`, plus whatever populates the view model. Record facts only.
2. **`## Scope`:**
   - `full audit`: Header, Allergies, Problem List, Medications, Prescriptions, Care Team.
   - `light review`: the extra-section candidates Vitals, Labs, Notes, Immunizations, Appointments and Encounters. Only the one the user picks in Task 14 gets a full audit.
   - `out of scope`: everything else.
   - "Toggles" lists the `hide_dashboard_cards` key, ACL checks and globals for each card.
3. **`## Out of scope, and why`:** one line per out-of-scope module.

- [ ] **Step 5: Run the checker until it passes**

```bash
bash clinical_copilot_week2/migration/tools/check-inventory.sh; echo "exit $?"
```

Expected: `PASS: N targets listed (partial coverage check)` and `exit 0`.

- [ ] **Step 6: Commit**

```bash
git add clinical_copilot_week2/migration/tools/check-inventory.sh clinical_copilot_week2/migration/INVENTORY.md
git commit --trailer "Assisted-by: Claude Code" -m "docs(migration): inventory of the patient dashboard" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

- [ ] **Gate 1.** Show the `## Scope` table and ask: "The six required modules get a full audit, and the six extra-section candidates get a lighter review, with a full audit only for the one you pick. Is that the right split, or should anything be added, dropped or audited more deeply?" Wait for the answer, and update the table if it changes.

Then report the time used so far against the budget, and ask: "If the audit runs long, is it OK to cut in this order: merge the Medications and Prescriptions audits into one sitting, then review only three extra sections, then skip the long-list allergy checks?"

---

## Phase 2: Audit

### Task 7: Audit template, checkers and the catalogue

**Files:**
- Create: `clinical_copilot_week2/migration/modules/_TEMPLATE.md`
- Create: `clinical_copilot_week2/migration/tools/check-module-doc.sh`
- Create: `clinical_copilot_week2/migration/tools/check-bugs.sh`
- Create: `clinical_copilot_week2/migration/BUGS-MITIGATIONS.md`

**Interfaces:**
- Consumes: `API-SPIKE.md` `## Discovery and CORS` (Task 1).
- Produces:
  - `check-module-doc.sh FILE`: exit 0 only for a complete doc.
  - `check-bugs.sh [FILE]`: exit 0 only when every catalogue row is valid and the summary counts match. The environment variable `MIN_ROWS` sets the minimum number of rows (default 1).
  - Bug ids `BM-NNN`.

- [ ] **Step 1: Write the template**

`clinical_copilot_week2/migration/modules/_TEMPLATE.md`:

```markdown
# Module: <name>

**Source files:** <every file, with line ranges>
**Test patients used:** <fixture keys from TEST-PATIENTS.md>

## 1. Purpose and lifecycle

> Guidance: what happens on page load, on each user action, and anything loaded later by JavaScript.

## 2. What the user sees

> Guidance: every field shown, including tooltips and styling that carries meaning (for example severity colour); sort order; what is filtered out; the empty state exactly as worded; visibility rules. Write "None" for a label that genuinely has nothing.

| Field shown | Format | Source column |
|---|---|---|

**Sort order:**
**Filtered out:**
**Empty state text:**
**Visibility rules:**

## 3. Controls

> Guidance: every button, link and toggle; what it does; and whether the new dashboard shows it, links out to OpenEMR for it, or leaves it out. Use a "None" row if there are no controls.

| Control | What it does | New dashboard |
|---|---|---|

## 4. Permission checks

> Guidance: every ACL check with its line number, missing checks, and the OAuth scope that covers the same access under each auth option.

| Check | Line | Scope in the new app |
|---|---|---|

## 5. Data

> Guidance: tables and columns read, anything written, then map each field the user sees to FHIR. Status must be one of: matches, differs, not available. "Checked against" names the fixture key and the value you saw in the API response. Keep exactly one table in this section, because the checker reads every table row here as a mapping row.

**Reads:**
**Writes:**

| Field shown | FHIR resource.field | Status | Checked against | Notes |
|---|---|---|---|---|
| List completeness | | | | |

## 6. Globals

> Guidance: every $GLOBALS, OEGlobalsBag, session or local-scope variable read or written, and its effect on the card. Use a "None" row if there are none.

| Global | Read or write | Effect on the card |
|---|---|---|

## 7. Problems

> Guidance: each problem as a BM-NNN id with a citation; the full row goes in BUGS-MITIGATIONS.md. Write "None found." if there are none.
```

- [ ] **Step 2: Write the module doc checker**

`clinical_copilot_week2/migration/tools/check-module-doc.sh`:

```bash
#!/usr/bin/env bash
# Fails unless a module audit is complete: all seven sections with real
# content, every label filled, every table with at least one data row, a FHIR
# mapping whose rows all have a resource field, a valid status and evidence,
# a List completeness row, and only catalogued bug ids.
set -uo pipefail
f="${1:?usage: check-module-doc.sh MODULE.md}"
[[ -r "$f" ]] || { echo "FAIL: cannot read $f"; exit 1; }
catalog="$(dirname "$f")/../BUGS-MITIGATIONS.md"
[[ -r "$catalog" ]] || { echo "FAIL: cannot read $catalog"; exit 1; }
doc="$(<"$f")"
fail=0
bad() { echo "FAIL: $*"; fail=1; }

titles=("Purpose and lifecycle" "What the user sees" "Controls" "Permission checks" "Data" "Globals" "Problems")
for i in "${!titles[@]}"; do
  n=$((i + 1))
  grep -qxF "## $n. ${titles[$i]}" <<<"$doc" || bad "missing heading '## $n. ${titles[$i]}'"
  body=$(awk -v h="## $n. ${titles[$i]}" '$0 == h {on=1; next} /^## / {on=0} on' <<<"$doc")
  content=$(grep -vE '^[[:space:]]*$|^> Guidance:|^\|[-| ]*\|$' <<<"$body" || true)
  [[ -n "$content" ]] || bad "section $n has no content"
  if [[ $n -ge 2 && $n -le 6 ]]; then
    rows=$(awk '/^\|/ {t++; if (t > 2) r++} END {print r + 0}' <<<"$body")
    [[ "$rows" -ge 1 ]] || bad "section $n table has no data rows"
  fi
done

placeholders=$(grep -nE '^> Guidance:|<name>|<every file|<fixture keys|\bTBD\b|\bTODO\b' <<<"$doc" || true)
[[ -z "$placeholders" ]] || { echo "$placeholders"; bad "template text or placeholders left in"; }

for label in "Source files:" "Test patients used:" "Sort order:" "Filtered out:" "Empty state text:" "Visibility rules:" "Reads:" "Writes:"; do
  line=$(grep -m1 -F "**$label**" <<<"$doc" || true)
  if [[ -z "$line" ]]; then bad "missing label '$label'"; continue; fi
  value=$(sed -E 's/^\*\*[^*]+\*\*[[:space:]]*//' <<<"$line")
  [[ -n "${value// }" ]] || bad "label '$label' is empty (write None if nothing applies)"
done

mapping=$(awk -F'|' '
  function trim(s) { gsub(/^[ \t]+|[ \t]+$/, "", s); return s }
  /^## 5\. Data$/ {on = 1; next}
  /^## / {on = 0}
  on && /^\|/ {
    t++; if (t <= 2) next
    rows++
    field = trim($2); fhir = trim($3); status = trim($4); checked = trim($5)
    if (field == "List completeness") lc = 1
    if (fhir == "") print "mapping row \"" field "\" has no FHIR resource.field"
    if (status != "matches" && status != "differs" && status != "not available") print "mapping row \"" field "\" has status \"" status "\""
    if (checked == "") print "mapping row \"" field "\" has no Checked against"
  }
  END {
    if (rows == 0) print "FHIR mapping table has no rows"
    if (!lc) print "FHIR mapping has no List completeness row"
  }' <<<"$doc")
if [[ -n "$mapping" ]]; then
  while IFS= read -r m; do bad "$m"; done <<<"$mapping"
fi

ids=$(grep -oE 'BM-[0-9]{3}' <<<"$doc" | sort -u || true)
for id in $ids; do
  grep -qE "^\| *$id *\|" "$catalog" || bad "$id is not in BUGS-MITIGATIONS.md"
done

if [[ $fail -eq 0 ]]; then echo "PASS: $f"; fi
exit $fail
```

- [ ] **Step 3: Write the catalogue checker**

`clinical_copilot_week2/migration/tools/check-bugs.sh`:

```bash
#!/usr/bin/env bash
# Fails unless every data row of the Catalogue table is well formed, ids are
# unique, and the Summary counts equal the rows per severity. No row is
# skipped for looking odd: a malformed row is a failure.
set -uo pipefail
f="${1:-$(dirname "$0")/../BUGS-MITIGATIONS.md}"
[[ -r "$f" ]] || { echo "FAIL: cannot read $f"; exit 1; }
awk -F'|' -v min="${MIN_ROWS:-1}" '
  function trim(s) { gsub(/^[ \t]+|[ \t]+$/, "", s); return s }
  function fail(msg) { print "FAIL: line " NR ": " msg; bad = 1 }
  /^## Summary/ {sec = "summary"; next}
  /^## Catalogue/ {sec = "cat"; next}
  /^## / {sec = ""}
  sec == "summary" && /^\|/ {
    s = trim($2); if (s ~ /^(Critical|High|Medium|Low)$/) { summary[s] = trim($3); seenSummary[s] = 1 }
    next
  }
  sec == "cat" && /^\|/ {
    t++; if (t <= 2) next
    rows++
    if (NF != 9) { fail("expected 7 columns, found " (NF - 2)); next }
    id = trim($2); sev = trim($3); cat = trim($4); mod = trim($5); cite = trim($6); act = trim($7); mit = trim($8)
    if (id !~ /^BM-[0-9][0-9][0-9]$/) fail("bad id \"" id "\"")
    if (id in seen) fail("duplicate id " id); seen[id] = 1
    if (sev !~ /^(Critical|High|Medium|Low)$/) fail(id " bad severity \"" sev "\"")
    else count[sev]++
    if (cat == "" || mod == "" || cite == "") fail(id " has an empty category, module or citation")
    if (act != "keep for parity" && act != "fix in the new app" && act != "out of scope") fail(id " bad port action \"" act "\"")
    if ((act == "fix in the new app" || act == "keep for parity") && (mit == "" || mit == "-")) fail(id " needs a mitigation or reason")
  }
  END {
    if (rows + 0 < min) { print "FAIL: " rows + 0 " catalogue rows, expected at least " min; bad = 1 }
    split("Critical High Medium Low", levels, " ")
    for (i = 1; i <= 4; i++) {
      l = levels[i]
      if (!(l in seenSummary)) { print "FAIL: Summary has no " l " row"; bad = 1 }
      else if (summary[l] + 0 != count[l] + 0) { print "FAIL: Summary says " summary[l] " " l ", catalogue has " count[l] + 0; bad = 1 }
    }
    if (!bad) print "PASS: " rows + 0 " rows"
    exit bad
  }' "$f"
```

- [ ] **Step 4: Write the catalogue, starting with the CORS observations**

`clinical_copilot_week2/migration/BUGS-MITIGATIONS.md`:

```markdown
# Bugs and mitigations

The catalogue the rewrite works from, with one row per problem found in the audit.

- **Severity:** Critical, High, Medium or Low.
- **Port action:**
  - `keep for parity`: the new dashboard reproduces this on purpose. The Mitigation column gives the reason.
  - `fix in the new app`: a presentation-layer problem the new app designs out. The Mitigation column says how.
  - `out of scope`: a backend problem, recorded here but not changed by this project.
- When a slice resolves a row, wrap its Citation in `~~` and add *(Resolved in slice NN-NN-NN, YYYY-MM-DD.)* to the Mitigation. Never delete rows.
- Never put a literal pipe character in a cell.

## Summary

| Severity | Count |
|---|---|
| Critical | 0 |
| High | 1 |
| Medium | 1 |
| Low | 1 |

## Catalogue

| ID | Severity | Category | Module | Citation | Port action | Mitigation |
|---|---|---|---|---|---|---|
| BM-001 | High | correctness | API (CORS) | API routes are dispatched by `RoutesExtensionListener` (request priority 40) before `CORSListener::onKernelRequest` (priority 25) can answer OPTIONS, so a browser preflight to any FHIR route gets no usable CORS answer and the browser blocks the call (src/RestControllers/Subscriber/RoutesExtensionListener.php:28, CORSListener.php:20; observed in API-SPIKE.md) | out of scope | - |
| BM-002 | Medium | security observation | API (CORS) | `CORSListener::onKernelResponse` echoes any Origin into Access-Control-Allow-Origin (src/RestControllers/Subscriber/CORSListener.php:56-57; observed for https://unrelated.example in API-SPIKE.md). No attack path is shown: browsers do not send bearer tokens on their own. | out of scope | - |
| BM-003 | Low | correctness | API (CORS) | `CORSListener::getInitialResponse` uses a comma instead of => so Access-Control-Allow-Methods is never set (src/RestControllers/Subscriber/CORSListener.php:69) | out of scope | - |
```

Adjust BM-001 and BM-002 to match what Task 1 actually observed. If Spike A found that a request for another patient silently returns the launched patient's data, add it as BM-004 (High, safety observation, module "API (SMART patient context)", citing `apis/routes/_rest_routes_fhir_r4_us_core_3_1_0.inc.php:75-77`, port action `fix in the new app`, mitigation: "every card checks that each resource's patient reference matches the header patient and shows an error if not"), and update the Summary.

- [ ] **Step 5: Prove the checkers catch bad input**

```bash
T=clinical_copilot_week2/migration/tools
M=clinical_copilot_week2/migration
bash $T/check-bugs.sh; echo "catalogue exit $?"
bash $T/check-module-doc.sh $M/modules/_TEMPLATE.md; echo "template exit $?"
tmp=$(mktemp -d)
mkdir -p "$tmp/modules"
printf '## Summary\n| Severity | Count |\n|---|---|\n| Critical | 0 |\n| High | 0 |\n| Medium | 0 |\n| Low | 0 |\n## Catalogue\n| ID | Severity | Category | Module | Citation | Port action | Mitigation |\n|---|---|---|---|---|---|---|\n| BM-XYZ | Bad | | | | | |\n' > "$tmp/BUGS-MITIGATIONS.md"
bash $T/check-bugs.sh "$tmp/BUGS-MITIGATIONS.md"; echo "bad catalogue exit $?"
printf '## 1. Purpose and lifecycle\n## 2. What the user sees\n## 3. Controls\n## 4. Permission checks\n## 5. Data\n| Field shown | FHIR resource.field | Status | Checked against | Notes |\n|---|---|---|---|---|\n| List completeness | | matches | x | |\n## 6. Globals\n## 7. Problems\n' > "$tmp/modules/thin.md"
bash $T/check-module-doc.sh "$tmp/modules/thin.md"; echo "thin doc exit $?"
rm -rf "$tmp"
```

Expected:
- `PASS: 3 rows` (or 4 with BM-004) and `catalogue exit 0`.
- The template fails with template text and empty labels, then `template exit 1`.
- The bad catalogue fails on the id, severity, empty cells and port action, then `bad catalogue exit 1`.
- The thin doc fails on empty sections, missing labels and the empty FHIR column, then `thin doc exit 1`.

If any of the last three exits with 0, the checker is broken. Fix it before going on.

- [ ] **Step 6: Commit**

```bash
git add clinical_copilot_week2/migration/modules/_TEMPLATE.md clinical_copilot_week2/migration/tools/check-module-doc.sh clinical_copilot_week2/migration/tools/check-bugs.sh clinical_copilot_week2/migration/BUGS-MITIGATIONS.md
git commit --trailer "Assisted-by: Claude Code" -m "docs(migration): module audit template, doc checkers and bug catalogue" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

### How to do every module audit (Tasks 8–13 and 15)

Read the code before writing anything. Read every handler and helper in the module from top to bottom, without skimming, until you can describe the module without reopening the file. Then open the old dashboard for each fixture patient in the browser and write down exactly what it shows.

Get the FHIR side through Spike B's proxy. Run `node bff/server.mjs` as in Task 5, log in, and open `http://localhost:5175/api/fhir/<Resource>?patient=<fhirId>` in the same browser. Add the resource name to `ALLOWED` in `bff/server.mjs` first if it isn't there yet. Compare field by field. Every mapping row's "Checked against" names the fixture key and the value seen, for example `TP-HISTORY: clinicalStatus=resolved`.

### Task 8: Audit the patient header

**Files:**
- Create: `clinical_copilot_week2/migration/modules/header.md` (copy of `_TEMPLATE.md`)
- Modify: `clinical_copilot_week2/migration/BUGS-MITIGATIONS.md` (append rows, update Summary)

**Interfaces:**
- Consumes: `INVENTORY.md` row "Header"; `_TEMPLATE.md`; the checkers (Task 7); fixtures `TP-TYPICAL`, `TP-DECEASED`, `TP-ESCAPING`.
- Produces: `modules/header.md`, including a note headed **Active status definition:** that says what the old UI shows as status and where it comes from. Gate 2 asks the user to confirm it.

Known leads:
- The identity bar is `interface/main/tabs/templates/patient_data_template.php`, with MRN (`pubpid`) around line 102 and DOB around line 112. It is populated by `interface/main/tabs/js/patient_data_view_model.js`.
- The page heading comes from `dashboard_header.php` → `templates/patient/dashboard_header.html.twig` → `OemrUI::pageHeading()` (`src/OeUI/OemrUI.php:150`).
- `FhirPatientService.php:212` always calls `setActive(true)`. Deceased is emitted separately as `deceasedDateTime` (lines 737–749). MRN is the `pubpid` identifier (lines 552–562).
- Don't assume that "active status" means "not deceased". Find what the old UI actually shows as status. If the old UI shows no status at all, say so plainly in the note.

- [ ] **Step 1: Run the checker to see it fail**

```bash
bash clinical_copilot_week2/migration/tools/check-module-doc.sh clinical_copilot_week2/migration/modules/header.md; echo "exit $?"
```

Expected: `FAIL: cannot read ...` and `exit 1`.

- [ ] **Step 2: Read the code**

```bash
cat -n interface/main/tabs/templates/patient_data_template.php
cat -n interface/main/tabs/js/patient_data_view_model.js
grep -rn "patient_data_view_model\|setPatient\|pubpid" interface/main/tabs | head -40
cat -n interface/patient_file/summary/dashboard_header.php templates/patient/dashboard_header.html.twig
sed -n 140,200p src/OeUI/OemrUI.php
sed -n 190,240p src/Services/FHIR/FhirPatientService.php
sed -n 540,570p src/Services/FHIR/FhirPatientService.php
sed -n 730,760p src/Services/FHIR/FhirPatientService.php
```

Follow every function called from these files until you reach the data source.

- [ ] **Step 3: Write modules/header.md**

```bash
cp clinical_copilot_week2/migration/modules/_TEMPLATE.md clinical_copilot_week2/migration/modules/header.md
```

Fill in all seven sections and the **Active status definition:** note. The mapping must have rows for name, DOB, sex, MRN and status, plus every other field the bar shows and the `List completeness` row (write "single resource" in its FHIR column). Check each row against `TP-TYPICAL`, `TP-DECEASED` (status) and `TP-ESCAPING` (name escaping).

- [ ] **Step 4: Add the header's problems to the catalogue and update the Summary**

- [ ] **Step 5: Run both checkers until they pass**

```bash
bash clinical_copilot_week2/migration/tools/check-module-doc.sh clinical_copilot_week2/migration/modules/header.md && bash clinical_copilot_week2/migration/tools/check-bugs.sh; echo "exit $?"
grep -q "Active status definition:" clinical_copilot_week2/migration/modules/header.md; echo "note exit $?"
```

Expected: two `PASS` lines, `exit 0` and `note exit 0`.

- [ ] **Step 6: Commit**

```bash
git add clinical_copilot_week2/migration/modules/header.md clinical_copilot_week2/migration/BUGS-MITIGATIONS.md
git commit --trailer "Assisted-by: Claude Code" -m "docs(migration): audit the patient header" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

### Task 9: Audit the Allergies card

**Files:**
- Create: `clinical_copilot_week2/migration/modules/allergies.md` (copy of `_TEMPLATE.md`)
- Modify: `clinical_copilot_week2/migration/BUGS-MITIGATIONS.md`, `clinical_copilot_week2/migration/TEST-PATIENTS.md`

**Interfaces:**
- Consumes: `INVENTORY.md` row "Allergies"; the checkers; fixtures `TP-TYPICAL`, `TP-EMPTY`, `TP-NKA`, `TP-HISTORY`, `TP-LONG`, `TP-ESCAPING`.
- Produces: `modules/allergies.md`; the finished `TP-NKA` fixture.

Known leads:
- Rendered at `demographics.php` around line 1134 with `templates/patient/card/allergies.html.twig`. Reactions appear in a tooltip (`allergies.html.twig:40`), so tooltip content counts as a field shown.
- `FhirAllergyIntoleranceService.php:117-128` derives `clinicalStatus`: `resolved` if `outcome == '1'` and `enddate` is set; `active` if `enddate` is not set; `inactive` otherwise. The seeder doesn't set `outcome`, so `TP-HISTORY`'s allergy should come out as `inactive`, not `resolved`. Record what you actually see.

- [ ] **Step 1: Run the checker to see it fail**

```bash
bash clinical_copilot_week2/migration/tools/check-module-doc.sh clinical_copilot_week2/migration/modules/allergies.md; echo "exit $?"
```

Expected: `exit 1`.

- [ ] **Step 2: Read the code**

```bash
sed -n 1100,1140p interface/patient_file/summary/demographics.php
cat -n templates/patient/card/allergies.html.twig templates/patient/card/card_base.html.twig
sed -n 90,260p src/Services/FHIR/FhirAllergyIntoleranceService.php
```

Follow every function called from those lines until you reach the SQL.

- [ ] **Step 3: Finish the TP-NKA fixture**

Find how the old UI records "no known allergies" (for example a dedicated option, or an allergy entry with a particular title). Record it that way for `TP-NKA`, add the step to `TEST-PATIENTS.md` `## Manual steps`, and note what FHIR returns for it.

- [ ] **Step 4: Write modules/allergies.md**

```bash
cp clinical_copilot_week2/migration/modules/_TEMPLATE.md clinical_copilot_week2/migration/modules/allergies.md
```

Fill in all seven sections. The mapping must cover allergen, reaction (tooltip), severity (including any styling), status and every other field shown. The `List completeness` row compares the card's row count with the FHIR entry count for `TP-TYPICAL`, `TP-HISTORY` and `TP-LONG`.

- [ ] **Step 5: Add the card's problems to the catalogue and update the Summary**

- [ ] **Step 6: Run both checkers until they pass**

```bash
bash clinical_copilot_week2/migration/tools/check-module-doc.sh clinical_copilot_week2/migration/modules/allergies.md && bash clinical_copilot_week2/migration/tools/check-bugs.sh; echo "exit $?"
```

Expected: two `PASS` lines and `exit 0`.

- [ ] **Step 7: Commit**

```bash
git add clinical_copilot_week2/migration/modules/allergies.md clinical_copilot_week2/migration/BUGS-MITIGATIONS.md clinical_copilot_week2/migration/TEST-PATIENTS.md
git commit --trailer "Assisted-by: Claude Code" -m "docs(migration): audit the allergies card" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

### Task 10: Audit the Problem List card

**Files:**
- Create: `clinical_copilot_week2/migration/modules/problem-list.md` (copy of `_TEMPLATE.md`)
- Modify: `clinical_copilot_week2/migration/BUGS-MITIGATIONS.md`

**Interfaces:**
- Consumes: `INVENTORY.md` row "Problem List"; the checkers; fixtures `TP-TYPICAL`, `TP-EMPTY`, `TP-HISTORY`, `TP-LONG`.
- Produces: `modules/problem-list.md`.

Known leads:
- Rendered at `demographics.php` around line 1158 with `templates/patient/card/medical_problems.html.twig`. `stats.php` also renders this template (lines 233 and 342), so record which path the dashboard actually uses.
- FHIR: `src/Services/FHIR/FhirConditionService.php` and `src/Services/FHIR/Condition/`, including `FhirConditionProblemListItemService.php`. `Condition` returns more than the problem list, so find and verify the `category` filter that selects problem-list items only.

- [ ] **Step 1: Run the checker to see it fail**

```bash
bash clinical_copilot_week2/migration/tools/check-module-doc.sh clinical_copilot_week2/migration/modules/problem-list.md; echo "exit $?"
```

Expected: `exit 1`.

- [ ] **Step 2: Read the code**

```bash
sed -n 1140,1165p interface/patient_file/summary/demographics.php
cat -n templates/patient/card/medical_problems.html.twig
sed -n 220,240p interface/patient_file/summary/stats.php
sed -n 330,345p interface/patient_file/summary/stats.php
cat -n src/Services/FHIR/FhirConditionService.php
ls src/Services/FHIR/Condition/
cat -n src/Services/FHIR/Condition/FhirConditionProblemListItemService.php
```

- [ ] **Step 3: Write modules/problem-list.md**

```bash
cp clinical_copilot_week2/migration/modules/_TEMPLATE.md clinical_copilot_week2/migration/modules/problem-list.md
```

Fill in all seven sections. The mapping must cover title, code, onset, status and every other field shown, and include a `Category filter` row. The `List completeness` row compares counts for `TP-TYPICAL`, `TP-HISTORY` and `TP-LONG`, requesting `Condition?patient=<id>&category=problem-list-item`.

- [ ] **Step 4: Add the card's problems to the catalogue and update the Summary**

- [ ] **Step 5: Run both checkers until they pass**

```bash
bash clinical_copilot_week2/migration/tools/check-module-doc.sh clinical_copilot_week2/migration/modules/problem-list.md && bash clinical_copilot_week2/migration/tools/check-bugs.sh; echo "exit $?"
```

Expected: two `PASS` lines and `exit 0`.

- [ ] **Step 6: Commit**

```bash
git add clinical_copilot_week2/migration/modules/problem-list.md clinical_copilot_week2/migration/BUGS-MITIGATIONS.md
git commit --trailer "Assisted-by: Claude Code" -m "docs(migration): audit the problem list card" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

### Task 11: Audit the Medications card

**Files:**
- Create: `clinical_copilot_week2/migration/modules/medications.md` (copy of `_TEMPLATE.md`)
- Modify: `clinical_copilot_week2/migration/BUGS-MITIGATIONS.md`

**Interfaces:**
- Consumes: `INVENTORY.md` row "Medications"; the checkers; fixtures `TP-TYPICAL` (including the linked Amlodipine), `TP-EMPTY`, `TP-HISTORY`, `TP-LONG`.
- Produces: `modules/medications.md` with a note headed **Separating medications from prescriptions:**, ending in exactly one of these two verdicts:
  - `VERDICT: reliable` followed by the field and value, shown to hold for every entry of `TP-TYPICAL` and `TP-HISTORY`.
  - `VERDICT: no reliable FHIR field` followed by the options for Gate 2.

  Task 12 relies on this note.

Known leads:
- The old card reads and filters list records from around `demographics.php:1164`, and renders at around line 1180 with `templates/patient/card/medication.html.twig`.
- `FhirMedicationRequestService.php:212` calls `PrescriptionService::getAll`. `PrescriptionService.php` builds a `UNION` of `prescriptions` (`'prescriptions' AS source_table`, line 152) and `lists` (line 208). `source_table` is **not** written into the FHIR resource.
- `intent` and `category` can be set on both halves of the UNION. The FHIR intent falls back to `plan` (`FhirMedicationRequestService.php:498-504`), and the service fills defaults for prescriptions only (`PrescriptionService.php:363-368`). So their values don't prove where a row came from.
- The UNION leaves out list rows that are linked to prescriptions (`PrescriptionService.php:260`). Check this with the linked Amlodipine on `TP-TYPICAL`.
- If there is no reliable FHIR field, list the options for Gate 2:
  - use the Standard API `/api/patient/:pid/medication` for this card
  - merge the two cards
  - accept a documented difference

- [ ] **Step 1: Run the checker to see it fail**

```bash
bash clinical_copilot_week2/migration/tools/check-module-doc.sh clinical_copilot_week2/migration/modules/medications.md; echo "exit $?"
```

Expected: `exit 1`.

- [ ] **Step 2: Read the code**

```bash
sed -n 1155,1185p interface/patient_file/summary/demographics.php
cat -n templates/patient/card/medication.html.twig
sed -n 80,300p src/Services/PrescriptionService.php
sed -n 355,370p src/Services/PrescriptionService.php
sed -n 120,520p src/Services/FHIR/FhirMedicationRequestService.php
```

- [ ] **Step 3: Write modules/medications.md**

```bash
cp clinical_copilot_week2/migration/modules/_TEMPLATE.md clinical_copilot_week2/migration/modules/medications.md
```

Fill in all seven sections and the separator note. Work out the verdict by fetching `MedicationRequest?patient=<id>` for `TP-TYPICAL` and `TP-HISTORY` and checking every entry.

- [ ] **Step 4: Add the card's problems to the catalogue and update the Summary**

- [ ] **Step 5: Run the checks until they pass**

```bash
M=clinical_copilot_week2/migration
bash $M/tools/check-module-doc.sh $M/modules/medications.md && bash $M/tools/check-bugs.sh && grep -qE "^VERDICT: (reliable|no reliable FHIR field)" $M/modules/medications.md; echo "exit $?"
```

Expected: two `PASS` lines and `exit 0`.

- [ ] **Step 6: Commit**

```bash
git add clinical_copilot_week2/migration/modules/medications.md clinical_copilot_week2/migration/BUGS-MITIGATIONS.md
git commit --trailer "Assisted-by: Claude Code" -m "docs(migration): audit the medications card" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

### Task 12: Audit the Prescriptions card

**Files:**
- Create: `clinical_copilot_week2/migration/modules/prescriptions.md` (copy of `_TEMPLATE.md`)
- Modify: `clinical_copilot_week2/migration/BUGS-MITIGATIONS.md`

**Interfaces:**
- Consumes: the separator note in `modules/medications.md` (Task 11); `INVENTORY.md` row "Prescriptions"; the checkers; fixtures `TP-TYPICAL`, `TP-EMPTY`, `TP-HISTORY`.
- Produces: `modules/prescriptions.md`.

Known leads:
- In `demographics.php` from around line 1185, when `$rx === 1`:
  - If `erx_enable` is on and `$display_current_medications_below == 1`, the eRx "Current Medications" card (`erx.html.twig`) renders **in addition to** the core card, from its own SQL (`SELECT * FROM prescriptions WHERE patient_id = ? AND active = '1'`).
  - The core card always renders (`rx.html.twig`). Its body comes from a Smarty fragment: `Controller::dispatch(['controller' => 'prescription', 'action' => 'fragment'])` → `controllers/C_Prescription.class.php` → `templates/prescription/general_fragment.html`. Follow that path to its query.
  - With eRx on, the core card is titled "Prescription History".
- Record every combination of `erx_enable` and `display_current_medications_below`, and which of them the dev stack can show.
- Don't assume the two medication cards partition `MedicationRequest`. Compare each card with its own source, and use Task 11's verdict for the FHIR side.

- [ ] **Step 1: Run the checker to see it fail**

```bash
bash clinical_copilot_week2/migration/tools/check-module-doc.sh clinical_copilot_week2/migration/modules/prescriptions.md; echo "exit $?"
```

Expected: `exit 1`.

- [ ] **Step 2: Read the code**

```bash
sed -n 1183,1250p interface/patient_file/summary/demographics.php
cat -n templates/patient/card/rx.html.twig templates/patient/card/erx.html.twig templates/patient/partials/erx.html.twig
grep -n "function fragment_action" -A40 controllers/C_Prescription.class.php
cat -n templates/prescription/general_fragment.html
```

- [ ] **Step 3: Write modules/prescriptions.md**

```bash
cp clinical_copilot_week2/migration/modules/_TEMPLATE.md clinical_copilot_week2/migration/modules/prescriptions.md
```

Fill in all seven sections, including a table of rendering combinations in section 1. The `List completeness` row compares the core card's rows with the FHIR entries picked out by Task 11's rule, for `TP-TYPICAL` and `TP-HISTORY`, including the discontinued prescription.

- [ ] **Step 4: Add the card's problems to the catalogue and update the Summary**

- [ ] **Step 5: Run both checkers until they pass**

```bash
bash clinical_copilot_week2/migration/tools/check-module-doc.sh clinical_copilot_week2/migration/modules/prescriptions.md && bash clinical_copilot_week2/migration/tools/check-bugs.sh; echo "exit $?"
```

Expected: two `PASS` lines and `exit 0`.

- [ ] **Step 6: Commit**

```bash
git add clinical_copilot_week2/migration/modules/prescriptions.md clinical_copilot_week2/migration/BUGS-MITIGATIONS.md
git commit --trailer "Assisted-by: Claude Code" -m "docs(migration): audit the prescriptions card" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

### Task 13: Audit the Care Team card

**Files:**
- Create: `clinical_copilot_week2/migration/modules/care-team.md` (copy of `_TEMPLATE.md`)
- Modify: `clinical_copilot_week2/migration/BUGS-MITIGATIONS.md`

**Interfaces:**
- Consumes: `INVENTORY.md` row "Care Team"; the checkers; fixtures `TP-TYPICAL` (practitioner plus related person) and `TP-EMPTY`.
- Produces: `modules/care-team.md`.

Known leads:
- `demographics.php` around line 1253 builds `new CareTeamViewCard($pid, ['dispatcher' => $ed])`. The class is `src/Patient/Cards/CareTeamViewCard.php`, and its template is `templates/patient/card/manage_care_team.html.twig` (line 30).
- `FhirCareTeamService.php:127-130` sets `status` from `care_team_status` when the value is valid, and otherwise **defaults to `active`**. Participants include practitioners, organisations and, depending on the configured US Core profile version, `RelatedPerson` (around line 396).
- The card shows names, which FHIR may return only as references. Record how many extra reads that would take, whether `_include=CareTeam:participant` works, and which scopes the extra reads need.

- [ ] **Step 1: Run the checker to see it fail**

```bash
bash clinical_copilot_week2/migration/tools/check-module-doc.sh clinical_copilot_week2/migration/modules/care-team.md; echo "exit $?"
```

Expected: `exit 1`.

- [ ] **Step 2: Read the code**

```bash
sed -n 1250,1275p interface/patient_file/summary/demographics.php
cat -n src/Patient/Cards/CareTeamViewCard.php templates/patient/card/manage_care_team.html.twig
cat -n src/Services/FHIR/FhirCareTeamService.php
```

- [ ] **Step 3: Write modules/care-team.md**

```bash
cp clinical_copilot_week2/migration/modules/_TEMPLATE.md clinical_copilot_week2/migration/modules/care-team.md
```

Fill in all seven sections. Test `CareTeam?patient=<TP-TYPICAL id>&_include=CareTeam:participant`, adding `CareTeam` to the spike proxy's `ALLOWED` list if needed, and record the result.

- [ ] **Step 4: Add the card's problems to the catalogue and update the Summary**

- [ ] **Step 5: Run both checkers until they pass**

```bash
bash clinical_copilot_week2/migration/tools/check-module-doc.sh clinical_copilot_week2/migration/modules/care-team.md && bash clinical_copilot_week2/migration/tools/check-bugs.sh; echo "exit $?"
```

Expected: two `PASS` lines and `exit 0`.

- [ ] **Step 6: Commit**

```bash
git add clinical_copilot_week2/migration/modules/care-team.md clinical_copilot_week2/migration/BUGS-MITIGATIONS.md
git commit --trailer "Assisted-by: Claude Code" -m "docs(migration): audit the care team card" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

### Task 14: Compare the extra-section candidates

**Files:**
- Create: `clinical_copilot_week2/migration/EXTRA-SECTION-OPTIONS.md`

**Interfaces:**
- Consumes: the `INVENTORY.md` light-review rows.
- Produces: `**Chosen:** <name>` at the top of `EXTRA-SECTION-OPTIONS.md`. Task 15 audits that module.

| Candidate | Old dashboard | FHIR | Scope in the spike clients |
|---|---|---|---|
| Vitals | `vitals_fragment.php` | `Observation?category=vital-signs` | yes (`Observation`) |
| Lab results | `labdata_fragment.php` | `Observation?category=laboratory`, `DiagnosticReport` | yes |
| Patient notes | `pnotes_fragment.php` | `DocumentReference`? Check whether pnotes are exposed at all | yes (`DocumentReference`) |
| Immunizations | `stats.php` line 304 → `immunizations.html.twig` | `Immunization` | yes |
| Appointments | `demographics.php` around line 2002 → `appointments.html.twig` | `Appointment` | **not registered**: there is no v2 `.rs` scope, but the v1 scopes `patient/Appointment.read` and `user/Appointment.read` exist (`ServerScopeListEntity.php:77`). Choosing this section means re-registering with a v1 scope. |
| Encounter history | `demographics.php` encounter card, if present (see `INVENTORY.md`) | `Encounter` | yes |

- [ ] **Step 1: Light review of each candidate**

This is a code-reading review only, with no fixtures and no API calls, so it stays fast. For each candidate, skim the old source and the matching FHIR service in `src/Services/FHIR/`, and record:
- how many fields it shows
- whether FHIR has all of them
- how many API calls it needs
- whether the scope is available under each auth option
- one likely problem

- [ ] **Step 2: Write EXTRA-SECTION-OPTIONS.md**

Include one table with those columns plus "Clinical value on a pre-visit glance", then a one-paragraph recommendation.

- [ ] **Step 3: Gate 2a.** Ask the user: "Which extra section would you like the new dashboard to include? I recommend <X> because <reason>." Add `**Chosen:** <name>` at the top of the file.

- [ ] **Step 4: Check and commit**

```bash
M=clinical_copilot_week2/migration
bash $M/tools/check-doc.sh $M/EXTRA-SECTION-OPTIONS.md "**Chosen:**" "Vitals" "Lab results" "Patient notes" "Immunizations" "Appointments" "Encounter history" && git add $M/EXTRA-SECTION-OPTIONS.md && git commit --trailer "Assisted-by: Claude Code" -m "docs(migration): compare extra-section candidates" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

Expected: `PASS`, then a commit.

### Task 15: Audit the chosen extra section and close the audit

**Files:**
- Create: `clinical_copilot_week2/migration/modules/extra-<name>.md`, where `<name>` is the Gate 2a choice in lowercase with hyphens, for example `extra-vitals.md`
- Modify: `clinical_copilot_week2/migration/BUGS-MITIGATIONS.md`, `clinical_copilot_week2/migration/TEST-PATIENTS.md`

**Interfaces:**
- Consumes: `**Chosen:**` (Task 14); all module docs.
- Produces: a complete audit set and a complete catalogue.

- [ ] **Step 1: Seed the data this section needs**

Add enough records for the chosen section to `TP-TYPICAL`, `TP-HISTORY` and `TP-LONG` to cover a typical list, an old or ended entry and a long list, using the UI or the Standard API with the seed client. Log each step in `TEST-PATIENTS.md` `## Manual steps`.

- [ ] **Step 2: Run the checker to see it fail, read the code in full, then write the doc**

```bash
bash clinical_copilot_week2/migration/tools/check-module-doc.sh clinical_copilot_week2/migration/modules/extra-<name>.md; echo "exit $?"
cp clinical_copilot_week2/migration/modules/_TEMPLATE.md clinical_copilot_week2/migration/modules/extra-<name>.md
```

Read the source files from the Task 14 table from top to bottom, then fill in all seven sections and add the problems to the catalogue.

- [ ] **Step 3: Run every checker and fail on any failure**

```bash
M=clinical_copilot_week2/migration
rc=0
for f in $M/modules/[!_]*.md; do bash $M/tools/check-module-doc.sh "$f" || rc=1; done
MIN_ROWS=3 bash $M/tools/check-bugs.sh || rc=1
echo "overall exit $rc"
test $rc -eq 0
```

Expected: seven `PASS` module lines, one catalogue `PASS` and `overall exit 0`.

- [ ] **Step 4: Commit**

```bash
git add clinical_copilot_week2/migration/modules/extra-*.md clinical_copilot_week2/migration/BUGS-MITIGATIONS.md clinical_copilot_week2/migration/TEST-PATIENTS.md
git commit --trailer "Assisted-by: Claude Code" -m "docs(migration): audit the chosen extra section" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

- [ ] **Gate 2.** Present these to the user and ask about each one as a full sentence. Record every answer in the catalogue or the module doc it concerns.
  1. Every mapping row marked `not available`, and every `differs` row that changes what a clinician would see. For example: "The API can't tell a medication-list entry from a prescription. Should the new dashboard use the Standard API for the medications card, merge the two cards, or show the difference and document it?" Each one needs a decision: an approved workaround, an accepted limitation, or dropping the field.
  2. Every catalogue row marked `keep for parity`. For example: "The old allergies card hides resolved allergies. Should the new one hide them too, to match?"
  3. The header's **Active status definition:**.
  4. Every visibility rule: whether the new app should honour it, and how, for example through deployment configuration.

  Then run the checks and commit:

```bash
M=clinical_copilot_week2/migration
rc=0; for f in $M/modules/[!_]*.md; do bash $M/tools/check-module-doc.sh "$f" || rc=1; done; bash $M/tools/check-bugs.sh || rc=1; test $rc -eq 0 && git add $M && git commit --trailer "Assisted-by: Claude Code" -m "docs(migration): record Gate 2 decisions" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

## Phase 3: Migration options

### Task 16: Write MIGRATION-OPTIONS.md and decide with the user

**Files:**
- Create: `clinical_copilot_week2/migration/MIGRATION-OPTIONS.md`

**Interfaces:**
- Consumes: `API-SPIKE.md` (both spikes and the Gate 0 decision), the module docs, `BUGS-MITIGATIONS.md`, and the Gate 2 decisions.
- Produces: a `## Decision` section recording:
  - the auth architecture (A or B)
  - the framework
  - where the app's code lives
  - how it is hosted

  Tasks 17 and 18 read this section.

- [ ] **Step 1: Write the auth section**

Write `## Auth architecture` with two subsections, one per option kept open at Gate 0. Use the spike results as evidence.
- **Option A, browser-only:** one patient per launch, and switching patients means a new launch. Tokens live in the browser. If Spike A's direct route failed, it also needs a same-origin reverse proxy in front of OpenEMR in both development and deployment. A mistaken `patient=` parameter silently returns the launched patient's data (BM-004 if observed).
- **Option B, backend-for-frontend:** any patient under the user's scopes, switching needs no new login, and tokens stay on the server. It is two deployables, needs a session store, and requires an administrator to approve the client.

- [ ] **Step 2: Write the framework routes**

Write one `##` section per route, each with the same sub-headings: framework and language; how it implements the chosen auth option (A or B); FHIR types and client libraries (`fhirclient`, `@types/fhir`); testing (unit, component, Playwright E2E); hosting next to OpenEMR; speed to build in the time left; and how well it can be defended.
- **Route 1:** React + TypeScript (Vite). Under Option B, add a small Node BFF, or use Next.js as the BFF.
- **Route 2:** modern Angular + TypeScript. OpenEMR already ships AngularJS 1.8, so weigh whether that continuity helps the argument.
- **Route 3:** Vue 3 + TypeScript.
- **Route 4:** Next.js (React) as a full-stack app, which naturally fits Option B.

- [ ] **Step 3: Add a comparison table and a recommendation**

One row per route and auth combination, scored High, Medium or Low with a few words each. Then write `## Recommendation`.

- [ ] **Step 4: Check it**

```bash
bash clinical_copilot_week2/migration/tools/check-doc.sh clinical_copilot_week2/migration/MIGRATION-OPTIONS.md "## Auth architecture" "Route 1" "Route 2" "Route 3" "Route 4" "## Recommendation"; echo "exit $?"
```

Expected: `PASS` and `exit 0`.

- [ ] **Step 5: Gate 3.** Take the user through the auth options, then each route, one at a time, with a three-sentence summary each, and ask for questions before moving on. Then ask separately:
  1. "Which auth option do you want: browser-only with one patient per login, or a small server that keeps tokens away from the browser?"
  2. "Which framework route do you want?"
  3. "Where should the new app's code live? I suggest a top-level `patient-dashboard/` folder, separate from the PHP code."
  4. "Where should it run for the demo: next to the dev stack only, or also on the droplet?"

  Write the answers into `## Decision`, run the Step 4 check again, and commit:

```bash
git add clinical_copilot_week2/migration/MIGRATION-OPTIONS.md
git commit --trailer "Assisted-by: Claude Code" -m "docs(migration): migration options and the chosen route" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

## Phase 4: The defence document

### Task 17: Write PATIENT_DASHBOARD_MIGRATION.md in the repo root

**Files:**
- Create: `PATIENT_DASHBOARD_MIGRATION.md`

**Interfaces:**
- Consumes: `## Decision` (Task 16), the module docs, `BUGS-MITIGATIONS.md`, `API-SPIKE.md`.
- Produces: the graded defence. Task 18's final arc adds the parity results to its `## Parity evidence` section.

- [ ] **Step 1: Write it with exactly these sections**

1. `## Summary`: three sentences covering what was ported, the framework and auth architecture, and the main reason for choosing them.
2. `## What was ported`: a table of the header, five cards and the extra section, listing old source files, the APIs and resources used, and parity status.
3. `## Why this framework`: the reasons, each tied to something in this codebase or the spike results.
4. `## What moving off PHP gained`: concrete gains backed by the audit. For example, name the `fix in the new app` rows the new app designs out, and describe the move from direct SQL in `demographics.php` to typed API mappers.
5. `## Tradeoffs and costs`: every `differs` or `not available` decision from Gate 2; every `keep for parity` decision; the auth tradeoff from Task 16 (tokens in the browser, or a second server to run); the CORS observations BM-001 and BM-002; permissions moving from server-side ACL checks to OAuth scopes; and the parts of the dashboard not ported.
6. `## Parity evidence`: how parity is tested, covering the fixtures, per-field comparisons with approved exceptions, and the E2E tests. The final arc adds the results table.
7. `## Not ported`: the out-of-scope modules from `INVENTORY.md`, with one line each.

- [ ] **Step 2: Check it**

```bash
f=PATIENT_DASHBOARD_MIGRATION.md
rc=0
bash clinical_copilot_week2/migration/tools/check-doc.sh $f "## Summary" "## What was ported" "## Why this framework" "## What moving off PHP gained" "## Tradeoffs and costs" "## Parity evidence" "## Not ported" || rc=1
for id in $(grep -oE 'BM-[0-9]{3}' $f | sort -u); do
  grep -qE "^\| *$id *\|" clinical_copilot_week2/migration/BUGS-MITIGATIONS.md || { echo "UNKNOWN: $id"; rc=1; }
done
echo "exit $rc"; test $rc -eq 0
```

Expected: `PASS` and `exit 0`.

- [ ] **Step 3: Commit**

```bash
git add PATIENT_DASHBOARD_MIGRATION.md
git commit --trailer "Assisted-by: Claude Code" -m "docs: patient dashboard migration defence" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

- [ ] **Gate 4.** Ask the user to read the defence document and say what to change. Make the changes, run the Step 2 check again, and commit.

---

## Phase 5: Migration spec

### Task 18: Write MIGRATION-SPEC.md, DEV-LOG.md and the arc files

**Files:**
- Create: `clinical_copilot_week2/migration/MIGRATION-SPEC.md`
- Create: `clinical_copilot_week2/migration/DEV-LOG.md`
- Create: `clinical_copilot_week2/migration/arcs/ARC-01-FOUNDATION-AUTH.md`, `ARC-02-HEADER.md`, `ARC-03-CLINICAL-CARDS.md`, `ARC-04-EXTRA-SECTION.md`, `ARC-05-DEFENCE-DEMO.md`

**Interfaces:**
- Consumes: everything above.
- Produces: slices numbered `NN-NN-NN`. Each slice has acceptance criteria written as named tests (unit, parity or E2E), so that `superpowers:test-driven-development` or `build-loop` can use them without rewording.

- [ ] **Step 1: Read the reference**

```bash
curl -sL --max-time 30 https://raw.githubusercontent.com/decagondev/vb6-rework-reverse-forward/main/docs/MIGRATION-DOTNET.md
```

- [ ] **Step 2: Write MIGRATION-SPEC.md with the reference's 13 sections, adapted**

The sections are: Purpose and scope; Target architecture; Project structure; Tech stack and rationale; Coding standards; Test strategy; Required documentation artifacts; Git workflow; The slice loop; Epic / Story / Slice hierarchy; Roadmap; Templates; Definition of Done. Adapt them as follows:

- **Scale and schedule.** A slice is a vertical piece of work of 2–4 hours: for one card, that's the mapper, the component, the parity test and the error states together. Include a `## Critical path` section that estimates each arc in hours, shows the total against the days left, and names what gets cut first if time runs short. Reserve at least half a day for problems found in Gate 2.
- **The frozen original.** The OpenEMR PHP backend is the equivalent of the reference's `ORIGINAL-CODE/`, and no slice edits it.
- **Layers, per the chosen auth option.**
  - Option A: the same-origin proxy configuration (if Spike A needed it), API client (PKCE, fetch, 401 → new launch), FHIR-to-view mappers (pure functions) that reject any resource whose patient reference doesn't match the header patient, view models or hooks, components.
  - Option B: the BFF (login, callback, session, token refresh, FHIR proxy with an allow-list), plus the same client-side layers talking to the BFF.
  - In both cases, name the real folders under the location chosen at Gate 3.
- **Test strategy.**
  - **Unit tests** for every mapper, including missing fields, ordering, and each Gate 2 exception.
  - **Parity tests, one per module.** Playwright opens the old dashboard at https://localhost:9300 and the new app for the same fixture. For each field in the module doc's section 2 it compares the value, including tooltips and meaningful styling, with the approved exceptions from Gate 2 listed in the test. Run each against `TP-TYPICAL`, `TP-EMPTY`, `TP-HISTORY`, `TP-LONG` and `TP-ESCAPING`, plus `TP-NKA` for allergies and `TP-DECEASED` for the header.
  - **Required E2E tests:**
    - login and logout
    - an expired or rejected token, matched against the 401 body in `API-SPIKE.md` `## Error responses`, which starts a new login rather than showing empty cards
    - an out-of-scope or failed request, where the card says it failed to load
    - switching patients, where nothing from the previous patient stays on screen, and every resource on every card references the patient named in the header; under Option A this includes the new launch
    - `TP-LONG`, where every entry the old card shows is shown
- **Git.** Follow the Task 0 working agreement. Commits use Conventional Commits with the `Assisted-by: Claude Code` trailer.
- **Documents kept current.** `DEV-LOG.md` (newest entry first, using the reference's entry template), the arc files, and `BUGS-MITIGATIONS.md`, where resolved citations are struck through.

- [ ] **Step 3: Write the five arc files**

Each file lists stories, then slices, and each slice names its acceptance tests and the `BM-NNN` rows it resolves.

- **ARC-01 Foundation and auth:**
  - 01-01 Scaffold the app, linter, unit test runner and Playwright.
  - 01-02 Auth for the chosen option (Option A: PKCE launch and logout; Option B: BFF login, session, refresh and logout), with the login, logout and rejected-token E2E tests.
  - 01-03 API client with typed errors and the error-state test helper.
  - 01-04 Parity harness: a Playwright helper that reads one field of a card from the old dashboard and from the new app, reading fixture ids from `fixtures/fixture-ids.json`.
- **ARC-02 Header:** 02-01 the header slice (mapper, component, parity test on all fixtures, and the Gate 2 status decision); 02-02 the patient-switching E2E test.
- **ARC-03 Clinical cards:** one vertical slice per card (allergies, problem list, medications, prescriptions, care team), each with its parity and error-state tests. Medications and prescriptions follow the Gate 2 decision on separating them.
- **ARC-04 Extra section:** one vertical slice.
- **ARC-05 Defence and demo:** run the full parity suite, add the results table to `PATIENT_DASHBOARD_MIGRATION.md` `## Parity evidence`, and prepare the demo walkthrough.

- [ ] **Step 4: Write DEV-LOG.md with one entry for the planning phase**

Use the reference's entry template, dated with the day it's written, with status `ready-for-commit`. Summarise Gates 0–4 and their decisions.

- [ ] **Step 5: Check everything and fail on any gap**

```bash
M=clinical_copilot_week2/migration
rc=0
bash $M/tools/check-doc.sh $M/MIGRATION-SPEC.md "Purpose and scope" "Target architecture" "Project structure" "Tech stack" "Coding standards" "Test strategy" "Required documentation" "Git workflow" "slice loop" "Epic / Story / Slice" "Roadmap" "Templates" "Definition of Done" "## Critical path" || rc=1
for a in $M/arcs/ARC-0*.md; do bash $M/tools/check-doc.sh "$a" || rc=1; done
for id in $(awk -F'|' '/^\| *BM-[0-9]{3} *\|/ && $7 ~ /fix in the new app/ {gsub(/ /, "", $2); print $2}' $M/BUGS-MITIGATIONS.md); do
  grep -q "$id" $M/arcs/*.md || { echo "UNASSIGNED: $id"; rc=1; }
done
echo "exit $rc"; test $rc -eq 0
```

Expected: every `PASS`, no `UNASSIGNED` line, and `exit 0`.

- [ ] **Step 6: Commit**

```bash
git add clinical_copilot_week2/migration/MIGRATION-SPEC.md clinical_copilot_week2/migration/DEV-LOG.md clinical_copilot_week2/migration/arcs/
git commit --trailer "Assisted-by: Claude Code" -m "docs(migration): migration spec, dev log and arc plans" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

- [ ] **Gate 5.** Ask the user to approve the spec, including the critical-path estimate.

---

## Phase 6: Handoff

### Task 19: Hand off to building

**Files:** none.

- [ ] **Step 1: Present the build plan**

- ARC-01 and ARC-02 are built inline with `superpowers:test-driven-development`. They carry the most unknowns and set the patterns the other arcs copy, so the user should watch them closely.
- ARC-03 and ARC-04 are built with `build-loop`. Each slice is a similar, independent vertical piece with a parity test as its acceptance test, and there are no database schema changes.
- ARC-05 is done inline.

Ask: "Shall I start ARC-01 slice 01-01 now?"
