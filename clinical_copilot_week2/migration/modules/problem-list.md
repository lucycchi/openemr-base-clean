# Module: Problem List

**Source files:** `interface/patient_file/summary/demographics.php:1096, 1111-1113, 1138-1160`; `templates/patient/card/medical_problems.html.twig:1-24`; `src/Services/PatientIssuesService.php:171-215` (search, ORDER BY begdate); `interface/patient_file/summary/stats.php:140-146` (skips medical_problem, so stats.php does not render this card on the dashboard); FHIR `src/Services/FHIR/Condition/FhirConditionProblemListItemService.php:130-170`, `src/Services/FHIR/Condition/Trait/FhirConditionTrait.php:95-135`
**Test patients used:** TP-TYPICAL, TP-EMPTY, TP-HISTORY, TP-LONG

## 1. Purpose and lifecycle

The card is server-rendered on page load. `$pl` comes from `aclCheckIssue('medical_problem')` and the `card_medicalproblems` hidden-card key (demographics.php:1096). When it's set, `PatientIssuesService::search(['lists.pid' => $pid, 'lists.type' => 'medical_problem'])` loads every problem ordered by `lists.begdate`, and `filterActiveIssues()` drops resolved and ended rows. The result, plus a `listTouched` flag, goes to `medical_problems.html.twig`.

`stats.php` also renders `medical_problems.html.twig`, but it removes `medical_problem` from its issue types first (stats.php:140-146), so on the dashboard that path only draws other issue types and the "Old Medication" card. Nothing is loaded later. Edit opens `stats_full.php?active=all&category=medical_problem`.

## 2. What the user sees

| Field shown | Format | Source column |
|---|---|---|
| Problem title | one line per problem, plain escaped text | lists.title |
| Card title | "Medical Problems" with an Edit button | constant |

No onset date, code, status or tooltip is shown.

**Sort order:** `lists.begdate` ascending, oldest first (PatientIssuesService.php:211). MySQL puts NULL begdates first.
**Filtered out:** `outcome = 1`, or `enddate` set and in the past (`filterActiveIssues`, demographics.php:1111-1113). Occurrence (First, Chronic/Recurrent and so on) does not filter anything.
**Empty state text:** "None" (the `None{{Issues}}` translation key) when the problem list has ever been saved (`lists_touch`), otherwise "Nothing Recorded" (medical_problems.html.twig:6-15).
**Visibility rules:** hidden when `aclCheckIssue('medical_problem')` fails or `card_medicalproblems` is in `hide_dashboard_cards` (demographics.php:1096).

Observed on the old dashboard (2026-09-26):
- TP-TYPICAL: `Type 2 diabetes mellitus`, `Essential hypertension`, `Hyperlipidaemia`. All three are shown, including Hyperlipidaemia (occurrence First) and Essential hypertension (occurrence Chronic/Recurrent).
- TP-EMPTY: `Nothing Recorded`
- TP-HISTORY: `Nothing Recorded` (its pneumonia has ended)
- TP-LONG: 60 rows, starting at `Long-list problem 60`, the oldest begdate

## 3. Controls

| Control | What it does | New dashboard |
|---|---|---|
| Edit | opens `stats_full.php?active=all&category=medical_problem`; shown to everyone because `auth` is hard-coded true (BM-013) | an "Edit in OpenEMR" link in the card header opens the patient's chart in OpenEMR (ARC-06), because the write API cannot record this card's clinical fields (BM-062) |
| Collapse / expand | toggles the card and saves the user setting `medical_problem_ps_expand` | local collapse state only |

## 4. Permission checks

| Check | Line | Scope in the new app |
|---|---|---|
| aclCheckIssue medical_problem (card visible) | demographics.php:1096 | `patient/Condition.rs` or `user/Condition.rs` |
| Edit `auth` hard-coded true, so no write check | demographics.php:1153 | not needed: the link relies on OpenEMR's own ACL once the chart opens |

## 5. Data

**Reads:** `lists` (type medical_problem: title, begdate, enddate, outcome, occurrence) and `lists_touch` (type medical_problem).
**Writes:** None

| Field shown | FHIR resource.field | Status | Checked against | Notes |
|---|---|---|---|---|
| Problem title | Condition.code.text (or code.coding[0].display when coded) | matches | TP-TYPICAL: code.text "Type 2 diabetes mellitus" | the title is in code.text, unlike uncoded allergies |
| Category filter | Condition?category=problem-list-item | matches | TP-TYPICAL: 3 entries with or without the filter, all category problem-list-item | the filter is still required: Condition also serves encounter-diagnosis and health-concern |
| Active filter | Condition.clinicalStatus | differs | TP-TYPICAL: Hyperlipidaemia (occurrence First) is clinicalStatus=resolved and Essential hypertension (Chronic/Recurrent) is recurrence, while the old card shows both | FhirConditionTrait maps occurrence 1 to resolved and occurrence above 1 to recurrence (BM-017) |
| Ended problem hidden | Condition.clinicalStatus=inactive, abatementDateTime | matches | TP-HISTORY: pneumonia clinicalStatus=inactive, abatement 2024-12-15; the old card hides it | |
| Sort order | Condition.onsetDateTime | differs | TP-LONG: FHIR order starts "Long-list problem 01", old card starts "Long-list problem 60" | FHIR returns insertion order; the new app sorts by onset ascending with missing onset first (BM-018) |
| Empty state wording | none | not available | TP-EMPTY: FHIR 0 entries; old "Nothing Recorded" | "None" versus "Nothing Recorded" depends on lists_touch, which FHIR doesn't expose (BM-012) |
| List completeness | Bundle.entry (all in one response, self link only) | matches | TP-TYPICAL: old 3, FHIR 3; TP-HISTORY: old 0, FHIR 1 inactive; TP-LONG: old 60, FHIR 60 | the old card's rows equal the FHIR entries minus inactive ones, and minus resolved ones only when outcome=1, which FHIR cannot tell apart (BM-017) |

## 6. Globals

| Global | Read or write | Effect on the card |
|---|---|---|
| hide_dashboard_cards (card_medicalproblems) | read (demographics.php:138, 1096) | hides the card |
| user setting medical_problem_ps_expand | read and write | initial collapsed state |
| $pid | read | whose problems are loaded |

## 7. Problems

- BM-017: FHIR maps occurrence "First" (1) to clinicalStatus `resolved`, and chronic or recurrent occurrences to `recurrence`, so active problems look resolved (`FhirConditionTrait.php:109-113`).
- BM-018: FHIR returns problems in insertion order; the old card sorts by begdate ascending (`PatientIssuesService.php:211`).
- BM-012 (shared with Allergies): "None" versus "Nothing Recorded" is inferred from `lists_touch` (`medical_problems.html.twig:6-15`).
- BM-013 (shared with Allergies): the Edit button's `auth` is hard-coded true (`demographics.php:1153`).

## 8. Update after the Fable parity review 2 (2026-09-26)

FHIR's problem-list search requires `lists.activity = 1`, which Fee Sheet problems lack, so an unlinked Fee Sheet problem was missing (BM-051). By user decision the card is now built from the Standard REST API problem list (`GET /api/patient/:puuid/medical_problem`, via `/api/list-dates?list=medical_problem`), which is the old card's own source: each row is keyed by its uuid (a problem linked to two visits is returned twice with the same uuid), filtered by the old `filterActiveIssues` rule and sorted by begdate. FHIR Condition is no longer read for this card, so BM-017 and BM-043 are superseded.

