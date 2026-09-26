# Module: Patient header

**Source files:** identity bar (tab frame), dashboard page heading, age helper, FHIR Patient service:
- `interface/main/tabs/templates/patient_data_template.php:50-115` (identity bar markup, Knockout)
- `interface/main/tabs/js/patient_data_view_model.js:22-60`
- `interface/main/tabs/js/frame_proxies.js:25-45` (`setPatient`)
- `interface/patient_file/summary/demographics.php:918-930` (`setMyPatient`), `365-377` (page heading settings), `1056-1070` (access checks), `1076` (dashboard header include)
- `interface/patient_file/summary/dashboard_header.php`, `templates/patient/dashboard_header.html.twig`, `src/OeUI/OemrUI.php:150-170` (page heading)
- `src/Services/PatientService.php:718-760` (age display)
- FHIR: `src/Services/FHIR/FhirPatientService.php:190-240, 540-570, 730-760`

**Test patients used:** TP-TYPICAL, TP-DECEASED, TP-ESCAPING

## 1. Purpose and lifecycle

The "header" is two separate things:

1. **The identity bar**, which sits in the tab frame (`main.php`) above every patient page. It is not part of the dashboard page. When the dashboard is opened with `set_pid`, its inline `setMyPatient()` (demographics.php:918-930) calls `parent.left_nav.setPatient(name, pid, pubpid, '', dobText)`. `frame_proxies.js` then creates or updates the Knockout `patient_data_view_model`, and the template re-renders. It stays on screen until another patient is chosen or the chart is closed with its × link.
2. **The page heading**, "Medical Record Dashboard", rendered inside the dashboard by `dashboard_header.php` → `dashboard_header.html.twig` → `OemrUI::pageHeading()`. It carries the page's action buttons (edit, help, expand), not identity.

Everything is server-rendered on page load. The DOB and age text is built in PHP (`oeFormatShortDate` plus `getPatientAgeDisplay`, or `oeFormatAge` for "Age at death") and passed to the browser as one preformatted string. Nothing is fetched later, except the patient photo (`controller.php?document&retrieve&document_id=-1&context=patient_picture`).

## 2. What the user sees

| Field shown | Format | Source column |
|---|---|---|
| Patient name | "First Last", link back to the dashboard | patient_data.fname, lname |
| MRN | "(36)" in muted text after the name | patient_data.pubpid |
| Date of birth | "DOB: 1958-03-14" in the site date format | patient_data.DOB |
| Age | "Age: 68"; in months under 2 years; "Age at death: 93" when deceased | computed from DOB and today, or from `is_patient_deceased()` |
| Patient photo | 75×75 thumbnail, default silhouette on error | documents in the patient photo category |
| Encounter selector | "Select Encounter (0)" dropdown, plus "Open Encounter" when one is chosen | form_encounter |
| Page heading | "Medical Record Dashboard" and action buttons | constant (demographics.php:367) |

**Sort order:** not applicable (single record)
**Filtered out:** None
**Empty state text:** None. The bar is hidden (`ko if: patient`) until a patient is set.
**Visibility rules:** the bar shows for every patient page in the tab frame. The dashboard content is replaced by `core/unauthorized-partial.html.twig` when `aclCheckCore('patients','demo')` fails (demographics.php:1056-1059), or when the patient has a squad the user isn't in (1069).

**Not shown by the old header:** sex, and any "active/inactive" status. The challenge requires both (see the Active status definition below). The only status signal today is "Age at death" in place of "Age".

Observed on the old dashboard (`tmp/dash_text.php`, 2026-09-26):
- TP-TYPICAL: `Tessa Typical (36)` / `DOB: 1958-03-14 Age: 68`
- TP-DECEASED: `Dora Deceased (40)` / `DOB: 1932-05-09 Age at death: 93`
- TP-ESCAPING: `Zoë O'Brien-Núñez (42)` / `DOB: 1988-12-12 Age: 37`, with the apostrophe and accents shown as text

## 3. Controls

| Control | What it does | New dashboard |
|---|---|---|
| Patient name link | reloads the dashboard (`refreshPatient`) | shows the name only; the new app is the dashboard |
| × (close chart) | clears the current patient (`clearPatient`) | leaves it out; the new app is a single-patient view |
| Visit History button | opens the encounter list | links out to OpenEMR, or leaves it out if Encounters isn't the chosen extra section |
| Select Encounter dropdown / New Encounter | encounter workflow | leaves it out (write workflow) |
| Page heading action buttons | edit demographics, help, expand/collapse | leaves them out (read-only port) |

## 4. Permission checks

| Check | Line | Scope in the new app |
|---|---|---|
| aclCheckCore patients demo, or show unauthorized | demographics.php:1056-1059 | Option A: `patient/Patient.rs`; Option B: `user/Patient.rs`. The server's own ACL still applies to the logged-in user. |
| squad check: aclCheckCore squads (patient's squad) | demographics.php:1069 | no FHIR equivalent. The API applies its own access rules, and whether it honours squads is unverified (see BM-008). |
| photo retrieval ACL | controller.php document retrieve | not needed: the photo is not ported |

## 5. Data

**Reads:** `patient_data` (fname, lname, pubpid, DOB, sex, squad, deceased_date) through `getPatientData()`; `is_patient_deceased()`; `form_encounter` for the encounter list; `documents` for the photo.
**Writes:** None

| Field shown | FHIR resource.field | Status | Checked against | Notes |
|---|---|---|---|---|
| Patient name | Patient.name[use=official].given + family | matches | TP-TYPICAL: given [Tessa], family Typical; TP-ESCAPING: given [Zoë], family O'Brien-Núñez | the new app must render it as text |
| MRN | Patient.identifier[type.coding.code=PT].value | matches | TP-TYPICAL: PT=36, pubpid 36; TP-DECEASED: PT=40 | the identifier system is v2-0203 |
| Date of birth | Patient.birthDate | matches | TP-TYPICAL: 1958-03-14 | the new app formats it with the site date format (`date_display_format`), which is not in FHIR |
| Age | computed from Patient.birthDate | differs | TP-TYPICAL: old "Age: 68", FHIR birthDate only | the new app must copy `getPatientAge` (months under 2 years) and honour `age_display_format` and `age_display_limit` (BM-007) |
| Age at death | computed from Patient.birthDate and Patient.deceasedDateTime | differs | TP-DECEASED: old "Age at death: 93", FHIR deceasedDateTime 2025-11-02T00:00:00+00:00 | computed client-side (BM-007) |
| Sex (not shown in old header) | Patient.gender, US Core birthsex extension | not available | TP-TYPICAL: gender female, birthsex F; patient_data.sex Female | new field required by the challenge; FHIR has it |
| Status (not shown in old header) | Patient.active, Patient.deceasedDateTime | differs | TP-DECEASED: active=true while deceasedDateTime=2025-11-02 | Patient.active is hard-coded true (BM-005); status must come from deceasedDateTime |
| Patient photo | none (DocumentReference would need extra scopes) | not available | TP-TYPICAL: no photo document | not ported |
| Encounter selector | Encounter?patient= | not available | TP-TYPICAL: 0 encounters | a write workflow, not ported |
| List completeness | single resource (Patient/{id}) | matches | TP-TYPICAL, TP-DECEASED, TP-ESCAPING: HTTP 200, one resource each | no list involved |

**Active status definition:** the old dashboard has no active or inactive status. What it does show is whether the patient is deceased: "Age at death: N" replaces "Age: N" (demographics.php:924-929), and a deceased banner card is rendered (demographics.php:1334, `patient/partials/deceased.html.twig`). FHIR `Patient.active` cannot be used, because OpenEMR always sets it to `true` (`FhirPatientService.php:212`, confirmed on TP-DECEASED). Proposed for Gate 2: the header shows **"Deceased (date)" when `Patient.deceasedDateTime` is present, otherwise "Active"**, and never reads `Patient.active`. The user confirms or changes this at Gate 2.

## 6. Globals

| Global | Read or write | Effect on the card |
|---|---|---|
| patient_name_display | read (patient_data_template.php:69) | chooses how the name is styled (button, large text or plain) |
| date_display_format | read (oeFormatShortDate) | DOB format |
| age_display_format, age_display_limit | read (PatientService.php:720-722) | age as years, or as years-months-days under the limit |
| $pid, $result, $_GET['set_pid'] | read (demographics.php:918-930) | which patient `setPatient()` is called with; the bar only updates when `set_pid` is present |
| session pid | write (pid.inc.php via set_pid) | sets the current patient for the whole session |

## 7. Problems

- BM-005: FHIR `Patient.active` is hard-coded `true`, including for deceased patients (`FhirPatientService.php:212`).
- BM-006: the identity bar requests the patient photo with `document_id=-1` on every chart open, the ACL denies it, and an "Access denied" warning is logged each time (`patient_data_view_model.js:30-39`, logged in the Apache error log on 2026-09-26).
- BM-007: age is computed server-side with site-specific rules (`PatientService::getPatientAgeDisplay`), and FHIR only supplies `birthDate`.
- BM-008: the squad restriction (demographics.php:1069) has no FHIR equivalent. It is unverified whether the FHIR API refuses patients in a squad the user doesn't belong to.
