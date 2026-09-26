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
| High | 3 |
| Medium | 3 |
| Low | 2 |

## Catalogue

| ID | Severity | Category | Module | Citation | Port action | Mitigation |
|---|---|---|---|---|---|---|
| BM-001 | High | correctness | API (CORS) | API routes are dispatched by `RoutesExtensionListener` (request priority 40) before `CORSListener::onKernelRequest` (priority 25) can answer OPTIONS, so a browser preflight to any FHIR route gets HTTP 404 Route not found with no Access-Control-Allow-Headers, and the browser blocks the call (src/RestControllers/Subscriber/RoutesExtensionListener.php:28, CORSListener.php:20; observed for every origin in API-SPIKE.md, Discovery and CORS) | out of scope | - |
| BM-002 | Medium | security observation | API (CORS) | `CORSListener::onKernelResponse` echoes any Origin into Access-Control-Allow-Origin (src/RestControllers/Subscriber/CORSListener.php:56-57; observed for https://unrelated.example in API-SPIKE.md). No attack path is shown: browsers do not send bearer tokens on their own, and BM-001 blocks authorised cross-origin calls anyway. | out of scope | - |
| BM-003 | Low | correctness | API (CORS) | `CORSListener::getInitialResponse` uses a comma instead of => so Access-Control-Allow-Methods is never set (src/RestControllers/Subscriber/CORSListener.php:69) | out of scope | - |
| BM-004 | High | safety observation | API (SMART patient context) | With a patient-bound token, `GET /fhir/AllergyIntolerance?patient=<another patient>` returns HTTP 200 with an empty Bundle instead of an error, because the route passes the bound patient to the service (apis/routes/_rest_routes_fhir_r4_us_core_3_1_0.inc.php:75-77); observed in Spike A | fix in the new app | Under Option A, switching patients always starts a new launch. Every card checks that the token's patient matches the header patient before showing data, and every resource's patient reference matches the header patient. A mismatch shows a load error, never an empty "nothing recorded" card. |
| BM-005 | High | correctness | Header | `FhirPatientService` always calls `setActive(true)`, so Patient.active is true even for a deceased patient (src/Services/FHIR/FhirPatientService.php:212; TP-DECEASED returns active=true with deceasedDateTime 2025-11-02) | fix in the new app | The header never reads Patient.active. Status comes from Patient.deceasedDateTime: "Deceased (date)" when present, otherwise "Active" (definition confirmed at Gate 2). A mapper unit test pins TP-DECEASED. |
| BM-006 | Low | correctness | Header | The identity bar requests the patient photo with `document_id=-1` on every chart open; the ACL denies it and logs "Access denied: Unauthorized attempt to retrieve document -1" (interface/main/tabs/js/patient_data_view_model.js:30-39) | out of scope | - |
| BM-007 | Medium | correctness | Header | Age and "Age at death" are computed server-side by `PatientService::getPatientAgeDisplay` with site rules (months under 2 years; age_display_format and age_display_limit globals), while FHIR only returns birthDate and deceasedDateTime (src/Services/PatientService.php:718-760) | fix in the new app | Port the age rules into one pure function with unit tests for under-2 months, birthdays, leap days and age at death. Read the two globals from deployment configuration. |
| BM-008 | Medium | security (unverified) | Header | The dashboard hides a patient whose squad the user is not in (interface/patient_file/summary/demographics.php:1069); no FHIR equivalent was found, and whether the API enforces squads is unverified | out of scope | - |
