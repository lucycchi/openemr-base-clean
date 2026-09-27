# Module: Prescriptions

**Source files:** `interface/patient_file/summary/demographics.php:1098, 1184-1247`; `templates/patient/card/rx.html.twig:1-15`; `templates/patient/card/erx.html.twig`; `templates/patient/partials/erx.html.twig`; `controllers/C_Prescription.class.php:286-300` (fragment_action); `templates/prescription/general_fragment.html:1-42`; `library/classes/Prescription.class.php:1205-1230` (prescriptions_factory ordering); `interface/patient_file/summary/stats.php:136-180` (eRx current-medications path); FHIR `src/Services/FHIR/FhirMedicationRequestService.php`, `src/Services/PrescriptionService.php`
**Test patients used:** TP-TYPICAL, TP-EMPTY, TP-HISTORY

## 1. Purpose and lifecycle

The card is server-rendered on page load. `$rx` is true when `disable_prescriptions` is off, `aclCheckCore('patients','rx')` passes, and `card_prescriptions` isn't hidden (demographics.php:1098).

The card's body isn't Twig. `demographics.php` changes directory to the web root and calls the legacy `Controller::dispatch(['controller' => 'prescription', 'action' => 'fragment', 'patient_id' => $pid])` (1236-1244). `C_Prescription::fragment_action` loads `Prescription::prescriptions_factory($pid)` and renders the Smarty `general_fragment.html`. The captured HTML is passed as `content` to `rx.html.twig`, which prints it inside `card_base`. The card starts collapsed according to the user's saved setting (`prescriptions_ps_expand`), and it is collapsed on this stack.

| erx_enable | display_current_medications_below | What renders |
|---|---|---|
| off (this stack) | n/a | core card titled "Prescriptions"; Edit opens `controller.php?prescription&list&id=<pid>` in a dialog (`editScripts`) |
| on | never set in demographics.php | core card retitled "Prescription History", with an "Add" button to `eRx.php?page=compose`. The extra "Current Medications" card at demographics.php:1186-1210 **never renders**, because the variable only exists in stats.php (a separate request), which renders its own `erx.html.twig` (stats.php:136-180). |

The dev stack has eRx off, so only the first row can be observed here.

## 2. What the user sees

| Field shown | Format | Source column |
|---|---|---|
| Drug | text | prescriptions.drug |
| Details | size + unit, then dosage display, for example "1" | prescriptions.size, unit (drug_units list), dosage |
| Qty | number | prescriptions.quantity |
| Refills | number | prescriptions.refills |
| Filled | date and time, although the value is the date the prescription was **added** | prescriptions.date_added |
| Card title | "Prescriptions" with an Edit button | constant |

**Sort order:** `active DESC, date_modified DESC, date_added DESC` (Prescription.class.php:1208-1214).
**Filtered out:** rows with `active <= 0` (discontinued = -1) are skipped inside the table loop (general_fragment.html:28).
**Empty state text:** "None", but only when the patient has **no prescriptions at all**. A patient whose prescriptions are all discontinued gets a table with headers and no rows (general_fragment.html:12-13 vs 28).
**Visibility rules:** hidden when `disable_prescriptions` is on, `aclCheckCore('patients','rx')` fails, or `card_prescriptions` is in `hide_dashboard_cards` (demographics.php:1098). The body starts collapsed when the user last collapsed it.

Observed on the old dashboard (2026-09-26, body text read from the DOM):
- TP-TYPICAL: header row `Drug Details Qty Refills Filled`, then `Amlodipine 5 mg | 1 | 30 | 0 | 2026-09-26 11:58:36` and `Omeprazole 20 mg | 1 | 30 | 0 | 2026-09-26 11:58:32`
- TP-EMPTY: `None`
- TP-HISTORY: header row only, with no rows and no message (its Lisinopril is discontinued)

## 3. Controls

| Control | What it does | New dashboard |
|---|---|---|
| Edit (card header) | opens the prescription list (`controller.php?prescription&list&id=<pid>`) in a dialog; `auth` is `aclCheckCore('patients','rx','',['write','addonly'])` (demographics.php:1219) | leaves it out (read-only) |
| Add (inside the prescription list) | broken: links to `controller.php??prescription&edit…` and fails with HTTP 400 (BM-021) | not ported |
| Save (prescription form) | fails silently outside the main tab frame, because it calls `top.restoreSession()` first (BM-022) | not ported |
| Collapse / expand | toggles the card and saves `prescriptions_ps_expand` | local collapse state; starts expanded |

## 4. Permission checks

| Check | Line | Scope in the new app |
|---|---|---|
| disable_prescriptions off, aclCheckCore patients rx, card_prescriptions not hidden (card visible) | demographics.php:1098 | `patient/MedicationRequest.rs` or `user/MedicationRequest.rs` |
| aclCheckCore patients rx write or addonly (Edit button) | demographics.php:1219 | not needed: no edit control |
| checkControllerAcl for the prescription controller | library/classes/Controller.class.php (dispatch) | not applicable to the API |

## 5. Data

**Reads:** `prescriptions` (drug, size, unit, dosage, quantity, refills, date_added, date_modified, active) through `Prescription::prescriptions_factory`, and `list_options` for unit labels.
**Writes:** None

| Field shown | FHIR resource.field | Status | Checked against | Notes |
|---|---|---|---|---|
| Drug | MedicationRequest.medicationCodeableConcept.text | matches | TP-TYPICAL: "Omeprazole 20 mg" | |
| Details (size, unit, dose) | MedicationRequest.dosageInstruction | not available | TP-TYPICAL: old "1"; FHIR dosageInstruction has only timing "No specific dosing interval specified" | the dose "1" is not in the resource for these prescriptions (BM-038) |
| Qty | MedicationRequest.dispenseRequest.quantity.value | matches | TP-TYPICAL: 30 and 30 | |
| Refills | MedicationRequest.dispenseRequest.numberOfRepeatsAllowed | matches | TP-TYPICAL: 0 and 0 | |
| Filled (date added) | MedicationRequest.authoredOn | matches | TP-TYPICAL: Amlodipine 2026-09-26T11:58:36+00:00, old "2026-09-26 11:58:36" | the column label is misleading (BM-023); FHIR is UTC and the old card shows local time |
| Card membership | none reliable | not available | see medications.md | depends on the BM-019 decision |
| Active filter | MedicationRequest.status = active | matches | TP-HISTORY: Lisinopril (active=-1) is status=stopped; old card shows no row | |
| Sort order | MedicationRequest.authoredOn | differs | TP-TYPICAL: old Amlodipine then Omeprazole (date_modified desc); authoredOn descending gives the same here | sort by authoredOn descending as an approximation of date_modified desc (BM-036) |
| Empty state wording | Bundle with no active prescriptions | differs | TP-HISTORY: old shows an empty table, FHIR has 1 stopped entry; TP-EMPTY: old "None", FHIR 0 | the new card shows "No active prescriptions" in both cases (BM-024) |
| List completeness | Bundle.entry filtered to prescriptions (see BM-019) | matches | TP-TYPICAL: old 2 rows, FHIR 2 order/outpatient prescriptions (Amlodipine, Omeprazole), plus Atorvastatin, a list row that now looks the same; TP-HISTORY: old 0, FHIR 0 active | exact only if the BM-019 decision separates list rows correctly |

## 6. Globals

| Global | Read or write | Effect on the card |
|---|---|---|
| disable_prescriptions | read (demographics.php:1098) | hides the card when on |
| erx_enable | read (demographics.php:1186, 1222) | retitles to "Prescription History" and switches Edit to Add (eRx compose) |
| $display_current_medications_below | read (demographics.php:1186), never set in this request | makes the eRx current-medications block dead code (BM-025) |
| hide_dashboard_cards (card_prescriptions) | read (demographics.php:1098) | hides the card |
| user setting prescriptions_ps_expand | read and write | initial collapsed state |
| working directory | changed with chdir("../../../") (demographics.php:1234) | needed for the legacy Controller; restored later on the page |

## 7. Problems

- BM-021: the prescription list's Add link builds `controller.php??prescription&edit…` (double `?`) and fails with HTTP 400 (`library/classes/Controller.class.php:78`, `templates/prescription/general_list.html.twig:449`).
- BM-022: the prescription form's Save calls `top.restoreSession()` first, so it fails silently outside the main tab frame, and the Drug field can't be filled when the drug list is empty (`templates/prescription/general_edit.html.twig:468-471, 188`).
- BM-023: the column labelled "Filled" shows the date the prescription was added (`general_fragment.html:23, 35`).
- BM-024: a patient whose prescriptions are all discontinued gets an empty table with no message (`general_fragment.html:12-13, 28`).
- BM-025: the eRx "Current Medications" block in demographics.php can never render, because `$display_current_medications_below` is only set in stats.php (`demographics.php:1186-1210`).
- BM-038: the Details column (size, unit, dose) isn't in FHIR for these prescriptions.
- BM-019 and BM-020 (shared): list rows and prescriptions can't be separated in FHIR.

## 8. Update after the Opus parity review 4 (2026-09-26)

The card shows every FHIR MedicationRequest that is not a Standard REST API medication-list entry, whatever its intent (the prescription form and CCDA import can store Plan or Original Order), so it matches the old card's `active > 0` rule exactly; parity now compares the rows with nothing extra allowed.
