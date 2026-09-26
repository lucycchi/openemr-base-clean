# API spike record

What the auth and data spikes found about OpenEMR's REST and FHIR API on the `development-easy` dev stack (https://localhost:9300). Later documents quote from this file.

## Working agreement

- Branch: `dashboard-migration`, created from `98a374f` on `pdf_reader` (2026-09-26).
- Claude commits each task to the branch. The user reviews the branch and does any merge to `main`.

## Settings changed

None. The dev stack already had every value the spikes need (read from the `globals` table on 2026-09-26):

| Setting | Global | Value found | Needed |
|---|---|---|---|
| Enable OpenEMR Standard REST API | `rest_api` | 1 (on) | on |
| Enable OpenEMR Standard FHIR REST API | `rest_fhir_api` | 1 (on) | on |
| Site Address | `site_addr_oath` | `https://localhost:9300` | same |
| Enable OAuth2 Password Grant | `oauth_password_grant` | 3 (On for Both Roles) | 1 or 3 |
| Require manual approval of OAuth apps | `oauth_app_manual_approval` | 0 (off) | record only |
| Enable system scopes | `rest_system_scopes_api` | 1 (on) | not used |

TLS: the dev certificate (`CN=localhost`, no subjectAltName extension) is saved as `spike/dev-cert.pem`, and Node trusts it through `NODE_EXTRA_CA_CERTS` (checked: HTTP 302). Chrome rejects certificates without a SAN even when they're imported, so for the browser spikes the user must visit https://localhost:9300 and accept the warning once per browser session. Nothing turns off TLS verification in code.

## Discovery and CORS

From `spike/check-config.mjs` (2026-09-26), which passed:

- `/.well-known/smart-configuration` returns 200 JSON and advertises S256 PKCE. Authorization endpoint: `https://localhost:9300/oauth2/default/authorize`. Token endpoint: `https://localhost:9300/oauth2/default/token`.
- Capabilities include `launch-standalone`, `client-public`, `client-confidential-symmetric`, `context-standalone-patient`, `permission-user`, `permission-patient`, `permission-offline`, `permission-v1` and `permission-v2`.
- **CORS preflight is not usable by a browser.** `OPTIONS /apis/default/fhir/Patient`, sent with `Access-Control-Request-Method: GET` and `Access-Control-Request-Headers: authorization`, returns **HTTP 404** `{"error":"An error occurred","message":"Route not found"}` for every origin tried. There is no `Access-Control-Allow-Headers`, `-Methods` or `-Credentials`. `Access-Control-Allow-Origin` echoes whatever origin was sent, including `https://unrelated.example`.
- **Why:** API routes are dispatched by `RoutesExtensionListener` (request priority 40) before `CORSListener::onKernelRequest` (priority 25) can answer OPTIONS, and no `OPTIONS` route exists. So a browser page on another origin cannot send a FHIR request with an `Authorization` header. This is BM-001 in the catalogue, and it means the direct browser route in Spike A is expected to fail.

## Clients

All three were registered on 2026-09-26 with `spike/register-client.mjs`, and each got HTTP 200. IDs and secrets are kept only in `spike/.env.local`, which is gitignored.

| Kind | application_type | Role given | Confidential | Enabled on registration | Requested scopes |
|---|---|---|---|---|---|
| public | public | patient | no | **yes** | `openid fhirUser api:fhir launch/patient` + `patient/<R>.rs` for Patient, AllergyIntolerance, Condition, MedicationRequest, CareTeam, Practitioner, Organization, RelatedPerson, Encounter, Observation, Immunization, DocumentReference, DiagnosticReport |
| bff | private | user | yes | **no**, enabled by hand | `openid fhirUser offline_access api:fhir` + `user/<R>.rs` for the same 13 resources |
| seed | private | user | yes | **no**, enabled by hand | `openid api:oemr api:fhir user/Patient.rs user/patient.crus user/allergy.cruds user/medical_problem.cruds user/medication.cruds user/encounter.crus user/vital.crus` |

- **Fields returned for every kind:** `application_type`, `client_id`, `client_id_issued_at`, `client_name`, `client_role`, `client_secret`, `client_secret_expires_at`, `dsi_type`, `redirect_uris`, `registration_access_token`, `registration_client_uri`, `scope`. The public client's response includes a `client_secret` key, but no secret is stored for it.
- **Every scope was accepted on the first attempt**, so none had to be removed.
- **The approval rule behaved as the code says.** Confidential clients with `user/` scopes start disabled (`ScopeRepository::hasScopesThatRequireManualApproval`). The public client with `launch/patient`, but not `launch`, started enabled because `oauth_app_manual_approval` is 0.
- **How they were enabled:** `bff` and `seed` were enabled with `UPDATE oauth_clients SET is_enabled=1` on the dev database, which is the same change the API Clients admin page makes.

## Spike A: browser-only public client

Run on 2026-09-26 in the user's Chrome, from http://localhost:5174. The user logged in as `admin`, picked Tessa Typical (`TP-TYPICAL`) in OpenEMR's patient picker, and approved every scope. The public client was already enabled and needed no admin step.

**Verdict: the browser-only option works through a same-origin proxy and does not work directly.**

| Route | Check | Result |
|---|---|---|
| auth | token endpoint answered the browser (form POST, direct to :9300) | ok, HTTP 200 |
| auth | access token issued | ok, `expires_in` 3600, ID token issued, no refresh token (none requested) |
| auth | patient context is Tessa Typical | ok, `patient` = `a2d68325-…` |
| auth | every requested scope granted | FAIL, but harmless: every resource scope was granted and only `api:fhir` is missing from the granted list. The API calls below worked without it, so the check was stricter than it needed to be. |
| direct | Patient read, allergies, other patient, out-of-scope, bad token | all `status 0`, `TypeError: Failed to fetch`. The browser blocked every direct FHIR call at the CORS preflight (see Discovery and CORS). |
| proxied | Patient read | ok, HTTP 200, `resourceType` Patient, id matches |
| proxied | allergies belong to the launched patient | ok, HTTP 200, 2 entries, both referencing Tessa |
| proxied | another patient's data never returned | ok, no `TP-HISTORY` data came back |
| proxied | out-of-scope read (`Procedure`) refused | ok, HTTP 401 |
| proxied | bad token refused | ok, HTTP 401 |

**Other-patient behaviour (safety observation).** `AllergyIntolerance?patient=<TP-HISTORY id>`, sent with Tessa's patient-bound token, returned **HTTP 200 with an empty Bundle** (`total: 0`). It was neither refused nor given Tessa's data. The route passes the bound patient to the service (`apis/routes/_rest_routes_fhir_r4_us_core_3_1_0.inc.php:75-77`), and the two patient filters together match nothing. An app that switches patients without a new launch would therefore show "no allergies" for the new patient, which is Review Focus 1 and 4 together. This becomes BM-004.

**What this means for Option A:** a browser-only app needs a same-origin reverse proxy for `/apis/` in development (for example Vite `server.proxy`) and in deployment. The token endpoint can be called directly, and the patient picker works.

## Spike B: backend-for-frontend

Run on 2026-09-26 in the user's Chrome, from http://localhost:5175. The user logged in as `admin` and approved every scope. The `bff` client had to be enabled by hand first (see Clients). There was no patient picker, because `user/` scopes don't bind a patient.

**Verdict: works.** One login read two different patients, and the browser never held a token (only an HttpOnly `sid` cookie).

| Where | Check | Result |
|---|---|---|
| page | TP-TYPICAL Patient read | ok, HTTP 200 |
| page | TP-TYPICAL allergies belong to this patient | ok, 2 entries |
| page | TP-HISTORY Patient read, same session | ok, HTTP 200 |
| page | TP-HISTORY allergies belong to this patient | ok, 1 entry |
| page | out-of-scope read (`Procedure`) refused | ok, HTTP 401 |
| server | token endpoint | ok, HTTP 200 (client authenticated with HTTP Basic) |
| server | every requested scope granted | FAIL, but harmless: as in Spike A, only `api:fhir` is missing from the granted list, and every resource scope and `offline_access` were granted |
| server | bad token refused | ok, HTTP 401 |

Token: `expires_in` 3600, and a refresh token was issued (`offline_access`), so a BFF can renew access without sending the user back to the login page.

**What this means for Option B:** switching patients needs no new login, tokens stay on the server, and there's no CORS problem, because the browser only talks to its own origin. The costs are a second process to run, an admin step to enable the confidential client, and a session store.

## Error responses

The error-state tests in the build must match these exact responses.

| Case | Status | Body |
|---|---|---|
| Out-of-scope read (`Procedure`, not granted), patient-bound token | 401 | `{"error":"An error occurred","message":"Unauthorized","code":0}` |
| Bad or unknown bearer token | 401 | `{"error":"An error occurred","message":"The resource owner or authorization server denied the request.","code":0}` |
| Another patient requested with a patient-bound token | 200 | an empty Bundle, `total: 0`. **Not an error:** the app must detect it itself (BM-004). |
| CORS preflight to any FHIR route | 404 | `{"error":"An error occurred","message":"Route not found","code":0}` |

## Blockers and decisions

- 2026-09-26, **Gate 0 decision (user):** keep both auth options open, and compare them at Gate 3 together with the framework choice.
- 2026-09-26: Both spikes passed on everything that matters. The one failing check in each is the strict "every requested scope granted" string match: OpenEMR grants every resource scope but leaves `api:fhir` out of the reported list. This is recorded, not a blocker.
- 2026-09-26: **BM-004 (safety):** with a patient-bound token (Option A), a request for another patient returns HTTP 200 with an empty Bundle. The new app must check that every resource belongs to the patient in the header, and must never treat an empty result for a different patient as "nothing recorded".
- 2026-09-26: After Docker Desktop's WSL integration was switched on, the running `development-easy` containers had a stale source bind mount (OpenEMR saw 6 entries instead of the repo) and MySQL had been stopped for 14 hours. Fixed by recreating the stack with `docker compose up --detach --force-recreate --wait`, keeping the data volumes.
