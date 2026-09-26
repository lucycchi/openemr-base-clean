# Planning brief: port the OpenEMR patient dashboard to a modern framework

## The assignment

Read `clinical_copilot_week2/AgentForge — Clinical Co-Pilot W2 — Surprise Challenge_ Modernize the Patient Dashboard (1).pdf` first. In short:

- Reimplement the patient dashboard's presentation layer in a modern framework. Do not redesign it. Feature parity with the current dashboard is the standard.
- All data comes from OpenEMR's existing REST and FHIR API. **The PHP backend is not modified.** Configuration changes through the admin UI (enabling the API, registering an OAuth client) are allowed and must be written down.
- Required by the end of the week:
  - OAuth2 / OpenID Connect login
  - a patient header showing name, date of birth, sex, MRN and active status
  - Allergies, Problem List, Medications, Prescriptions and Care Team cards, each with live FHIR data
  - one more section, chosen from encounters, labs, vitals, immunizations, appointments or notes
- `PATIENT_DASHBOARD_MIGRATION.md` defends the framework choice: why this framework, what moving off PHP gained, and what it cost. It is graded.

## Where things go

All documents go in `clinical_copilot_week2/migration/` in this repo unless a step says otherwise. The exception is `PATIENT_DASHBOARD_MIGRATION.md`, which goes in the repo root so graders find it straight away.

## Starting points already found

| Piece | Current source |
|---|---|
| Dashboard entry point | `interface/patient_file/summary/demographics.php` |
| Patient identity bar | `interface/main/tabs/templates/patient_data_template.php`, filled by `interface/main/tabs/js/patient_data_view_model.js`. It sits in the tab frame, outside the dashboard page. |
| Page heading | `interface/patient_file/summary/dashboard_header.php`, `templates/patient/dashboard_header.html.twig`, `OemrUI::pageHeading()` |
| Allergies | `demographics.php` ~line 1134 → `templates/patient/card/allergies.html.twig` |
| Problem List | `demographics.php` ~line 1158 → `medical_problems.html.twig` |
| Medications | `demographics.php` ~line 1180 → `medication.html.twig` |
| Prescriptions | `demographics.php` ~line 1245 → `rx.html.twig`, or `erx.html.twig` (~line 1208) when eRx is enabled |
| Care Team | `demographics.php` ~line 1253 → `src/Patient/Cards/CareTeamViewCard.php` → `manage_care_team.html.twig` |
| Extra-section candidates | `vitals_fragment.php`, `labdata_fragment.php`, `pnotes_fragment.php`, immunizations via `stats.php`, and `appointments.html.twig` |
| FHIR API | `apis/routes/_rest_routes_fhir_r4_us_core_3_1_0.inc.php`, `src/Services/FHIR/` |
| OAuth2 | `oauth2/authorize.php` |

Confirm each of these before relying on it. None of the dashboard cards has Twig render fixtures or E2E coverage today.

## Terms used below

A **module** is one thing the user sees on the dashboard, together with all the code that produces it: the PHP section in `demographics.php` (or the fragment file), its Twig template, the service or SQL that loads its data, and any JavaScript that runs it. The Allergies card is one module. Do not treat each PHP file as a module.

## Phases

Each phase ends at a **gate**. At a gate, stop, summarise what was produced, and wait for the user before starting the next phase. Ask the user every question as a full plain-language sentence. Do not use shorthand labels or option codes.

### Phase 0: Prove the API works for a browser app (spike)

This comes first because it is the most likely thing to block the whole port. Using the dev stack (`docker/development-easy`, http://localhost:8300):

1. Find out which admin settings must be turned on for the FHIR API and OAuth2.
2. Register an OAuth client suitable for a browser app: public client, authorization code flow with PKCE, a redirect URI and the scopes the cards need.
3. From a browser origin other than OpenEMR's, log in and fetch one `Patient` and one `AllergyIntolerance` for a test patient. Record whether CORS gets in the way.

Output: `migration/API-SPIKE.md` with the exact settings, client registration, scopes and any blockers.
**Gate:** the user confirms that the spike works, or decides what to do about a blocker.

### Phase 1: Inventory

Start from `demographics.php` and list everything it pulls in: included PHP files, fragments loaded after the page opens, Twig templates, services, JavaScript, and global settings that turn cards on or off.

Output: `migration/INVENTORY.md`, a tree. Record what exists and how it connects. Do not critique the code here.

Mark every module as one of:
- **in scope**: the header, the five required cards, and the extra-section candidates
- **out of scope**: everything else, listed so the choice is visible

**Gate:** the user confirms the in-scope list.

### Phase 2: Audit the in-scope modules

Do the in-scope modules only. Put one file per module in `migration/modules/`, for example `migration/modules/allergies.md`.

**Step 1: read the code.** Read every handler and helper in the module from top to bottom, without skimming. The goal is to be able to describe the module without reopening the file.

**Step 2: record, for each module:**

1. **Purpose and lifecycle.** What happens when the page loads, what happens on user actions, and whether anything is loaded later by JavaScript.
2. **What the user sees.** Every field shown, the sort order, what is filtered out (for example inactive or resolved items), and what an empty card looks like. Parity tests are written from this section, so it has to be exact.
3. **Controls.** Every button, link and toggle, and what each does. The port is read-only for now, so say what each edit control does and whether the new dashboard shows it, links out to OpenEMR for it, or leaves it out.
4. **Permission checks.** Every ACL check, for example `AclMain::aclCheckCore(...)`, and any place a check is missing. Name the OAuth scope that would cover the same access in the new app.
5. **Data.** The tables and columns read, and anything written. Then map each field the user sees to its FHIR resource and field. Mark each field as **matches**, **differs** (how) or **not available through the API**. This mapping is the most important part of the audit.
6. **Globals.** Every value read from or written to `$GLOBALS`, `OEGlobalsBag`, the session (`$_SESSION`, `SessionWrapperFactory`) or local-scope variables such as `$pid` and `$hiddenCards`. Say which global settings change what the card shows. For example, the eRx setting changes which prescriptions card appears.
7. **Problems.** Bugs, dead code, half-finished features, missing permission checks, output that isn't escaped, mutable global state. Cite each one as the function name in backticks, plus a line number when that matters.

**Step 3: catalogue the problems.** Copy every problem into `migration/BUGS-MITIGATIONS.md`, one row each, with:
- severity: Critical, High, Medium or Low
- category, for example security, correctness, dead code or global state
- the citation
- **what the port does about it**, one of:
  - **keep for parity**: the new dashboard reproduces this behaviour on purpose, and the reason is written down
  - **fix in the new app**: the problem is in the presentation layer, so the new app designs it out
  - **out of scope**: the problem is in the backend, which this project does not change; record it and move on
- a concrete mitigation for the problems marked "fix in the new app"

The catalogue, not the audit notes, is what the rewrite works from.

**Gate:** the user reviews the FHIR mapping gaps and the "keep for parity" decisions.

### Phase 3: Migration options

Write `migration/MIGRATION-OPTIONS.md` with three or four realistic routes. For each route, cover:
- the framework and language
- how OAuth2 with PKCE works in it
- how it handles FHIR types: are there maintained FHIR TypeScript types or client libraries?
- testing: unit tests, component tests, and Playwright end-to-end tests
- how it is hosted next to OpenEMR, and what that means for CORS
- how quickly it can be built within one week
- how well each option can be defended in the graded document

End with a recommendation and the main reason for it.

**Gate:** go through the options with the user one at a time and let the user decide. Do not choose for them.

### Phase 4: The defence document

Write `PATIENT_DASHBOARD_MIGRATION.md` in the repo root using the chosen route. It must answer the three graded questions directly: why this framework, what moving off PHP gained, and what the tradeoffs are.

Use evidence from the audit where possible, for example "the Allergies card read the `lists` table directly and now uses `AllergyIntolerance`, which changes X". Include the FHIR gaps and the "keep for parity" decisions as honest tradeoffs.

**Gate:** the user reviews the document.

### Phase 5: Migration spec

Write `migration/MIGRATION-SPEC.md` modelled on
https://github.com/decagondev/vb6-rework-reverse-forward/blob/main/docs/MIGRATION-DOTNET.md. Read that document first and follow its structure:

1. purpose and scope
2. target architecture and layers
3. project structure
4. tech stack and rationale
5. coding standards
6. test strategy
7. documents that must be kept current
8. git workflow
9. the slice loop
10. the arc / story / slice hierarchy
11. roadmap
12. templates
13. definition of done

Adapt it rather than copy it:

- **Scale.** The reference plans months of work. This project has one week. Size a slice at a few hours and an arc at a day or less. Expect roughly four to six arcs: foundation and auth, header, required cards, extra section, and the defence and demo.
- **The frozen original.** The reference freezes its original code. Here the OpenEMR PHP backend is the frozen original, and it also serves the API. No slice edits it.
- **Layers for a browser app.** Use an API client (OAuth and fetch), FHIR-to-view mappers (pure functions), view models or hooks, and components. The mappers are where parity bugs are most likely, so they need the most unit tests.
- **Git.** Follow this repo's rules in `CLAUDE.md`: Conventional Commits and the `Assisted-by` trailer. Ask the user whether they want the reference's rule that only the user commits to main.
- **Test strategy.** It must contain:
  - **Parity tests, one for each module.** Playwright opens the old dashboard (http://localhost:8300) and the new app for the same test patient and compares what each card shows, as text rather than HTML. Run them against a small fixed set of test patients that cover edge cases: no allergies, resolved problems, stopped medications, an empty care team, and an inactive or deceased patient.
  - **Unit tests** for every FHIR-to-view mapper, including missing fields and ordering.
  - **E2E tests for things the old dashboard can't be compared on:** OAuth login and logout; an expired or rejected token, which must send the user back to login; an API failure, where the card must say it failed to load; and switching patients, where no data from the previous patient may remain. A card that shows as empty because of an error reads as "no allergies" to a clinician. That is a safety problem, so these tests are required.
- **Tracking.** `DEV-LOG.md` and the per-arc files go in `migration/`. `BUGS-MITIGATIONS.md` entries are struck through as they are resolved, as in the reference.

**Gate:** the user approves the spec.

### Phase 6: Hand off to building

The plan ends here. The build is carried out from `MIGRATION-SPEC.md`, with every slice written test-first: the parity test or unit test fails first, then the code makes it pass.

Build every arc inline with `superpowers:test-driven-development` (user decision, 2026-09-26). Write each slice's acceptance criteria as named tests so they can be used without rewording.

## Constraints that apply throughout

- Do not modify the OpenEMR PHP backend, database schema or API.
- Do not redesign the interface. Visual changes are allowed only where the framework makes them unavoidable, and each one is recorded in the defence document.
- Always keep patient data on screen tied to the correct patient. Treat any risk of mixing up patients as Critical.
- The dev stack login is `admin` / `pass`. Never commit credentials or OAuth client secrets.
