# Module: Care Team

**Source files:** `interface/patient_file/summary/demographics.php:1250-1276`; `src/Patient/Cards/CareTeamViewCard.php:25-150` (setupOpts, getTemplateVariables, handleFormSubmission); `templates/patient/card/manage_care_team.html.twig:1-227` (view and edit form, rows built in JavaScript); `src/Services/CareTeamService.php`; FHIR `src/Services/FHIR/FhirCareTeamService.php:80-430`, `src/Services/PractitionerService.php:80-95`
**Test patients used:** TP-TYPICAL, TP-EMPTY

## 1. Purpose and lifecycle

Unless `card_care_team` is hidden, `demographics.php:1253` constructs `CareTeamViewCard($pid)`. The constructor first runs `handleFormSubmission()`: if the request POSTs `save_care_team=true`, it checks CSRF and saves the team through `CareTeamService::saveCareTeam`. Only then does `demographics.php:1271` check the card's ACL (`patients`/`demo`) before rendering.

The template embeds the team as JSON (`existing_care_team`, plus user, related-person, facility, role and status options), and a jQuery script builds the member table rows in the browser. A hidden edit form in the same template turns the card into an editor (Edit toggles `.editShow` and `.viewOnly`), and saving POSTs back to `demographics.php`. The card is expanded on this stack.

## 2. What the user sees

| Field shown | Format | Source column |
|---|---|---|
| Team name | heading, for example "practitioner" | care_teams.team_name |
| Team status | badge, for example "Active" | care_teams.status |
| Member type | "Provider" or "Related Person" | care_team_member.user_id or contact_id |
| Member | "Last, First" for users; the person's name for related persons | users.lname/fname, or person via contact |
| Role | role list title, for example "Nurse Practitioner"; blank when unset | care_team_member.role |
| Facility | facility name; blank when unset | care_team_member.facility_id |
| Since | date | care_team_member.provider_since |
| Status | member status, for example "Active" | care_team_member.status |
| Note | text | care_team_member.note |
| Remove | column header shown even in view mode | edit-mode control |

**Sort order:** the order of `existing_care_team` from CareTeamService. TP-TYPICAL shows the Provider first, then the Related Person.
**Filtered out:** None observed; the team is shown whatever its status, with a status badge.
**Empty state text:** None. With no team, the card shows only the table header row (Type, Member, Role, Facility, Since, Status, Note, Remove) and no message (BM-030).
**Visibility rules:** hidden when `card_care_team` is in `hide_dashboard_cards` (demographics.php:1252) or `aclCheckCore('patients','demo')` fails (1271). The Edit button's `auth` is `aclCheckCore('patients','demo','','write')`.

Observed on the old dashboard (2026-09-26):
- TP-TYPICAL: `practitioner Active`, then `Provider | Stone, Fred | Nurse Practitioner | | 2026-09-26 | Active` and `Related Person | martha mom | | | 2026-09-26 | Active`
- TP-EMPTY: header row only

## 3. Controls

| Control | What it does | New dashboard |
|---|---|---|
| Edit | switches the card into its inline editor; the link is `javascript:void(0);` rendered with linkMethod html, so safe_href blocks the URL and logs a warning (BM-027) | leaves it out (read-only) |
| Add team member / Add related person / Remove / Save / Cancel | inline editor that POSTs to the dashboard | not ported |
| Collapse / expand | saves `careteam_ps_expand` | local collapse state |

## 4. Permission checks

| Check | Line | Scope in the new app |
|---|---|---|
| card_care_team not hidden | demographics.php:1252 | deployment configuration (Gate 2 visibility decision) |
| aclCheckCore patients demo (render) | demographics.php:1271 | `patient/CareTeam.rs` or `user/CareTeam.rs`, plus Practitioner and RelatedPerson reads for names |
| aclCheckCore patients demo write (Edit button) | CareTeamViewCard.php setupOpts | not needed: no edit |
| save on POST: CSRF only, no write ACL, runs before the render ACL | CareTeamViewCard.php:124-145, demographics.php:1253 | not applicable (BM-026) |

## 5. Data

**Reads:** `care_teams`, `care_team_member`, `users`, `contact`/`person` (related persons), `facility`, and `list_options` for roles and statuses.
**Writes:** `care_teams` and `care_team_member` when the page is POSTed with `save_care_team=true` (from the card's own edit form).

| Field shown | FHIR resource.field | Status | Checked against | Notes |
|---|---|---|---|---|
| Team name | CareTeam.name | matches | TP-TYPICAL: "practitioner" | |
| Team status | CareTeam.status | matches | TP-TYPICAL: active | FhirCareTeamService defaults a missing or invalid status to active (FhirCareTeamService.php:127-130) |
| Member type | CareTeam.participant.member.reference resource type | matches | TP-TYPICAL: Practitioner/… and RelatedPerson/… | |
| Member name (provider) | Practitioner.name, via the reference | not available | TP-TYPICAL: GET Practitioner/a2c6137a-… is HTTP 404 (Fred Stone has no NPI) | the Practitioner endpoint only serves users with an NPI (PractitionerService.php:88-93) (BM-028) |
| Member name (related person) | RelatedPerson.name, via the reference | not available | TP-TYPICAL: GET RelatedPerson/a2d68932-… is HTTP 404; RelatedPerson?patient= returns 0 | (BM-028) |
| Role | CareTeam.participant.role[].coding[].display | differs | TP-TYPICAL: provider "Nurse Practitioner" matches; related person has code 407542009 with no display where the old card shows blank | an unmatched role defaults to SNOMED 407542009 informal caregiver (FhirCareTeamService.php:424) (BM-031) |
| Facility | CareTeam.participant.onBehalfOf | matches | TP-TYPICAL: no facility, absent in both | |
| Since | CareTeam.participant.period.start | differs | TP-TYPICAL: provider 2026-09-26 present; related person period absent where the old card shows 2026-09-26 | the related-person since date is lost (BM-037) |
| Member status and note | none | not available | TP-TYPICAL: both members Active on the old card; no per-member status or note in the participant | |
| Participant names in one call | CareTeam?_include=CareTeam:participant | differs | TP-TYPICAL: returns HTTP 200 with 0 entries, dropping the CareTeam itself | never use _include (BM-029) |
| Empty state wording | Bundle with 0 entries | differs | TP-EMPTY: FHIR 0 entries; old card shows only headers | the new card shows "No care team recorded" (BM-030) |
| List completeness | Bundle.entry (one CareTeam per team) | matches | TP-TYPICAL: old 1 team with 2 members, FHIR 1 CareTeam with 2 participants; TP-EMPTY: 0 and 0 | |

## 6. Globals

| Global | Read or write | Effect on the card |
|---|---|---|
| hide_dashboard_cards (card_care_team) | read (demographics.php:1252) | hides the card |
| user setting careteam_ps_expand | read (CareTeamViewCard setupOpts) and write | initial collapsed state |
| $_POST save_care_team, team_id, team_name, team, team_status | read (handleFormSubmission) | saves the team during page construction |
| $pid | read | whose team is loaded |

## 7. Problems

- BM-026: the care team is saved from the view card's constructor with only a CSRF check, and no write permission check, before the render ACL runs (`CareTeamViewCard.php:124-145`, `demographics.php:1253` vs `1271`).
- BM-027: the Edit link is `javascript:void(0);` with linkMethod html, so safe_href blocks it and logs a warning on every dashboard load.
- BM-028: participant names can't be resolved: Practitioner only serves users with an NPI, and the RelatedPerson reference returns 404 (`PractitionerService.php:88-93`).
- BM-029: `_include=CareTeam:participant` returns an empty Bundle instead of the CareTeam.
- BM-030: an empty care team shows only table headers, with no message.
- BM-037: the related person's since date, member status and note aren't in FHIR, and the old Remove header shows in view mode.
- BM-031: an unrecorded related-person role is sent as SNOMED 407542009 informal caregiver, with no display (`FhirCareTeamService.php:424`).
