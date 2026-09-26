# Patient Dashboard Migration

## Summary

I am porting OpenEMR's patient dashboard (the identity header, the Allergies, Problem List, Medications, Prescriptions and Care Team cards, and an Encounter history section) from server-rendered PHP to a **React + TypeScript** single-page app, fed only by OpenEMR's FHIR R4 API. Login uses OAuth2/OpenID Connect through a small **Node backend-for-frontend (BFF)** that holds a confidential client, so tokens never reach the browser. I chose this pairing because two working spikes showed that a browser cannot call OpenEMR's FHIR API directly, and React gives typed, testable card components that map one-to-one onto the old Twig cards.

Supporting evidence, all in `clinical_copilot_week2/migration/`:
- `API-SPIKE.md`: auth spikes and exact API behaviour
- `INVENTORY.md`: what the old dashboard loads
- `modules/*.md`: seven field-by-field audits
- `BUGS-MITIGATIONS.md`: 35 catalogued problems and the Gate 2 decisions
- `MIGRATION-OPTIONS.md`: the routes I compared
- `TEST-PATIENTS.md`: seven synthetic patients used for every comparison

## What was ported

| Section | Old source | New data source (FHIR R4 unless noted) | Parity status |
|---|---|---|---|
| Patient header | `interface/main/tabs/templates/patient_data_template.php` filled from `demographics.php:924`; age from `PatientService::getPatientAgeDisplay` | `Patient` (name, identifier type PT for MRN, birthDate, gender, deceasedDateTime) | Name, MRN and DOB match. Age is computed client-side with the same rules (BM-007). **Added:** sex and a status ("Deceased (date)" or "Active"), which the challenge requires but the old header never showed. |
| Allergies | `demographics.php:1115-1134`, `allergies.html.twig` | `AllergyIntolerance` | The list and reactions match. Severity shows as FHIR risk level (BM-011). An empty list says "No allergies recorded" (BM-012). |
| Problem List | `demographics.php:1140-1158`, `medical_problems.html.twig` | `Condition` (problem-list-item and encounter-diagnosis, merged) | Titles and completeness match, including problems linked to a visit, which FHIR moves out of the problem list (BM-043). Status handling was changed for safety (BM-017). |
| Medications | `demographics.php:1162-1180`, `medication.html.twig` | `MedicationRequest`, intent `plan`, plus each list entry's end date and outcome from the Standard REST API | Titles and dosage text match, and the old rule (hide resolved or past-ended entries, keep future-ended ones) is applied exactly, because FHIR does not send the end date (BM-044). The card split relies on intent (BM-019), and the old start-date order can't be reproduced from FHIR, so FHIR's order is kept (BM-036). |
| Prescriptions | `demographics.php:1184-1247`, Smarty `general_fragment.html` via `C_Prescription` | `MedicationRequest`, intent `order` | Drug, quantity and date added match, including prescriptions with an end date, which FHIR calls completed (BM-044). Refills show "Not available", because FHIR always sends 0 (BM-041). The dose detail is not in FHIR (BM-038). |
| Care Team | `CareTeamViewCard`, `manage_care_team.html.twig` | `CareTeam`, then `Practitioner`, `RelatedPerson` and `Organization` by reference | Team name, status, member type, roles and facility match (BM-045). The related person's since date and each member's status and note aren't in FHIR (BM-037), and names that FHIR can't resolve show as "Name unavailable" (BM-028). |
| Encounter history (extra) | `interface/patient_file/history/encounters.php` (Visit History) | `Encounter`, then `Practitioner` | Date, reason and provider match. Billing, insurance, issue and forms columns are not ported (BM-039). |

## Why this framework

1. **The spikes decided the architecture before the framework.** OpenEMR answers every CORS preflight on FHIR routes with HTTP 404, because routes are dispatched before its CORS listener can respond (`RoutesExtensionListener` priority 40 vs `CORSListener` 25, BM-001). A browser page on another origin therefore cannot send an authenticated FHIR request, so any browser app needs a server in front of the API. The BFF spike passed every functional check (one login, two patients, refresh token, 401s where expected). Its only failure was a strict string match on the granted-scope list, which omits `api:fhir` although every resource scope was granted. So I made that server the BFF.
2. **The BFF also removes a patient-safety trap.** With a patient-bound browser token, asking for another patient returns HTTP 200 with an empty list, not an error (BM-004), so a patient switch could show "no allergies" for the wrong person. A user-scoped BFF returns the patient actually requested.
3. **React + TypeScript matches the shape of the problem.** Each old card is a small, independent view over one resource type, which is exactly a React component. TypeScript with `@types/fhir` makes every FHIR field access type-checked. The audits found that most parity risk sits in the translation from FHIR to what the card shows, so I put that logic in pure mapper functions that are easy to unit-test.
4. **It is fast to build and light to host.** Vite plus a small Node BFF runs as one container of tens of MB, which fits the 3.9 GB droplet already running OpenEMR, MariaDB and the Python Co-Pilot sidecar. I compared Angular (more boilerplate for six cards, and not a real upgrade from AngularJS), Vue (a close second) and Next.js (heavier, and server-rendered, which is harder to justify as a presentation-layer move). See `MIGRATION-OPTIONS.md`.

## What moving off PHP gained

- **A typed, testable data path in place of SQL inside page code.** `demographics.php` issues its own queries, filters rows in page code (`filterActiveIssues`, line 1111) and hands arrays to templates. The new cards receive typed FHIR resources, and one mapper per card decides what is shown. The audits turned every display rule (filters, sort orders, empty states) into a named unit test on a fixture patient.
- **The access-control mistakes in the card code go away.** Three cards render their Edit button with `'auth' => true` hard-coded (BM-013). The Care Team card saves on construction with only a CSRF check (BM-026). The new dashboard is read-only, has no edit controls, and relies on OpenEMR's own API authorisation behind OAuth scopes.
- **Presentation bugs are fixed where they are cheap to fix:**
  - "Peanuts ()" when an allergy has no severity (BM-015)
  - an empty table when every prescription is discontinued (BM-024)
  - a "Filled" column that actually shows the date added (BM-023)
  - "1-0 of 0" on an empty visit list (BM-035)
  - a bare header row for an empty care team (BM-030)
- **Unsafe inferences are removed:**
  - The old allergy card says "No Known Allergies" whenever the list was ever saved and is now empty, even after a mistaken entry is deleted (BM-012). The new card only says what is true: "No allergies recorded".
- **Behaviour that no longer depends on legacy runtime quirks:**
  - The prescriptions card body is a Smarty fragment reached by changing the working directory and dispatching a legacy controller (`demographics.php:1234-1244`), and an eRx block depends on a variable that is never set in that request (BM-025).
  - The new card is one component and one API query.
- **Tokens are never exposed to the browser, and patient switching is a normal navigation,** not a session-wide side effect of `set_pid`.

## Tradeoffs and costs

- **FHIR is not a faithful view of OpenEMR's data, so parity is limited where FHIR is:**
  - *Problem status (BM-017):* FHIR reports a problem whose occurrence is "First" as `resolved`. I show every problem that isn't `inactive` and label the resolved ones, so no active problem is hidden. The cost is that a few truly resolved problems may appear, clearly labelled.
  - *Medications and Prescriptions (BM-019, BM-020):* FHIR merges the medication list and prescriptions into one `MedicationRequest` feed, and no field reliably says which is which. I split the two cards on `intent` (plan or order) and show a caveat: a list entry marked "Order" appears under Prescriptions. A medication linked to a prescription appears once, without the list's dosage text.
  - *Allergy severity (BM-011):* eight severities collapse to FHIR's low or high risk, so "Moderate" becomes "Low risk". The high-risk highlight is kept.
  - *Uncoded allergy names and markup (BM-009, BM-010):* an uncoded allergy's name is only in the FHIR narrative, which OpenEMR builds without HTML escaping. I read it as text, never as HTML, so a name like `Latex <b>x</b>` displays as "Latex x".
  - *Names (BM-028, BM-032):* FHIR's Practitioner endpoint only serves users with an NPI, so care-team members and encounter providers without one show as "Name unavailable".
  - *Header status (BM-005):* `Patient.active` is always true, so status is derived from `deceasedDateTime` instead.
  - *Refills (BM-041):* FHIR always sends 0 refills, so the column says "Not available" rather than a wrong number.
  - *Medication end dates (BM-044):* FHIR sends "completed" for any end date, past or future, and never the date itself. For the medication list only, the BFF reads each entry's end date and outcome from OpenEMR's Standard REST API, which needs three extra scopes (`api:oemr`, `user/patient.rs`, `user/medication.rs`). That API answers an empty list with a bodyless 404 (BM-046).
  - *Visit-linked problems (BM-043):* FHIR drops a problem from the problem list once it is linked to a visit, and returns it once per visit instead. The card reads both and merges the copies.
  - *The prescription dose detail and the encounter billing, insurance and forms columns* are not in FHIR and are not ported.
- **The API has safety traps the client must guard against:**
  - an unsupported `_include` returns an empty Bundle instead of an error (BM-029)
  - a patient-bound token returns an empty Bundle for another patient (BM-004)

  The new app never uses `_include`, and it treats an unexpected empty result as a load error, not as "nothing recorded".
- **Security observations in the API that I can't fix (the backend is out of scope):**
  - OpenEMR echoes any `Origin` into `Access-Control-Allow-Origin` (BM-002)
  - its preflight handling is unreachable (BM-001)
  - the FHIR Encounter search appears not to apply sensitivity restrictions that the old Visit History page enforces (BM-033, read from the code, not tested at runtime)
  - the FHIR MedicationRequest route checks medication access, not the prescription permission the old card required, so prescriptions are visible to anyone who may see medications (BM-042; kept by decision, and a site can hide the card)

  These are documented for the OpenEMR maintainers.
- **Operational cost:** a second process (the BFF) to deploy and monitor, a client secret to store, a session store, and an administrator step to enable the confidential client (it registers disabled).
- **Permissions move from page-level PHP ACL checks to OAuth scopes and the API's own authorisation.** Site-wide card hiding (`hide_dashboard_cards`) is read by the old dashboard straight from SQL and is not in the API, so the new app reads a hidden-cards list from its own configuration.
- **Not ported:** the edit workflows (every card is read-only), the reminders, disclosures, amendments, billing, insurance, portal, photos and other cards listed in the next section.

## Parity evidence

Parity is measured against the running old dashboard, not against my reading of its code:

- **Fixtures:** seven synthetic patients (`TEST-PATIENTS.md`). They cover a typical chart, an empty chart, "no known allergies", ended history, a deceased patient, long lists (25 allergies, 60 problems, 60 medications, 30 encounters) and names with special characters.
- **Field-level comparison:** each module audit records, for every field the user sees, the FHIR field it comes from, whether it matches, and the actual values observed for named fixtures in both systems. The old dashboard was read in a real browser through the dev stack's Selenium, and FHIR through the same OAuth client the app uses.
- **Automated suites** (defined in `clinical_copilot_week2/migration/MIGRATION-SPEC.md`):
  - one Playwright parity test per section, comparing each field on the old dashboard and the new app for the same fixture, with the approved exceptions from Gate 2 listed in the test
  - unit tests for every FHIR-to-view mapper
  - E2E tests for login and logout, rejected tokens, API failures shown as load errors, patient switching, and long lists

The results table is added here when the build's final arc runs the full parity suite.

## Not ported

- **Treatment intervention and care experience preferences:** not among the challenge's required or optional sections.
- **Demographics, billing and insurance cards:** administrative and financial; identity is covered by the header.
- **Deceased banner:** folded into the header's status.
- **Patient reminders and clinical reminders:** decision-support widgets, not listed by the challenge.
- **Disclosures and amendments:** record-keeping workflows, not listed.
- **Treatment plan / issue list and LBF chartable forms:** site-configured, with no stable FHIR mapping.
- **Portal, eRx notice, photos and ID card, advance directives, track anything:** not listed, and mostly write or launch workflows.
- **Delete patient:** a destructive admin action; the new dashboard is read-only.
- **Vitals, labs, notes, immunizations and appointments:** reviewed as candidate extra sections (`EXTRA-SECTION-OPTIONS.md`); Encounter history was chosen instead.
