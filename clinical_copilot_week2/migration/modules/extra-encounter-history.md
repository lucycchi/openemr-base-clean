# Module: Encounter history (extra section)

**Source files:** `interface/patient_file/history/encounters.php:1-984` (Visit History page: access, paging, query, rows); `interface/main/tabs/templates/patient_data_template.php:121-150` (Visit History button and encounter selector); FHIR `src/Services/FHIR/FhirEncounterService.php:150-230`, `src/Services/EncounterService.php:180-260`; seed script `clinical_copilot_week2/migration/fixtures/seed-encounters.mjs`
**Test patients used:** TP-TYPICAL, TP-EMPTY, TP-HISTORY, TP-LONG

## 1. Purpose and lifecycle

Encounter history is not a card on the old dashboard. It's the **Visit History** page, reached from the identity bar's history button (`clickEncounterList`) or the patient menu. So the new section is a new card, and its parity reference is this page.

`encounters.php` reads the patient from the session (or switches it when `?pid=` is given, lines 52-53) and checks the encounter and patient ACLs (67-77). It picks the clinical or billing view (`default_encounter_view`, `?billing=`, or the user's authorisation, 84-89) and a page size (`encounter_page_size`, or `?pagesize=` from a 5/10/15/20/25/50/ALL picker, 296-330).

It then queries `form_encounter` joined to the `newpatient` form and the provider in `users`, ordered by date descending, with LIMIT for paging (410-470). Each row prints date, linked issues, reason plus that encounter's forms, provider, and a coding summary and insurance. In clinical view, the patient's documents are **interleaved** between encounters by date (`showDocument`, defined at 138 and called between rows at 515 and after the last row at 869). The page is server-rendered; clicking a row opens the encounter.

## 2. What the user sees

| Field shown | Format | Source column |
|---|---|---|
| Date | YYYY-MM-DD, with a "View encounter" tooltip | form_encounter.date |
| Issue | linked issue titles, "type: title", when the user has patients/med | issue_encounter joined to lists |
| Reason/Form | reason text, then the encounter's forms (collapsible); "(No access)" when the sensitivity or visit-category ACL fails | form_encounter.reason, forms |
| Provider | "Last, First" | users via form_encounter.provider_id |
| Billing / Coding | billed codes (clinical view) or Code/Chg/Paid/Adj/Bal (billing view) | billing, ar_activity |
| Insurance | "Primary: …" | insurance_data |
| Paging summary | "1-3 of 3", "1-20 of 30 Next⇒", "1-0 of 0" | count query |
| Documents | interleaved document rows, clinical view only | documents |

**Sort order:** `form_encounter.date` descending, then id descending (encounters.php:449).
**Filtered out:** deleted encounter forms (`f.deleted = 0`). Encounters the user can't see still appear, with the reason replaced by "(No access)".
**Empty state text:** "1-0 of 0" above an empty table header (encounters.php:470).
**Visibility rules:** the page shows "(Encounters not authorized)" without `encounters notes` or `notes_a` (291). A patient in a squad the user isn't in is denied (77). Per row, the reason is hidden by the `sensitivities` ACL and the visit-category ACO.

Observed on the old page (2026-09-26, `encounter_page_size` = 20, clinical view):
- TP-TYPICAL: `1-3 of 3`; `2026-08-14 Diabetes review Lee, Donna`, `2026-03-02 Blood pressure check Stone, Fred`, `2025-11-20 Annual physical Lee, Donna`, each with "Primary:" in Insurance
- TP-EMPTY: `1-0 of 0` and the header row
- TP-HISTORY: `1-1 of 1`; `2024-10-26 Cough and fever`
- TP-LONG: `1-20 of 30 Next⇒`, starting `2026-09-01 Long-list visit 01`

## 3. Controls

| Control | What it does | New dashboard |
|---|---|---|
| Row click / date tooltip | opens the encounter | leaves it out, or links out to OpenEMR (Gate 3 hosting question) |
| To Billing View / To Clinical View | switches the view | leaves it out: the clinical view only |
| Results per page picker, Prev / Next | changes page size and pages | the new card shows the 20 most recent with a "Show all" toggle (BM-034) |
| Print page | prints the list | leaves it out |
| Billing note edit (billing view) | inline edit | not ported |

## 4. Permission checks

| Check | Line | Scope in the new app |
|---|---|---|
| aclCheckCore encounters notes or notes_a (page) | encounters.php:67-68, 291 | `patient/Encounter.rs` or `user/Encounter.rs` |
| aclCheckCore encounters coding, coding_a (billing column) | encounters.php:69-70 | not needed: no billing column |
| aclCheckCore patients med (issue column) | encounters.php:72 | not needed: no issue column |
| aclCheckCore sensitivities (per row reason) | encounters.php:507 | no FHIR equivalent found; see BM-033 |
| visit-category ACO (per row reason) | encounters.php:491-496 | no FHIR equivalent |
| squad check | encounters.php:77 | see BM-008 |

## 5. Data

**Reads:** `form_encounter`, `forms` (newpatient and the per-encounter forms), `users`, `issue_encounter` and `lists`, `documents` and `categories`, `billing` and `ar_activity`, `insurance_data`, `openemr_postcalendar_categories`.
**Writes:** `form_encounter.billing_note` (billing view inline edit only).

| Field shown | FHIR resource.field | Status | Checked against | Notes |
|---|---|---|---|---|
| Date | Encounter.period.start | matches | TP-TYPICAL: 2026-08-14T00:00:00+00:00 and old "2026-08-14" | UTC in FHIR; the new app formats it as a site-local date |
| Reason | Encounter.reasonCode[].text | matches | TP-TYPICAL: "Diabetes review", "Blood pressure check", "Annual physical" | |
| Provider (with NPI) | Encounter.participant[].individual → Practitioner.name | matches | TP-TYPICAL: Practitioner/a2c6137a-eabd-… resolves to Lee, Donna (NPI 1234567893) | one extra read per distinct provider |
| Provider (without NPI) | Encounter.participant | not available | TP-TYPICAL 2026-03-02: old "Stone, Fred"; FHIR encounter has no participant | a provider without an NPI is dropped (BM-032) |
| Visit type | Encounter.type | differs | TP-TYPICAL: Office Visit, Established Patient and Preventive Care all come back as "Encounter for check up (procedure)" | the old page doesn't show a type column, so this isn't a parity issue |
| Issue | none | not available | TP-TYPICAL: no linked issues; FHIR Encounter has no issue link | not ported |
| Forms per encounter | none | not available | TP-TYPICAL: newpatient only | not ported |
| Billing / Coding / Insurance | none on Encounter | not available | TP-TYPICAL: "Primary:" on the old page | not ported |
| Interleaved documents | none | not available | TP-TYPICAL: no documents | not ported; documented difference |
| Restricted reason "(No access)" | none | differs | not runtime-checked (admin has every ACL); code-read only | the FHIR search path has no sensitivity filter (BM-033) |
| Sort order | Encounter.period.start | differs | TP-LONG: FHIR ascending (starts 2024-04-14 "visit 30"), old descending (starts 2026-09-01 "visit 01") | the new app sorts descending (BM-034) |
| Empty state wording | Bundle with 0 entries | differs | TP-EMPTY: FHIR 0; old "1-0 of 0" | the new card shows "No encounters recorded" (BM-035) |
| List completeness | Bundle.entry (all 30 in one response, self link only) | matches | TP-TYPICAL: old 3, FHIR 3; TP-HISTORY: old 1, FHIR 1; TP-LONG: old 30 across 2 pages, FHIR 30 in one Bundle | the old page's paging is display-only |

## 6. Globals

| Global | Read or write | Effect on the card |
|---|---|---|
| encounter_page_size | read (encounters.php:300) | rows per page (20 on this stack; 0 means all) |
| default_encounter_view | read (encounters.php:64) | clinical (0) or billing (1) view |
| enable_group_therapy | read (25, 381) | adds the group-therapy columns and query path |
| enable_follow_up_encounters | read | adds follow-up columns |
| ippf_specific, weight_loss_clinic, phone_country_code | read | column labels and insurance column |
| session pid, therapy_group | read and write (`?pid=` switches the session patient, 52-53) | which patient or group is listed |
| $_GET billing, issue, pagesize, pagestart | read | view, issue filter, paging |

## 7. Problems

- BM-032: a provider without an NPI is dropped from Encounter.participant, so the new card can't name that provider.
- BM-033: the old page hides the reason of sensitivity-restricted encounters, but the FHIR search has no sensitivity filter (code-read, not runtime-verified).
- BM-034: FHIR returns encounters oldest first in one Bundle; the old page lists newest first, 20 per page.
- BM-035: the empty state reads "1-0 of 0" (encounters.php:470).
- BM-028 and BM-008 (shared): participant names need extra reads, and the squad restriction has no FHIR equivalent.
