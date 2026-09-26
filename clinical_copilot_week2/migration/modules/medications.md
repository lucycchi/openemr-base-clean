# Module: Medications

**Source files:** `interface/patient_file/summary/demographics.php:1097, 1111-1113, 1162-1182`; `templates/patient/card/medication.html.twig:1-25`; `src/Services/PatientIssuesService.php:171-215`; FHIR `src/Services/FHIR/FhirMedicationRequestService.php:120-520`, `src/Services/PrescriptionService.php:80-300, 355-370` (the UNION of prescriptions and lists)
**Test patients used:** TP-TYPICAL, TP-EMPTY, TP-HISTORY, TP-LONG

## 1. Purpose and lifecycle

The card is server-rendered on page load. `$meds` comes from `aclCheckIssue('medication')` and the `card_medication` hidden-card key (demographics.php:1097). When it's set, `PatientIssuesService::search(['lists.pid' => $pid, 'lists.type' => 'medication'])` loads the patient's **medication-list entries** (the `lists` table only, never `prescriptions`), ordered by begdate. `filterActiveIssues()` removes resolved and ended rows, and the result goes to `medication.html.twig` with a `listTouched` flag. The card respects the user's saved collapse state (`medication_ps_expand`); on this dev stack it starts collapsed. Nothing is loaded later. Edit opens `stats_full.php?active=all&category=medication`.

## 2. What the user sees

| Field shown | Format | Source column |
|---|---|---|
| Medication | title in normal weight | lists.title |
| Dosage instructions | plain text after the title, when present | lists_medication.drug_dosage_instructions |
| Card title | "Medications" with an Edit button | constant |

**Sort order:** `lists.begdate` ascending, with NULL first (PatientIssuesService.php:211). TP-TYPICAL's Amlodipine has no begdate, so it is listed first.
**Filtered out:** `outcome = 1`, or `enddate` in the past (`filterActiveIssues`). Prescriptions are never shown on this card.
**Empty state text:** "None" when the medication list has ever been saved (`lists_touch`), otherwise "Nothing Recorded".
**Visibility rules:** hidden when `aclCheckIssue('medication')` fails or `card_medication` is in `hide_dashboard_cards` (demographics.php:1097). The body starts collapsed when the user last collapsed it.

Observed on the old dashboard (2026-09-26, card collapsed, items read from the DOM):
- TP-TYPICAL: `Amlodipine 5 mg 1 in`, `Metformin 500 mg`, `Lisinopril 10 mg`, `Atorvastatin 20 mg 1 tablet at night` (4 list entries). Amlodipine is also a prescription (id 2481), linked to list row 1393 through `lists_medication.prescription_id`.
- TP-EMPTY: `Nothing Recorded`
- TP-HISTORY: `Nothing Recorded` (Amoxicillin has ended)
- TP-LONG: 60 rows, starting at `Long-list medication 60`

## 3. Controls

| Control | What it does | New dashboard |
|---|---|---|
| Edit | opens `stats_full.php?active=all&category=medication`; shown to everyone because `auth` is hard-coded true (BM-013) | leaves it out (read-only) |
| Collapse / expand | toggles the card and saves the user setting `medication_ps_expand` | local collapse state; starts expanded |

## 4. Permission checks

| Check | Line | Scope in the new app |
|---|---|---|
| aclCheckIssue medication (card visible) | demographics.php:1097 | `patient/MedicationRequest.rs` or `user/MedicationRequest.rs` (or `user/medication.rs` if the Standard API is chosen at Gate 2) |
| Edit `auth` hard-coded true, so no write check | demographics.php:1175 | not needed: no edit control |

## 5. Data

**Reads:** `lists` (type medication: title, begdate, enddate, outcome), `lists_medication` (drug_dosage_instructions, request_intent, usage_category, prescription_id) and `lists_touch`.
**Writes:** None

| Field shown | FHIR resource.field | Status | Checked against | Notes |
|---|---|---|---|---|
| Medication | MedicationRequest.medicationCodeableConcept.text | matches | TP-TYPICAL: "Metformin 500 mg" | coded drugs also carry coding |
| Dosage instructions | MedicationRequest.dosageInstruction[].text | matches | TP-TYPICAL: Atorvastatin text "1 tablet at night", same as the old card | only for list rows not linked to a prescription (see linked row) |
| Linked list row | the prescription's MedicationRequest; the list row is excluded | differs | TP-TYPICAL: old card shows `Amlodipine 5 mg 1 in` (list row 1393); FHIR has one Amlodipine, from prescription 2481, with no dosage text | the UNION drops list rows linked to a prescription (BM-020) |
| Card membership (list versus prescription) | none reliable: intent, category, dosageInstruction | not available | TP-TYPICAL: Atorvastatin (list) and Omeprazole (prescription) both have intent=order and category=outpatient | see the note below (BM-019) |
| Active filter | MedicationRequest.status | matches | TP-HISTORY: Amoxicillin (list, ended) is completed and Lisinopril (prescription, discontinued) is stopped; old card shows nothing | the new app keeps status=active |
| Sort order | none: MedicationRequest has no begdate; authoredOn is the record time (PrescriptionService.php:218, FhirMedicationRequestService.php:440-443) | not available | TP-LONG: old starts at medication 60; FHIR authoredOn ascending starts at 01, with same-second ties | approved exception: keep FHIR order and compare the set of entries, not their order (BM-036) |
| Empty state wording | none | not available | TP-EMPTY: 0 entries; old "Nothing Recorded" | lists_touch is not in FHIR (BM-012) |
| List completeness | Bundle.entry (all in one response, self link only) | differs | TP-TYPICAL: old card 4, FHIR 5 (3 list + 2 prescriptions, with Amlodipine once); TP-LONG: old 60, FHIR 60; TP-HISTORY: old 0, FHIR 2 inactive | the card's rows cannot be picked out of the FHIR response reliably (BM-019, BM-020) |

**Separating medications from prescriptions:** the SQL knows each row's origin (`source_table` = `prescriptions` or `lists`, PrescriptionService.php:152 and 208), but `FhirMedicationRequestService` doesn't write it into the resource. The candidate fields don't work:
- **intent and category:** a list row takes them from `lists_medication.request_intent` and `usage_category` (PrescriptionService.php:212-215). The medication form lets a user set these to "Order" and "Outpatient", which are the values prescriptions get. Proven on TP-TYPICAL: after Atorvastatin's list row was given `order` and `outpatient`, its MedicationRequest is identical to Omeprazole's (a prescription) in intent, category, status and reportedBoolean.
- **dosageInstruction:** prescriptions carry a placeholder timing ("No specific dosing interval specified") and list rows don't. That's an implementation side effect, not a contract, and a list row gets a dosageInstruction as soon as it has dosage text.

VERDICT: no reliable FHIR field. Options for Gate 2:
1. Read the Medications card from the Standard API `GET /api/patient/:pid/medication` (lists only), and the Prescriptions card from FHIR MedicationRequest minus those ids. This needs `api:oemr user/medication.rs`, and conflicts with "cards pulling live data from the FHIR API".
2. Merge the two cards into one "Medications and prescriptions" card from FHIR, labelling each entry by intent (plan or order) as a hint, and document the change.
3. Keep two cards split on the intent heuristic (plan means list, order means prescription), and document that a list entry marked Order appears under Prescriptions.

## 6. Globals

| Global | Read or write | Effect on the card |
|---|---|---|
| hide_dashboard_cards (card_medication) | read (demographics.php:138, 1097) | hides the card |
| user setting medication_ps_expand | read and write | initial collapsed state (collapsed on this stack) |
| erx_enable | read in stats.php:190 | with eRx on, stats.php checks for list medications not uploaded to eRx; this card is unaffected |
| $pid | read | whose medications are loaded |

## 7. Problems

- BM-019: no FHIR field separates a medication-list entry from a prescription (`PrescriptionService.php:212-215`, `FhirMedicationRequestService.php:498-504`).
- BM-020: the UNION leaves out list rows linked to a prescription, so a linked medication appears on the old card with the list's dosage text but in FHIR only as the prescription (`PrescriptionService.php:260`).
- BM-036: the old begdate order can't be reproduced from FHIR; the card keeps FHIR order (approved exception).
- BM-012 and BM-013 (shared): "None" is inferred from lists_touch, and Edit `auth` is hard-coded true (`demographics.php:1175`).

## 8. Update after the Codex parity review (2026-09-26)

FHIR MedicationRequest does not carry the end date: an active entry with any end date, past or future, is sent as status `completed` (BM-044). By user decision the Medications card now reads each list entry's `enddate` and `outcome` from the Standard REST API (`GET /api/patient/:pid/medication`, after `GET /api/patient/:puuid` for the pid) through the BFF route `/api/medication-end-dates`, and applies the old `filterActiveIssues` rule exactly. The list-row uuid equals the FHIR MedicationRequest id, so the two are matched by id. An empty list comes back as a bodyless HTTP 404 (BM-046).

