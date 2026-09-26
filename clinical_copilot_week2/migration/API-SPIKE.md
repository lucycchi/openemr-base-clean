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

{{FILL}}

## Spike B: backend-for-frontend

{{FILL}}

## Error responses

{{FILL}}

## Blockers and decisions

- 2026-09-26: After Docker Desktop's WSL integration was switched on, the running `development-easy` containers had a stale source bind mount (OpenEMR saw 6 entries instead of the repo) and MySQL had been stopped for 14 hours. Fixed by recreating the stack with `docker compose up --detach --force-recreate --wait`, keeping the data volumes.
