# Module: Allergies

**Source files:** `interface/patient_file/summary/demographics.php:1095, 1104-1136` (flag, active filter, card set-up); `templates/patient/card/allergies.html.twig:1-52`; `templates/patient/card/card_base.html.twig`; `src/Services/AllergyIntoleranceService.php` (getAll); `library/lists.inc.php:130-150` (list touch); `interface/patient_file/summary/add_edit_issue.php:279`; FHIR `src/Services/FHIR/FhirAllergyIntoleranceService.php:110-240`, `src/Services/FHIR/UtilsService.php:374-383`
**Test patients used:** TP-TYPICAL, TP-EMPTY, TP-NKA, TP-HISTORY, TP-LONG, TP-ESCAPING

## 1. Purpose and lifecycle

The card is server-rendered on page load. `demographics.php` sets `$allergy` from `aclCheckIssue('allergy')` and the `card_allergies` hidden-card key (1095). If `$allergy` is set, it loads every allergy for the patient through `AllergyIntoleranceService::getAll(['lists.pid' => $pid])` and keeps only the rows that pass `filterActiveIssues()` (1111-1113). It passes the list, plus a `listTouched` flag from `getListTouch($pid, 'allergy')`, to `allergies.html.twig`. Collapse state comes from the user setting `allergy_ps_expand`. Nothing is loaded later. The card's Edit button runs `load_location(stats_full.php?active=all&category=allergy)`, which opens the full issues list in the same frame.

## 2. What the user sees

| Field shown | Format | Source column |
|---|---|---|
| Allergen | plain text, escaped (`l.title\|text`) | lists.title |
| Severity | in brackets after the allergen, from the `severity_ccda` list; "()" when unset | lists.severity_al |
| Severity highlight | `bg-warning font-weight-bold` on the severity for severe, life_threatening_severity or fatal | lists.severity_al |
| Tooltip | "<title> Reaction: <reaction> - <severity>" | lists.title, lists.reaction (reaction list title), lists.severity_al |
| Card title | "Allergies" with an Edit button | constant |

**Sort order:** no explicit ORDER BY in `AllergyIntoleranceService::getAll`, so database order (insertion order in practice). The template's header comment says severe allergies are shown first, but no code does this (BM-014).
**Filtered out:** rows with `outcome = 1` (resolved), and rows whose `enddate` is set and in the past (`filterActiveIssues`, demographics.php:1111-1113).
**Empty state text:** "No Known Allergies" when the patient's allergy list has ever been saved (`lists_touch` row) and no active allergy remains; otherwise "Nothing Recorded" (allergies.html.twig:27-36).
**Visibility rules:** hidden when `aclCheckIssue('allergy')` fails or `card_allergies` is in the `hide_dashboard_cards` global (demographics.php:1095).

Observed on the old dashboard (`tmp/dash_text.php`, 2026-09-26):
- TP-TYPICAL: `Penicillin (Moderate)`, `Peanuts ()`; tooltips `Penicillin Reaction: Hives - Moderate`, `Peanuts Reaction:  -`
- TP-EMPTY: `Nothing Recorded`
- TP-NKA: `No Known Allergies`
- TP-HISTORY: `Nothing Recorded` (its only allergy has ended)
- TP-LONG: 25 rows, all shown (no truncation; the card scrolls)
- TP-ESCAPING: `Latex <b>x</b> ()`, shown literally as text

## 3. Controls

| Control | What it does | New dashboard |
|---|---|---|
| Edit (card header) | opens `stats_full.php?active=all&category=allergy` in the frame; shown to everyone because `auth` is hard-coded `true` (BM-013) | an "Edit in OpenEMR" link in the card header opens the patient's chart in OpenEMR (ARC-06), because the write API cannot record this card's clinical fields (BM-062) |
| Collapse / expand | toggles the card and saves the user setting `allergy_ps_expand` | the new app keeps its own collapse state; it doesn't write OpenEMR user settings |
| Hover tooltip | shows the reaction and severity | the new app shows the reaction inline or in a tooltip, and a parity test checks the text |

## 4. Permission checks

| Check | Line | Scope in the new app |
|---|---|---|
| aclCheckIssue allergy (card visible) | demographics.php:1095 | `patient/AllergyIntolerance.rs` (Option A) or `user/AllergyIntolerance.rs` (Option B); the server applies its own ACL |
| Edit button `auth` hard-coded true, so no write check | demographics.php:1129 | not needed: the link relies on OpenEMR's own ACL once the chart opens |

## 5. Data

**Reads:** `lists` (type allergy: title, severity_al, reaction, outcome, enddate, begdate, diagnosis) joined to `list_options` for the reaction title; `lists_touch` (pid, type = allergy); `list_options` `severity_ccda` for the severity label.
**Writes:** None

| Field shown | FHIR resource.field | Status | Checked against | Notes |
|---|---|---|---|---|
| Allergen (uncoded) | AllergyIntolerance.text.div (narrative); code is data-absent "unknown" | differs | TP-TYPICAL: code=unknown, text.div "Penicillin" | the name is only in the narrative when there is no diagnosis code (BM-010) |
| Allergen (coded) | AllergyIntolerance.code.coding[].display | matches | TP-TYPICAL: no coded allergies seeded; code path read at FhirAllergyIntoleranceService.php:198-215 | display falls back to lists.title when the code has no description |
| Allergen with markup characters | AllergyIntolerance.text.div | differs | TP-ESCAPING: text.div is `<div xmlns=…>Latex <b>x</b></div>`, unescaped | a text-only parse gives "Latex x"; the old card shows "Latex <b>x</b>" (BM-009) |
| Severity label | AllergyIntolerance.criticality | not available | TP-TYPICAL: old "Moderate", FHIR criticality=low, reaction.severity absent | eight OpenEMR severities collapse to low, high or unable-to-assess (BM-011) |
| Severity highlight | AllergyIntolerance.criticality = high | differs | TP-TYPICAL: moderate maps to low (no highlight, same as old) | old highlights severe, life_threatening, fatal; high also covers moderate_to_severe (BM-011) |
| Reaction (tooltip) | AllergyIntolerance.reaction[].manifestation[].coding[].display | matches | TP-TYPICAL: Hives; Peanuts has no reaction element | the old tooltip shows an empty reaction as "Reaction:  -" |
| Active filter | AllergyIntolerance.clinicalStatus = active | differs | TP-HISTORY: ended Sulfa is clinicalStatus=inactive, old card hides it | FHIR marks any row with an end date inactive; the old card keeps a future end date visible (BM-016) |
| Empty state wording | none | not available | TP-NKA: FHIR 0 entries; TP-EMPTY: FHIR 0 entries | FHIR cannot tell "No Known Allergies" from "Nothing Recorded" (BM-012) |
| List completeness | Bundle.entry (all in one response, self link only) | matches | TP-TYPICAL: old 2, FHIR 2; TP-HISTORY: old 0, FHIR 1 inactive (0 after filter); TP-LONG: old 25, FHIR 25 | no paging needed; the new app filters to clinicalStatus active |

## 6. Globals

| Global | Read or write | Effect on the card |
|---|---|---|
| hide_dashboard_cards (card_allergies) | read (demographics.php:138, 1095) | hides the card |
| user setting allergy_ps_expand | read and write (getUserSetting, user_settings.php) | initial collapsed state |
| $pid | read | whose allergies are loaded |
| enable_allergy_check | read elsewhere on the page (demographics.php) | not used by this card |

## 7. Problems

- BM-009: FHIR narrative is built by string concatenation without escaping, so an allergy title is returned as live markup (`UtilsService.php:374-376`).
- BM-010: an uncoded allergy's name is only in the narrative; `code` is "unknown" (`FhirAllergyIntoleranceService.php:198-221`).
- BM-011: severity collapses into criticality, so "Moderate" can't be shown from FHIR, and the highlight rule differs (`FhirAllergyIntoleranceService.php:138-151`).
- BM-012: "No Known Allergies" is inferred from `lists_touch`, not recorded by a clinician (`allergies.html.twig:27-36`, `lists.inc.php:130-150`).
- BM-013: the Edit button's `auth` is hard-coded `true` (`demographics.php:1129`).
- BM-014: the template comment promises severe allergies first, but nothing sorts them (`allergies.html.twig:7-8`).
- BM-015: an allergy without a severity renders as "Title ()" (`allergies.html.twig:45`).
- BM-016: an allergy with a future end date shows on the old card, but FHIR marks it inactive (`demographics.php:1111-1113` vs `FhirAllergyIntoleranceService.php:117-121`).

## 8. Update after the Fable parity review (2026-09-26)

FHIR's clinicalStatus is wrong both ways: an allergy marked Resolved with no end date is `active` (BM-047), and an allergy with a future end date is `inactive` (BM-016). By user decision the card reads each allergy's `enddate` and `outcome` from the Standard REST API (`GET /api/patient/:puuid/allergy`, through the BFF route `/api/list-dates?list=allergy`) and applies the old `filterActiveIssues` rule. The allergy row uuid equals the FHIR AllergyIntolerance id. That endpoint answers a bad patient id with HTTP 200, validation errors and an empty list, which the BFF treats as a failure.
