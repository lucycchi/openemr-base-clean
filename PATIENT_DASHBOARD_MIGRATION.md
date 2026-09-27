# Patient Dashboard Migration

## Summary

I ported OpenEMR's patient dashboard (the identity header, the Allergies, Problem List, Medications, Prescriptions and Care Team cards, and an Encounter history section) from server-rendered PHP to a **React + TypeScript** single-page app. It reads OpenEMR's FHIR R4 API, plus OpenEMR's Standard REST API for the three lists FHIR reports wrongly (medications, allergies, problems). Login uses OAuth2/OpenID Connect through a small **Node backend-for-frontend (BFF)** that holds a confidential client, so tokens never reach the browser; a second, server-only client reads staff and facility names, which OpenEMR's API shows only to administrators. I chose this pairing because two working spikes showed that a browser cannot call OpenEMR's FHIR API directly, and React gives typed, testable card components that map one-to-one onto the old Twig cards. Every section passes a parity test against the running old dashboard (see Parity evidence), and the page keeps the old look: the same Bootstrap card box with a collapsible title, the first three cards side by side, and an identity header that stays in view. Clinicians can add, change and discontinue prescriptions from the dashboard; the other cards link to OpenEMR for editing, because OpenEMR's write API cannot record their clinical fields (see Tradeoffs). It is deployed next to OpenEMR on the droplet at https://dashboard.146-190-139-37.sslip.io.

Supporting evidence, all in `clinical_copilot_week2/migration/`:
- `API-SPIKE.md`: auth spikes and exact API behaviour
- `INVENTORY.md`: what the old dashboard loads
- `modules/*.md`: seven field-by-field audits
- `BUGS-MITIGATIONS.md`: every catalogued problem (BM-001 onwards), its port action and mitigation, and the Gate 2 decisions
- `MIGRATION-OPTIONS.md`: the routes I compared
- `TEST-PATIENTS.md`: seven synthetic patients, plus the seeded edge cases in `fixtures/seed-parity-gaps.php`, used for every comparison
- `DEV-LOG.md`: every slice, test-first, including the fixes from five independent parity reviews

## What was ported

| Section | Old source | New data source (FHIR R4 unless noted) | Parity status |
|---|---|---|---|
| Patient header | `interface/main/tabs/templates/patient_data_template.php` filled from `demographics.php:924`; age from `PatientService::getPatientAgeDisplay` | `Patient` (name, identifier type PT for MRN, birthDate, gender, deceasedDateTime) | Name, MRN and DOB match. Age is computed client-side with the same rules (BM-007). **Added:** sex and a status ("Deceased (date)" or "Active"), which the challenge requires but the old header never showed. |
| Allergies | `demographics.php:1115-1134`, `allergies.html.twig` | `AllergyIntolerance`, plus each allergy's end date and resolved flag from the Standard REST API | The list and reactions match, and the old rule (hide resolved or past-ended, keep future-ended) is applied exactly, because FHIR's status is wrong both ways (BM-016, BM-047). Severity shows as FHIR risk level (BM-011). An empty list says "No allergies recorded" (BM-012). **Editing:** an "Edit in OpenEMR" link opens the patient's chart in OpenEMR, because the write API cannot record this card's clinical fields (BM-062). |
| Problem List | `demographics.php:1140-1158`, `medical_problems.html.twig` | Standard REST API problem list (the old card's own source) | Titles, order and completeness match, including problems FHIR leaves out (BM-051); the old rule (hide resolved or past-ended) is applied exactly, so FHIR's status problems no longer apply (BM-017, BM-043 superseded). **Editing:** an "Edit in OpenEMR" link opens the patient's chart in OpenEMR, because the write API cannot record this card's clinical fields (BM-062). |
| Medications | `demographics.php:1162-1180`, `medication.html.twig` | Standard REST API medication list (the old card's own source), with dosage from the matching FHIR `MedicationRequest` | Titles, order and dosage match, and the old rule (hide resolved or past-ended, keep future-ended) is applied exactly (BM-044). Every list entry is here whatever its intent, so hiding Prescriptions hides nothing else (BM-019). A list entry linked to a prescription has no dosage, because FHIR carries only the prescription (BM-020). **Editing:** an "Edit in OpenEMR" link opens the patient's chart in OpenEMR, because the write API cannot record this card's clinical fields (BM-062). |
| Prescriptions | `demographics.php:1184-1247`, Smarty `general_fragment.html` via `C_Prescription` | `MedicationRequest` records that are not list entries, whatever their intent | Drug, quantity and date added match exactly, including prescriptions with an end date, which FHIR calls completed (BM-044). Refills show "Not available", because FHIR always sends 0 (BM-041). The dose detail is not in FHIR (BM-038). **Editing:** add, change (the corrected prescription is added, then the old one discontinued) and discontinue, through the Standard REST API (ARC-06; BM-064, BM-065, BM-067). |
| Care Team | `CareTeamViewCard`, `manage_care_team.html.twig` | `CareTeam`, then `Practitioner` and `Organization` names through the BFF's server-only client, and `RelatedPerson` by reference | Team name, status, member type, roles and facility match for every user, not only administrators (BM-045, BM-048). FHIR does not say whether a member was removed, so the card carries a note telling clinicians to check in OpenEMR (BM-053). The related person's since date and each member's status and note aren't in FHIR (BM-037), and names that FHIR has no record for show as "Name unavailable" (BM-028). **Editing:** an "Edit in OpenEMR" link opens the patient's chart in OpenEMR, because the write API cannot record this card's clinical fields (BM-063). |
| Encounter history (extra) | `interface/patient_file/history/encounters.php` (Visit History) | `Encounter`, then `Practitioner` names through the BFF's server-only client | Date, reason and provider match, in the old order including same-day visits, and in the site date format. Billing, insurance, issue and forms columns are not ported (BM-039). OpenEMR's default Clinicians group cannot search encounters through the API (BM-049). |

## Why this framework

1. **The spikes decided the architecture before the framework.** OpenEMR answers every CORS preflight on FHIR routes with HTTP 404, because routes are dispatched before its CORS listener can respond (`RoutesExtensionListener` priority 40 vs `CORSListener` 25, BM-001). A browser page on another origin therefore cannot send an authenticated FHIR request, so any browser app needs a server in front of the API. The BFF spike passed every functional check (one login, two patients, refresh token, 401s where expected). Its only failure was a strict string match on the granted-scope list, which omits `api:fhir` although every resource scope was granted. So I made that server the BFF.
2. **The BFF also removes a patient-safety trap.** With a patient-bound browser token, asking for another patient returns HTTP 200 with an empty list, not an error (BM-004), so a patient switch could show "no allergies" for the wrong person. A user-scoped BFF returns the patient actually requested.
3. **React + TypeScript matches the shape of the problem.** Each old card is a small, independent view over one resource type, which is exactly a React component. TypeScript with `@types/fhir` makes every FHIR field access type-checked. The audits found that most parity risk sits in the translation from FHIR to what the card shows, so I put that logic in pure mapper functions that are easy to unit-test.
4. **It is fast to build and light to host.** Vite plus a small Node BFF runs as one container using about 45 MB of memory (capped at 256 MB), which fits the 3.9 GB droplet already running OpenEMR, MariaDB and the Python Co-Pilot sidecar. I compared Angular (more boilerplate for six cards, and not a real upgrade from AngularJS), Vue (a close second) and Next.js (heavier, and server-rendered, which is harder to justify as a presentation-layer move). See `MIGRATION-OPTIONS.md`.

## What moving off PHP gained

- **A typed, testable data path in place of SQL inside page code.** `demographics.php` issues its own queries, filters rows in page code (`filterActiveIssues`, line 1111) and hands arrays to templates. The new cards receive typed FHIR resources, and one mapper per card decides what is shown. The audits turned every display rule (filters, sort orders, empty states) into a named unit test on a fixture patient.
- **The access-control mistakes in the card code go away.** Three cards render their Edit button with `'auth' => true` hard-coded (BM-013). The Care Team card saves on construction with only a CSRF check (BM-026). The new dashboard's only write is to prescriptions, through OpenEMR's own API authorisation behind OAuth scopes; the other cards have no edit controls and link to OpenEMR, and nothing offers delete.
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
  - *Problem list (BM-017, BM-043, BM-051):* FHIR reports some active problems as resolved, drops a problem from the problem list once it is linked to a visit, and leaves out problems without `activity = 1`. The card is built from the standard API problem list instead, the old card's own source, so none of these apply.
  - *Medications and Prescriptions (BM-019, BM-020):* FHIR merges the medication list and prescriptions into one `MedicationRequest` feed with no reliable field saying which is which. The standard API medication list has the same ids, so a record is a list entry exactly when its id is on that list; the cards are split that way, not by intent. A list entry linked to a prescription appears on both cards, as on the old dashboard, without its list dosage.
  - *Allergy severity (BM-011):* eight severities collapse to FHIR's low or high risk, so "Moderate" becomes "Low risk". The high-risk highlight is kept.
  - *Allergy names and markup (BM-009, BM-010, BM-060):* FHIR puts an uncoded allergy's name only in its narrative, built without HTML escaping, and a coded allergy's name is the code's description. The card shows the title the clinician entered, from the standard API list, as text, never as HTML: `Latex <b>x</b>` displays literally, exactly as the old card shows it.
  - *Names (BM-028, BM-032, BM-048):* OpenEMR's API lets only administrators read Practitioner and Organization, so the BFF reads staff and facility names with its own server-only client (SMART backend services, `system/Practitioner.rs` and `system/Organization.rs` only) and returns the name alone. The Practitioner endpoint still only serves users with an NPI, so a provider without one, and a related person, show as "Name unavailable".
  - *Removed care-team members (BM-053):* the old card hides members marked inactive, which is what its Remove button sets. FHIR lists every member with no status and no other API exposes it, so a removed member looks current. The card says so in a note; teams marked entered-in-error are hidden and the badge follows the team status.
  - *Problems FHIR leaves out (BM-051):* FHIR's problem list only returns rows with `activity = 1`, which Fee Sheet problems lack. The card reads the standard API problem list instead, which is exactly what the old card reads (`user/medical_problem.rs`).
  - *Failing closed:* if the medication list dates cannot be read, both medication cards show a load error, because FHIR alone cannot tell a finished list entry marked Order from a current prescription (BM-044). No clinical card is shown until the patient header has loaded and matched the patient, as the old page stops when the patient cannot be shown. End dates are compared with the current date and time, as the old rule does.
  - *Allergy status (BM-016, BM-047):* FHIR calls a resolved allergy with no end date "active" and a still-current allergy with a future end date "inactive". Like the medication list, the card reads each allergy's end date and resolved flag from the Standard REST API (`user/allergy.rs`).
  - *Header status (BM-005):* `Patient.active` is always true, so status is derived from `deceasedDateTime` instead.
  - *Refills (BM-041):* FHIR always sends 0 refills, so the column says "Not available" rather than a wrong number.
  - *Medication end dates (BM-044):* FHIR sends "completed" for any end date, past or future, and never the date itself. The Medications card is built from OpenEMR's Standard REST API medication list, which carries the end date and outcome. Reading the three standard-API lists needs extra scopes (`api:oemr`, `user/patient.rs`, `user/medication.rs`, `user/allergy.rs`, `user/medical_problem.rs`). That API answers an empty medication list with a bodyless 404 (BM-046).
  - *The prescription dose detail and the encounter billing, insurance and forms columns* are not in FHIR and are not ported.
- **The API has safety traps the client must guard against:**
  - an unsupported `_include` returns an empty Bundle instead of an error (BM-029)
  - a patient-bound token returns an empty Bundle for another patient (BM-004)

  The new app never uses `_include`, and it never holds a patient-bound token, so neither trap applies. Every record OpenEMR returns must name the patient on screen, or the card shows a load error. An empty result is shown as "nothing recorded", which is only safe because of those two guards.
- **Security observations in the API that I can't fix (the backend is out of scope):**
  - OpenEMR echoes any `Origin` into `Access-Control-Allow-Origin` (BM-002)
  - its preflight handling is unreachable (BM-001)
  - the FHIR Encounter search does not apply the sensitivity restrictions the old Visit History page enforces, so a group with visit search but no sensitivity permission (OpenEMR's default Accounting group) sees reasons the old page masked (BM-033). The card says so, and sites should not grant `encounters auth_a` to groups that must not see reasons.
  - the FHIR MedicationRequest route checks medication access, not the prescription permission the old card required, so prescriptions are visible to anyone who may see medications (BM-042; kept by decision, and a site can hide the card)

  These are documented for the OpenEMR maintainers.
- **Operational cost:** a second process (the BFF) to deploy and monitor, a client secret and a private key to store, a session store, and an administrator step to enable two clients (they register disabled): the user-facing confidential client and the server-only names client, which also needs OpenEMR's `rest_system_scopes_api` setting on.
- **Idle sign-out.** The page signs out after the site's idle timeout (`IDLE_TIMEOUT_SECONDS`, mirroring OpenEMR's `timeout`, default 2 hours) and rechecks its session every minute; signing out of OpenEMR itself does not end the dashboard session (BM-056).
- **Care Team needs `patients/med`.** FHIR CareTeam checks `patients/med`, where the old card needed only `patients/demo`, so demo-only users do not see the card (BM-057).
- **The problem list's permission is re-checked.** The standard problem list only checks `encounters/notes`; the BFF first requires the user's own FHIR Condition search (`patients/med`, the old card's permission) to succeed (BM-055).
- **The proxy forwards only the query parameters the app uses.** OpenEMR's rewrite rule would otherwise let a forwarded `_REWRITE_COMMAND` send a request to any API route (BM-054).
- **A server-held credential that can read the staff directory.** The names client can read every Practitioner and Organization. It is used only for a logged-in user, only for those two resource types, and the route returns the display name alone; the old page already showed these names to anyone who could open the patient (BM-048).
- **Permissions move from page-level PHP ACL checks to OAuth scopes and the API's own authorisation.** Site-wide card hiding (`hide_dashboard_cards`) is read by the old dashboard straight from SQL and is not in the API, so the new app reads a hidden-cards list from its own configuration.
- **Collapsed cards are remembered by the BFF, not by OpenEMR.** The old card saves each user's collapsed cards in OpenEMR's user settings, which only a logged-in OpenEMR page can write. The BFF reads the user from the login's token and keeps the same per-user choice in a JSON file on a Docker volume, so a collapsed card stays collapsed across visits, patients and browsers. The two are separate: collapsing a card in the old dashboard does not collapse it here. The user comes from the OpenID Connect ID token's `sub`, after checking its issuer, audience and expiry.
- **Editing through OpenEMR's write API (ARC-06).** OpenEMR's write API is much thinner than its read API, so editing was ported only where it can be done without losing clinical data:
  - *Allergies, problems and medications (BM-062):* the API silently drops an allergy's reaction, severity, outcome and verification, a problem's comments, and a medication's dosage instructions, and gives a new medication no uuid. Editing them in the dashboard would save incomplete records, so each card has an "Edit in OpenEMR" link. It opens the patient's chart page on its own, without OpenEMR's menu, because OpenEMR has no link that opens a patient inside its full screen; a user not signed in to OpenEMR signs in there and lands on OpenEMR's home screen, so the note says to click again. OpenEMR's session cookie is `SameSite=Strict`, so the link works when the dashboard and OpenEMR share a site, as on the droplet.
  - *Care team (BM-063):* no API route can change a care team, so the card links to OpenEMR too.
  - *Prescriptions (BM-064, BM-065):* the API can add and discontinue but not update, so Change adds the corrected prescription and then discontinues the old one; if the second step fails, the page says both exist rather than showing none. The BFF sets what the old form sets (date added, start date, intent, category). The date added is the browser's local time, which assumes the clinic's clock matches OpenEMR's time zone.
  - *Prescriber (BM-065):* OpenEMR's prescriber field only takes its numeric user id, which only admins can read through the API, so the form's Prescriber box is saved in the prescription's note as "Prescriber: ..."; OpenEMR's own prescriber field stays empty and the typed name is not checked.
  - *Permissions (BM-065):* the API checks the medications permission for prescription writes, not the prescriptions permission. In a default install the same groups can write prescriptions as before; a site that took prescribing away from a group but kept its medications access would still find prescriptions writable.
  - *Discontinued prescriptions look current in OpenEMR's screens (BM-067).* The API marks a discontinued prescription `active = 0`, and OpenEMR's legacy screens skip empty values when they load a record, so they show it as active; the old form uses `-1`. Kept by decision: the dashboard and FHIR show it as stopped, and the Discontinue question warns that OpenEMR's own screens will still list it. A browser test pins the behaviour, so a fix in OpenEMR will show up.
  - *No deletes (BM-066):* the API's allergy, problem and medication deletes are permanent and open to any clinician, where the old screens let only superusers delete, so the dashboard never deletes.
  - A backend change, such as a custom module with proper write routes, is a possible follow-up that would let all of this move into the dashboard.
- **Not ported:** the edit workflows for allergies, problems, medications and the care team (they link to OpenEMR), the reminders, disclosures, amendments, billing, insurance, portal, photos and other cards listed in the next section.

## Parity evidence

Parity was measured against the running old dashboard, not against my reading of its code:

- **Fixtures:** seven synthetic patients (`TEST-PATIENTS.md`) covering a typical chart, an empty chart, "no known allergies", ended history, a deceased patient, long lists (25 allergies, 60 problems, 60 medications, 30 encounters) and names with special characters. The reviews added seeded edge cases (`fixtures/seed-parity-gaps.php`), including:
  - a problem linked to two visits, a Fee Sheet problem with no activity, and future-ended medications and allergies
  - a prescription with refills, a resolved allergy with no end date, and a severe allergy
  - two visits on one day, a care-team facility, a care team with an NPI provider, a middle name, and a non-admin physician
- **Field-level comparison:** each module audit records, for every field the user sees, its source, whether it matches, and the values observed for named fixtures in both systems. The old dashboard was read in a real browser through the dev stack's Selenium.
- **Reviews:** five independent reviews (Codex twice, Fable twice, Opus once) compared the new code with the old PHP. Each gap they found was fixed test-first or recorded as a user decision in `BUGS-MITIGATIONS.md`. A final Codex check against the challenge text found a login bug (the ID token was dropped) and three inaccurate lines in this document; all are fixed (`clinical_copilot_week2/migration/CHALLENGE-COMPLIANCE.md`).

### Results

Full parity suite (`npm run test:parity`) on commit `5238e83`, 2026-09-27, against the development-easy stack: **9 of 9 tests passed**, including the two self-tests of the old-dashboard reader.

| Section | Fields compared | Fixtures | Result | Approved exceptions |
|---|---|---|---|---|
| Patient header | name (without middle names), MRN, DOB line with age or age at death | all seven | Pass | BM-052 (middle name shown), BM-040 (infant age text spaced) |
| Allergies | which allergies, in order; name; reaction | TYPICAL, EMPTY, NKA, HISTORY, LONG, ESCAPING | Pass | BM-011 (risk level wording), BM-012 (empty text), BM-015 (no empty brackets) |
| Medical Problems | which problems, in order | TYPICAL, EMPTY, HISTORY, LONG | Pass | BM-012 (empty text) |
| Medications | which entries, in order; dosage | TYPICAL, EMPTY, HISTORY, LONG | Pass | BM-012 (empty text), BM-020 (linked entry has no dosage) |
| Prescriptions | drug, quantity, date added, in order, nothing extra; refills | TYPICAL, EMPTY, HISTORY, LONG | Pass | BM-023 ("Added" label), BM-024 (empty text), BM-038 (Details not in FHIR), BM-041 (refills "Not available"), BM-044 (end-dated shown) |
| Care Team | team name and status; member type, role, facility; since where FHIR has it; names that can be resolved, with Donna Lee pinned | TYPICAL, EMPTY, LONG | Pass | BM-028 (unresolvable names), BM-030 (empty text), BM-037 (since, status, note), BM-053 (removed members noted) |
| Encounter history | date, reason, provider (named wherever FHIR sends one), first page and all visits | TYPICAL, HISTORY, LONG, EMPTY | Pass | BM-032 (provider without an NPI), BM-034 ("Show all"), BM-035 (empty text), BM-039 (billing, forms, insurance not ported) |

Every parity test was also shown to fail when the rule it guards was broken on purpose (recorded per slice in `DEV-LOG.md`). Alongside it, on the same commit:
- **Unit tests:** 342 of 342 pass, covering every mapper, hook, card and BFF route.
- **End-to-end tests:** 33 of 33 pass, covering:
  - login, logout, idle sign-out and ended sessions
  - load failures shown as errors, and cards a user may not see left out
  - patient switching with no stale data, and every card rejecting another patient's data
  - the high-risk highlight, the cards that link to OpenEMR instead of editing, and a non-admin physician
  - a physician adding, changing and discontinuing a prescription on the write-test patient TP-RXEDIT, checked against the old dashboard
  - the old layout: the header stays in view while a long chart scrolls, the first three cards share a row, and each card's title collapses it
  - a collapsed card staying collapsed after a reload, for another patient, and after signing out and in
- **Ledger:** every "fix in the new app" row in `BUGS-MITIGATIONS.md` is resolved.
- **Deployed:** 5 of 5 smoke tests pass against the droplet: health, login, TP-TYPICAL with every card and a named provider for a non-admin physician, TP-DECEASED's status, and a saved card layout for that physician.

## Not ported

- **Treatment intervention and care experience preferences:** not among the challenge's required or optional sections.
- **Demographics, billing and insurance cards:** administrative and financial; identity is covered by the header.
- **Deceased banner:** folded into the header's status.
- **Patient reminders and clinical reminders:** decision-support widgets, not listed by the challenge.
- **Disclosures and amendments:** record-keeping workflows, not listed.
- **Treatment plan / issue list and LBF chartable forms:** site-configured, with no stable FHIR mapping.
- **Portal, eRx notice, photos and ID card, advance directives, track anything:** not listed, and mostly write or launch workflows.
- **Delete patient:** a destructive admin action; the new dashboard never deletes anything.
- **Links into OpenEMR:** the Allergies, Medical Problems, Medications and Care Team cards link to the patient's chart in OpenEMR; each old Visit History row opens that visit, and the new visit rows do not.
- **Translation:** the old templates pass every label through OpenEMR's translation tables (`xlt`), so a site set to Spanish sees Spanish labels. The new app's labels and messages are English only. The clinical data itself (titles, reactions, reasons) is shown as entered, as before.
- **Vitals, labs, notes, immunizations and appointments:** reviewed as candidate extra sections (`EXTRA-SECTION-OPTIONS.md`); Encounter history was chosen instead.
