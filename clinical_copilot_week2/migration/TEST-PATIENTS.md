# Test patients

Synthetic patients on the `development-easy` dev stack, used by the audits and later by the parity tests. None of them is a real person. `fixtures/seed.mjs` created them on 2026-09-26 through the Standard REST API, using the `seed` client with the password grant. The ids come from `fixtures/fixture-ids.json`, and each FHIR id was checked by reading `Patient/<id>`: in OpenEMR the FHIR Patient id is the patient uuid.

Refer to a patient by its key (for example `TP-TYPICAL`), not by name or pid.

## TP-TYPICAL

- **Tessa Typical**, female, born 1958-03-14. pid 36, FHIR id `a2d68325-7821-4a53-aa27-816ce437150f`.
- **Seeded:**
  - allergies: Penicillin and Peanuts, both active
  - problems: Type 2 diabetes mellitus, Essential hypertension, Hyperlipidaemia, all active
  - list medications: Metformin 500 mg, Lisinopril 10 mg, Atorvastatin 20 mg, all active
- **Manual:**
  - Penicillin reaction and severity
  - two prescriptions, one of them linked to the medication list
  - a care team of one practitioner and one related person
- **Exercises:** the main parity path for every card; the linked list-medication case (Review Focus 3); mixed care-team participants.

## TP-EMPTY

- **Evan Empty**, male, born 1990-07-01. pid 37, FHIR id `a2d68328-f292-4e4e-bea5-f589f5f04f56`.
- **Seeded:** nothing on any card.
- **Exercises:** the exact empty-state wording of every card, and telling an empty card apart from a failed load (Review Focus 4).

## TP-NKA

- **Nora NoKnownAllergies**, female, born 1975-11-23. pid 38, FHIR id `a2d68329-9e88-4db3-8e61-3e9ed9aab17d`.
- **Seeded:** no allergies, plus a `lists_touch` row (pid 38, type allergy). The old UI writes that row whenever an allergy is saved (`add_edit_issue.php:279`), and the card shows "No Known Allergies" when the row exists and no active allergy remains. The row was inserted directly because the UI has no explicit "no known allergies" action.
- **Observed:** the old card shows `No Known Allergies`; FHIR returns 0 entries, the same as TP-EMPTY (BM-012).
- **Exercises:** "no known allergies" versus "nothing recorded".

## TP-HISTORY

- **Hugo History**, male, born 1949-02-02. pid 39, FHIR id `a2d6832a-671e-4a74-a35e-9424da762ae7`.
- **Seeded:**
  - allergy: Sulfa drugs, ended (enddate set, `outcome` not set)
  - problem: Community-acquired pneumonia, ended
  - list medication: Amoxicillin 500 mg, ended
- **Manual:** one prescription, then discontinued.
- **Exercises:** what each card filters out, and how status is derived (Review Focus 2).

## TP-DECEASED

- **Dora Deceased**, female, born 1932-05-09. pid 40, FHIR id `a2d6832c-0d95-48a1-9f24-3a3e67443ef4`.
- **Set through the Standard API** (`PUT /api/patient/:puuid`): `deceased_date` 2025-11-02 and `deceased_reason` "Synthetic test fixture".
- **Observed:** FHIR returns `deceasedDateTime` 2025-11-02 **and** `active: true`, confirming Review Focus 2.
- **Exercises:** the header's status field.

## TP-LONG

- **Lena LongLists**, female, born 1944-09-30. pid 41, FHIR id `a2d6832c-b8b1-48cd-ab3b-127acc55cc85`.
- **Seeded:** 25 allergies, 60 problems and 60 list medications, all active, titled "Long-list … 01" and onwards.
- **Exercises:** list completeness and any truncation in the old or new cards (Review Focus 3).

## TP-ESCAPING

- **Zoë O'Brien-Núñez**, female, born 1988-12-12. pid 42, FHIR id `a2d6835b-6830-4007-8ae9-3f8eb7e8f40a`.
- **Seeded:** one allergy titled `Latex <b>x</b>`.
- **Exercises:** the apostrophe, accents and HTML-like text must be shown as text, never rendered as markup.

## Manual steps

The Standard API can't create these records. It ignores `reaction` and `severity_al` on an allergy update, and has no prescription or care-team write endpoints. So they're done in the OpenEMR UI at https://localhost:9300.

- [x] TP-TYPICAL: Penicillin reaction set to "Hives" and severity to "Moderate" (by the user in the UI; stored as `lists.reaction = hives`, `severity_al = moderate`)
- [x] TP-TYPICAL: Amlodipine 5 mg added to the **medication list** by the user (`lists` id 1393). It's a list entry, not a prescription.
- [x] TP-TYPICAL: active prescription Amlodipine 5 mg with "Add to Medication List" = Yes (`prescriptions` id 2481, `medication = 1`)
- [x] TP-TYPICAL: active prescription Omeprazole 20 mg with "Add to Medication List" = No (`prescriptions` id 2480, `medication = 0`)
- [x] TP-TYPICAL: care team "practitioner", status active, two members: user 5 as `nurse_practitioner`, and contact 2477, a related person (by the user in the UI)
- [x] TP-HISTORY: prescription Lisinopril 5 mg (`prescriptions` id 2482), then discontinued (`active = -1`, `end_date` left NULL)
- [x] TP-DECEASED: deceased date set through the Standard API (see above)
- [x] TP-TYPICAL: problem occurrence set as the UI's Occurrence dropdown stores it: Hyperlipidaemia = 1 (First), Essential hypertension = 4 (Chronic/Recurrent) (Task 10, for BM-017)
- [x] TP-TYPICAL: Atorvastatin list row given a `lists_medication` row (request_intent order, usage_category outpatient, drug_dosage_instructions "1 tablet at night"), the values the medication form stores (Task 11, for BM-019)
- [x] Encounters (Task 15, `fixtures/seed-encounters.mjs`, ids in `fixtures/encounter-ids.json`): TP-TYPICAL 3 (2026-08-14 Diabetes review, Donna Lee; 2026-03-02 Blood pressure check, Fred Stone; 2025-11-20 Annual physical, Donna Lee), TP-HISTORY 1 (2024-10-26 Cough and fever), TP-LONG 30 monthly visits. Dev user Donna Lee (users.id 6) was given the test NPI 1234567893 so that one provider resolves through FHIR Practitioner.
- [x] Parity-gap data (2026-09-26, `fixtures/seed-parity-gaps.php`, run as apache in the openemr container; safe to re-run): TP-TYPICAL Type 2 diabetes (lists 1238) linked to visits 2488 and 2490 through OpenEMR's own `PatientIssuesService::linkIssueToEncounter`; Omeprazole (prescriptions 2480) end date 2027-03-31 and 2 refills; Fred Stone's care-team membership at Great Clinic (facility 3); TP-LONG "Long-list allergen 01" (lists 1247) severity severe. Each exposes a gap found by the Codex parity review (BM-041, BM-043, BM-044, BM-045, and the missing allergy highlight). Added after the Fable review: Lisinopril (lists 1242) ends 2027-06-30; a second TP-HISTORY visit on 2024-10-26 ("Same-day follow-up", through `EncounterService::insertEncounter`); TP-LONG "Long-list allergen 02" (lists 1248) marked resolved with no end date and "Long-list allergen 03" (lists 1249) ending 2027-12-31; and the non-admin dev user `tp-physician` (password `tp-physician-pass`, Physicians group, dev stack only). Added after Fable review 2: TP-HISTORY "Fee sheet problem" (inserted as the Fee Sheet does, with no activity) and TP-LONG's middle name Quinn.
- [x] TP-NKA: `INSERT INTO lists_touch (pid, type, date) VALUES (38, 'allergy', NOW())`, the same row `setListTouch()` writes (Task 9)

**How the prescriptions were added.** The prescription form could not be used by hand on the dev stack, for three reasons, all of which are Prescriptions audit findings:
1. The list's "Add" link builds `controller.php??prescription…`, with a double `?`, and fails with HTTP 400 (`library/classes/Controller.class.php:78` plus `templates/prescription/general_list.html.twig:449`).
2. Save calls `top.restoreSession()`, which exists only inside the main tab frame (`general_edit.html.twig:468-471`).
3. The Drug field is a select2 search over the drug list, and the dev database's list is empty, so no drug can be chosen.

The prescriptions were therefore saved through that same form in the dev stack's Selenium browser (`tmp/seed_rx.php`, gitignored), which added the drug as an option and defined `restoreSession` before calling the form's own `submitfun()`. Everything else is OpenEMR's normal save path.
