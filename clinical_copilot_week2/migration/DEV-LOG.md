# Development log

Newest entry first. One entry per slice, using the template in `MIGRATION-SPEC.md`.

## 2026-09-26 — Arc 03 / Story 03-05 / Slice 03-05-06 — Fixes from the Opus parity review 4

**Branch:** `dashboard-migration`
**Status:** ready-for-commit

### Worked on
- A fresh Opus 5.5 agent reviewed read-only: 4 P1 and 9 P2 findings. The P1s were checked against the OpenEMR source before any fix.
- Medications and Prescriptions (user decision, replacing the split on intent):
  - Medications is the Standard REST API medication list with the old rule, begdate order and dosage from the FHIR request with the same id.
  - Prescriptions is every FHIR request that is not a list entry, whatever its intent.
  - This fixes list entries vanishing when Prescriptions is hidden, and non-"order" prescriptions landing on the wrong card or on none.
  - Medication and prescription parity are now exact; the caveat notes are gone.
- Idle sign-out (BM-056):
  - `IDLE_TIMEOUT_SECONDS` (default 7200, mirroring `timeout`) sets the BFF session lifetime and the page's `useIdleLogout`
  - the page rechecks `/auth/me` every minute and on returning to the tab
  - `SESSION_TTL_MINUTES` is replaced
- A 403 leaves the card out, as the old page leaves out cards a user may not see; this also covers BM-055's permission gate.
- Header (BM-059): Deceased only for a real date on or before today.
- Related persons: a failed read other than a 404 says "Name couldn't be loaded".
- Allergies (BM-060): the name is the stored title from the standard list, which also removes the BM-009 exception from parity ("Latex <b>x</b>" is shown literally, as on the old card).
- Session hardening:
  - login attempts that never complete expire after 10 minutes
  - the store is capped (503 when full)
  - the session id is rotated at the callback
  - `/auth/login` while signed in goes back to the dashboard instead of dropping the session
  - the names cache is capped (oldest first)
- Visit reasons (BM-033, user decision): the Encounter history card notes that OpenEMR's API does not apply the sensitivity restrictions; the defence advises sites.
- Care-team parity: a TP-LONG team with Donna Lee (NPI) whose name must resolve, a fixed expectation independent of the names route.

### Decisions
- Ruling: a list entry linked to a prescription (Amlodipine) appears on both cards, as on the old dashboard, but without its list dosage, which FHIR does not carry and the standard list does not include (BM-020). Cost if wrong: one dosage text missing for linked entries.
- Recorded, not fixed: BM-057 (Care Team needs patients/med) and BM-058 (end dates compared with the browser's clock).

### Tests
- Unit: 245 / 245 passing
- Playwright: 35 / 35 passing (new: ended session, forbidden card)
- Lint, typecheck and Prettier: clean
- Proven red:
  - medication parity with the intent rule restored (Atorvastatin missing)
  - care-team parity with the names route blanked
  - every new unit test and E2E before its fix

### BUGS-MITIGATIONS.md updates
- Added BM-056 to BM-060 (056, 059 and 060 resolved; 057 and 058 out of scope). BM-033 raised to High with the user's mitigation. BM-009, BM-019, BM-020, BM-036 and BM-042 annotated. Gate 2 amended.

### Open questions / follow-ups
- ARC-05 deploy: set `IDLE_TIMEOUT_SECONDS` to the site's `timeout` value.

## 2026-09-26 — Arc 03 / Story 03-05 / Slice 03-05-05 — Fixes from the Codex parity review 3

**Branch:** `dashboard-migration`
**Status:** ready-for-commit

### Worked on
- After the user upgraded their ChatGPT plan, a fresh Codex run completed: 5 P1 and 2 P2 findings. All five P1s were checked against the OpenEMR source before any fix.
- Proxy (BM-054): OpenEMR's rewrite appends the query and PHP keeps the last `_REWRITE_COMMAND`, so a forwarded one could reach any API route. The proxy now forwards only `patient` and `name`; any other parameter is a 400.
- End dates: the old rule compares the stored end date and time with now, and add_edit_issue.php stores a time. `isCurrentListRow(row, now)` now compares full local timestamps, and the patient view passes the local date and time fixed at mount.
- Medications: without list dates, both cards show a load error; the Prescriptions fallback could show a finished list entry marked Order as current.
- Problems (BM-055): the standard problem list checks encounters/notes, while the old card checks patients/med. `/api/list-dates?list=medical_problem` now first requires the user's one-row FHIR Condition search (patients/med) to succeed, and answers 403 otherwise.
- Header: no clinical card renders until the header is ready, which also means the patient id matched, as old demographics.php exits when the patient cannot be shown.
- Names: `/api/display-names` returns `failed` for references OpenEMR answered with an error (not 404), uncached, and the cards mark them "Name couldn't be loaded".
- Tests:
  - care-team parity compares Since wherever FHIR has a period
  - encounter parity requires "Show all" when the old page has more visits than a page
  - medication parity compares dosage text
  - the switch test delays every `/api/` read, not only FHIR
  - the reader self-test compares the name part
- Seed: Metformin dosage text through a lists_medication row, because the dosage check was vacuous (no Medications-card entry had dosage text).

### Decisions
- Ruling: both medication cards fail together without list dates, rather than Prescriptions falling back to FHIR, because FHIR cannot tell a finished list entry marked Order from a prescription. Cost if wrong: Prescriptions is unavailable during a standard-API outage although most of its rows came from FHIR.
- Ruling (BM-055 residual): a user with patients/med but not encounters/notes gets a load error on the problem card where the old card showed it. Granting both matches the old behaviour.

### Tests
- Unit: 236 / 236 passing
- Playwright: 33 / 33 passing (new: header-gating E2E)
- Lint, typecheck and Prettier: clean
- Proven red: every new unit test and the header-gating E2E before the fix; dosage parity with dosages blanked (after seeding, it failed on Metformin).

### BUGS-MITIGATIONS.md updates
- Added and resolved BM-054, BM-055. BM-044, BM-047 and BM-051 annotated.

### Open questions / follow-ups
- None beyond the ARC-05 deploy settings already listed.

## 2026-09-26 — Arc 03 / Story 03-05 / Slice 03-05-04 — Fixes from the Fable parity review 2

**Branch:** `dashboard-migration`
**Status:** ready-for-commit

### Worked on
- A second Codex run hit the user's ChatGPT usage limit before writing any findings, so a fresh Fable agent reviewed instead. It found 2 P1 and 6 P2 issues, and confirmed every earlier fix holds. The P1s and the refresh race were checked against the OpenEMR source before any fix.
- Problems (BM-051): FHIR's problem list requires `activity = 1`, which Fee Sheet problems lack.
  - User decision: build the card from the Standard REST API problem list, the old card's own source.
  - `/api/list-dates?list=medical_problem` returns uuid, end date, outcome, title and start date.
  - `mapProblemList` keys rows by uuid, applies the old rule and sorts by begdate. `useProblemCard` wires it.
  - The FHIR problem mapper, its merge heuristic and the FHIR problem fixtures were removed.
  - Scope `user/medical_problem.rs` was added.
- Care Team (BM-053): FHIR sends removed (inactive) members with no status, and no API exposes it. User decision: a visible note on the card. Teams marked entered-in-error are hidden and the badge colour follows the team status.
- Refresh race: every card refreshed in parallel and OpenEMR revokes a refresh token on first use, so the losers logged the user out. `ensureFreshToken` now shares one in-flight refresh per session.
- Names outage: a failed `/api/display-names` now marks those rows "Name couldn't be loaded", distinct from "Name unavailable" (no record).
- Proxy: a search must carry exactly one `patient` value (no lists, no repeats); ids may not start with a dot.
- `/api/display-names` caches names and 404s for ten minutes, and fetches only what is not cached.
- Tests:
  - care-team parity allows "Name unavailable" only for members the lookup cannot name
  - medication parity requires each old entry on the card its FHIR intent says
  - header parity approves the middle name (BM-052) and pins TP-LONG's full name
  - the problems BM-004 E2E now rewrites the problem list's patient
  - the problem error E2E blocks the problem list
- Seeds: TP-HISTORY "Fee sheet problem" (inserted as the Fee Sheet does) and TP-LONG's middle name Quinn.

### Decisions
- Ruling: the problem card reads only the standard API list rather than merging it with FHIR. Visit-linked copies in FHIR carry the link's uuid, not the problem's, so a merge would need the name-and-onset heuristic again; the standard list is exactly the old card's source. Cost if wrong: the card no longer uses FHIR at all, one step further from FHIR-first than the user's "also read" wording.
- Ruling: the middle name stays (BM-052), as an improvement recorded against the old bar.

### Tests
- Unit: 231 / 231 passing
- Playwright: 32 / 32 passing (new: names outage E2E, problems BM-004 against the list)
- Lint, typecheck and Prettier: clean
- Proven red:
  - problem parity on the Fee Sheet problem before the fix, and with the old rule removed (pneumonia leaked in)
  - the problems BM-004 E2E with the parser's patient check off
  - medication parity with every list medication moved to Prescriptions (it failed on Metformin and Lisinopril, which the old spec missed)
  - header parity on the middle name before the exception

### BUGS-MITIGATIONS.md updates
- Added BM-051 (resolved), BM-052 (resolved), BM-053 (out of scope, mitigated by the note). BM-017, BM-037 and BM-043 annotated. Gate 2 amended.

### Open questions / follow-ups
- ARC-05 deploy: the app client needs `user/medical_problem.rs` too.

## 2026-09-26 — Arc 03 / Story 03-05 / Slice 03-05-03 — Fixes from the Fable parity review

**Branch:** `dashboard-migration`
**Status:** ready-for-commit

### Worked on
- Fable reviewed the port read-only against the old code: 2 P1 and 8 P2 findings. It confirmed that the Codex-era fixes hold, except same-day order and the name checks in parity. Both P1s were checked against the OpenEMR source before any fix.
- F1 (BM-048): OpenEMR's API lets only administrators read Practitioner and Organization.
  - User decision: a server-only lookup, so `server/systemToken.ts` signs RS384 client assertions for a SMART backend-services client (scopes `system/Practitioner.rs system/Organization.rs` only).
  - `server/displayNames.ts` (`/api/display-names?ref=…`) returns display names only, for logged-in users, at most 50 references.
  - `useBundleWithNames` batches staff names through it; related persons are still read with the user's own token and checked against the patient.
  - `register-client.mjs names` creates the key in the gitignored `patient-dashboard/certs/`, registers only the public JWKS, and was enabled on the dev database. The config requires `NAMES_CLIENT_ID` (and `NAMES_KEY_FILE`, which has a default).
- F2 (BM-047, BM-016): user decision to read allergies' end dates and outcome from the Standard REST API.
  - The medication route became `/api/list-dates?list=medication|allergy` (files renamed with `git mv`).
  - The allergy branch reads `GET /api/patient/:puuid/allergy` and treats a 200 with validation errors as a failure.
  - `isCurrentListRow` is shared by both cards.
  - `useAllergyCard` errors the card if the dates fail.
  - Scope `user/allergy.rs` was added to the app client.
- F3: same-day visits keep the API order (FHIR sorts by eid descending, which is the old page's id descending); a second TP-HISTORY visit on 2024-10-26 pins it in parity.
- F5: encounter parity accepts "Name unavailable" only where FHIR sends no primary performer.
- F6: a refresh token OpenEMR rejects (400 or 401) logs the session out, so the proxy answers 401 and the browser re-logs in; other refresh failures are 502, never 500.
- F7: `DATE_DISPLAY_FORMAT` (0, 1, 2) mirrors `date_display_format`; `formatShortDate` ports `oeFormatShortDate` for the header DOB, the deceased date and visit dates.
- F8: `DISABLE_PRESCRIPTIONS=1` hides the Prescriptions card, as `disable_prescriptions` does.
- F9: the visit-copy merge key adds diagnosis codes.
- F10: the proxy refuses a search without `patient=` except the picker's `Patient?name=`.
- `tests/e2e/clinician.spec.ts`: logs in as the seeded non-admin `tp-physician` (Physicians group); every test before it logged in as admin, which is how F1 slipped through.

### Decisions
- Ruling (F4, BM-050): a medication-list row linked to a prescription disappears once the prescription is discontinued; the old card still lists it. Kept as a documented difference, because showing a drug whose only prescription was stopped as current is arguably worse. Cost if wrong: one list entry missing for that case.
- Ruling (BM-049): Clinicians' Encounter search (encounters auth_a) is left to OpenEMR's permissions; the names decision did not cover it. Cost if wrong: default Clinicians see "Couldn't load encounters" until a site grants auth_a.
- The names route answers any Practitioner or Organization for a logged-in user rather than only those linked to the open patient: staff and facility names are directory data the old page showed to anyone who could open a patient, and the route returns the name alone.

### Tests
- Unit: 224 / 224 passing
- Playwright: 31 / 31 passing (new: clinician as non-admin, allergy list-date failure)
- Lint, typecheck and Prettier: clean
- Proven red:
  - clinician E2E with user-token staff reads ("Name unavailable" instead of "Lee, Donna")
  - encounter parity with an empty names route
  - allergy parity with the resolved flag ignored (allergen 02 leaked in)
  - encounter parity against the seeded same-day visits before the fix
  - allergy parity against the seeded allergies before the fix

### BUGS-MITIGATIONS.md updates
- Resolved: BM-016, BM-047, BM-048. Recorded: BM-049, BM-050 (out of scope). BM-028, BM-032 and BM-045 annotated. Gate 2 amended.

### Open questions / follow-ups
- ARC-05 deploy settings:
  - the BFF needs `NAMES_CLIENT_ID`, the key file, and `DATE_DISPLAY_FORMAT` and `DISABLE_PRESCRIPTIONS` to match the site
  - the app client needs `api:oemr user/patient.rs user/medication.rs user/allergy.rs` on top of its FHIR scopes
  - OpenEMR needs `rest_system_scopes_api` on, and both clients enabled

## 2026-09-26 — Arc 03 / Story 03-05 / Slice 03-05-02 — Medication list end dates from the Standard REST API

**Branch:** `dashboard-migration`
**Status:** ready-for-commit

### Worked on
- User decision (option 3): read the medication list's end dates from OpenEMR's Standard REST API, the one exception to FHIR only.
- Test data: `seed-parity-gaps.php` gives Lisinopril (lists 1242) an end date of 2027-06-30. FHIR then calls it "completed", and medication parity failed on TP-TYPICAL before any code changed. TP-HISTORY's Amoxicillin (ended 2024-11-05) covers the past-end-date case.
- `server/medicationEndDates.ts`, `GET /api/medication-end-dates?patient=<uuid>`:
  - maps the uuid to OpenEMR's pid through `GET /api/patient/<uuid>`, then reads `GET /api/patient/<pid>/medication`
  - returns only uuid, end date and outcome, and 502s unless the patient and every row belong to the requested patient
  - rejects anything but a uuid with 400, before calling OpenEMR
- OpenEMR answers an empty list with a bodyless 404 (BM-046). After the patient lookup succeeds, that is read as an empty list; a 404 with a body is still a failure.
- Web:
  - `getJson` on the API client
  - `parseMedicationListDates` (a wrong patient or a malformed answer is an error, never an empty map)
  - `useMedicationCards`, which gives each card its own state: Medications errors if the end dates fail, while Prescriptions falls back to FHIR
- `splitMedications(resources, listDates, today)` applies the old `filterActiveIssues` rule to list rows: hide outcome 1 or an end date on or before today, keep a future one; FHIR status decides only when no list row matches. A list row marked Order keeps that rule on the Prescriptions card.
- Scopes: `api:oemr user/patient.rs user/medication.rs` added to the app client in `spike/register-client.mjs`, on the dev database's existing client, and in the gitignored `.env`.
- `spike/api-get.mjs`: an audit helper like `fhir-get.mjs`, for the Standard REST API.
- `selectLoadState` removed (no longer used).
- `PATIENT_DASHBOARD_MIGRATION.md`: corrected the lines the review made wrong (refills, end dates, visit-linked problems, facility, the medication data source, BM-042); the full ARC-05 rewrite is still to come.

### Decisions
- The old card compares the end date with the current time, so an entry ending today is hidden; the new rule compares dates (`ends > today`), which gives the same answer except for an end date with a time later today, which OpenEMR's date field does not store.
- The route is narrow on purpose: one path and three fields, not a general standard-API proxy.

### Tests
- Unit: 186 / 186 passing (route 7, parser 3, client 2, mapper 6 new, hook 3)
- Playwright: 29 / 29 passing (new: end-date failure E2E)
- Lint, typecheck and Prettier: clean
- Proven red:
  - medication parity with the end date ignored (Amoxicillin leaked in on TP-HISTORY)
  - the failure E2E with Prescriptions made to fail with the end dates

### BUGS-MITIGATIONS.md updates
- Resolved: BM-044. Added: BM-046 (out of scope). Gate 2 data-source decision amended.

### Open questions / follow-ups
- The droplet's client must be registered with the three extra scopes (ARC-05).

## 2026-09-26 — Arc 03 / Story 03-05 / Slice 03-05-01 — Fixes from the Codex parity review

**Branch:** `dashboard-migration`
**Status:** ready-for-commit

### Worked on
- Codex reviewed the port against the old code (6 P1, 5 P2; it rated six of seven sections worse). The four biggest claims were checked against the OpenEMR source before any fix.
- Test data first: `fixtures/seed-parity-gaps.php` seeds each gap on synthetic patients. Problems, prescriptions and care-team parity then failed on TP-TYPICAL, and the highlight E2E failed on TP-LONG, before any code changed.
- Problems (BM-043): search `Condition?patient=` with no category, keep problem-list-item and encounter-diagnosis, merge visit copies by name and onset (most current status wins), never merge a problem-list entry.
- Prescriptions:
  - completed orders are shown, because OpenEMR sends "completed" for any end date and the old card ignores the end date (BM-044)
  - Refills say "Not available", because FHIR always sends 0 (BM-041)
  - the refills field left `MedicationView`
- Care Team (BM-045): Organization added to the proxy allow-list and to the shared name reads (`isReadableNameReference`, `displayName`); facility names shown, "Name unavailable" if unreadable, and FHIR's extra Organization participants dropped.
- Styling: Bootstrap 4.6.2 (OpenEMR's version) imported in `main.tsx`; the app had no stylesheet, so the high-risk highlight and every other class were invisible.
- Encounters: provider from the primary performer (PPRF) only, never a referrer; sort by full start time, ties in reverse API order.
- Settings: `parseSiteConfig` validates `/app-config`; a failed or malformed load shows "Couldn't load the dashboard settings" and no cards, instead of every card.
- Tests:
  - medications parity now requires every old list entry to be on the new Medications or Prescriptions card
  - the switch test looks for TP-TYPICAL's clinical text on every frame (not only the name), and all of the next patient's reads are delayed
  - a new BM-004 E2E feeds each card a real bundle rewritten to another patient and requires a load error

### Decisions
- User decisions (2026-09-26): refills "Not available"; prescription permissions stay as OpenEMR's API enforces them (BM-042); a medication with a future end date must not be treated as finished.
- Ruling: the Medications card still shows active list entries only. FHIR sends "completed" for past and future end dates alike and never the date itself, so a future end date cannot be honoured through FHIR alone. BM-044 stays open for the user's decision. Cost if wrong: a list medication with a future end date is missing from the card until then.
- OpenEMR's JSON escapes slashes (`Patient\/<id>`), so the BM-004 E2E parses the bundle before rewriting it; a text replace silently changed nothing.

### Tests
- Unit: 166 / 166 passing
- Playwright: 28 / 28 passing (new: highlight, settings failure, five wrong-patient cards; switch test rewritten)
- Lint, typecheck and Prettier: clean
- Proven red:
  - problems, prescriptions and care team by the seeded data
  - lost-drug parity by dropping Atorvastatin from Prescriptions
  - the highlight E2E without the stylesheet
  - the BM-004 E2E with the patient check disabled (all five failed)
  - the switch test with the remount removed and a stale hook (it caught Penicillin, Essential hypertension, Metformin and Amlodipine)

### BUGS-MITIGATIONS.md updates
- Added BM-041 to BM-045. Resolved: BM-041, BM-043, BM-045. BM-042 recorded as out of scope by user decision. BM-044 open for the Medications card.

### Open questions / follow-ups
- BM-044, Medications card: show list entries FHIR calls "completed" (risking finished courses), keep hiding them, or read the end date from another source (the user ruled out non-FHIR sources at Gate 2).

## 2026-09-26 — Arc 04 complete: Encounter history

**Slices:** 1 (04-01-01)  **BM rows resolved:** BM-032, BM-034, BM-035, BM-039

### Retrospective
- What worked: reading the old Visit History page directly (its rows are `tr.encrow`, with `pagesize=0` for all) gave a real parity reference for a card that did not exist on the old dashboard. Pulling the member-name reads out of `useCareTeam` before starting meant the encounter hook is five lines.
- What didn't: nothing blocked. The "Show all" comparison only runs when the button exists, so it got its own mutation to prove it is not silently skipped.
- Carried forward to Arc 05: the settings the BFF reads (`AGE_DISPLAY_FORMAT`, `AGE_DISPLAY_LIMIT`, `ENCOUNTER_PAGE_SIZE`, `SESSION_TTL_MINUTES`, the OAuth client values) need listing in the deploy notes; the date-as-written rule (authoredOn, period.start) needs checking against the droplet's time zone.

## 2026-09-26 — Arc 04 / Story 04-01 / Slice 04-01-01 — Encounter mapper, hook and card

**Branch:** `dashboard-migration`
**Status:** ready-for-commit

### Worked on
- Refactor first, with the suite green:
  - `mappers/people.ts` holds `NAME_UNAVAILABLE`, `personName()` and the safe-reference check
  - `hooks/useBundleWithNames.ts` holds the patient check plus one read per person, which `useCareTeam` now wraps
- `web/src/mappers/encounters.ts`:
  - `providerReferences()` lists each readable Practitioner once (the primary performer, else the first participant)
  - `mapEncounters()` sorts newest first, with ties in reverse API order and a missing start last
  - each row has the date as written, the reason text, and the provider or "Name unavailable"
- `web/src/hooks/useEncounters.ts` (wraps `useBundleWithNames`).
- `web/src/cards/EncounterHistoryCard.tsx`:
  - Date, Reason and Provider columns
  - the first `pageSize` rows with a "Show all N" / "Show the N most recent" toggle, and page size 0 shows all
  - "No encounters recorded" and "Couldn't load encounters"
- `server/appConfig.ts`: `ENCOUNTER_PAGE_SIZE` (the encounter_page_size global; default 20, 0 shows all, anything else stops start-up), served as `encounterPageSize` in `/app-config`.
- `tests/support/oldDashboard.ts`: `readOldVisitHistory()` reads the old Visit History page.
- Unit fixtures: Encounter bundles for TP-TYPICAL, TP-HISTORY, TP-LONG and TP-EMPTY, and Donna Lee's Practitioner.

### Decisions
- The date is the first ten characters of period.start. OpenEMR sends the stored local date with a +00:00 offset, so converting it to the browser's zone would move a midnight visit to the day before in any zone west of UTC.
- The provider is the primary performer (PPRF) when there are several participants, since the old page shows one provider.

### Tests
- Unit: 151 / 151 passing (encounter mapper 7, hook 3, card 5, config 2 new and 2 updated)
- Playwright: 22 / 22 passing, including encounter parity for four fixtures (the first page, then all 30 for TP-LONG) and the API-failure E2E
- Lint, typecheck and Prettier: clean
- Seen failing first: mapper, hook and card (modules missing), config (field missing), then parity and E2E (card not rendered). Parity proven red twice: flipping the sort failed on TP-TYPICAL's first page, and a "Show all" that revealed nothing failed on TP-LONG's full list.

### BUGS-MITIGATIONS.md updates
- Resolved: BM-032, BM-034, BM-035, BM-039.

### Open questions / follow-ups
- BM-033 (restricted reasons may be visible through FHIR) stays out of scope and unverified, as the audit recorded.

## 2026-09-26 — Arc 03 complete: Clinical cards

**Slices:** 5 (03-01-01, 03-02-01, 03-03-01, 03-03-02, 03-04-01)  **BM rows resolved:** BM-009, BM-010, BM-011, BM-012, BM-013, BM-014, BM-015, BM-016, BM-017, BM-018, BM-019, BM-020, BM-023, BM-024, BM-028, BM-030, BM-031, BM-036, BM-037, BM-038

### Retrospective
- What worked: one pattern for every card (pure mapper, a hook tagged by patient, a card with `data-*` hooks), recorded FHIR bundles as unit fixtures, and parity specs that name each approved exception by BM id. Every parity spec was proven red by breaking one rule on purpose.
- What didn't: the generic old-card reader could not read two cards. Rows with no child elements lost their text ("Nothing Recorded"), and the Care Team card hides an edit-mode select with every user and role in each cell. The first was fixed in the reader; the second got its own reader that reads only `.viewOnly` text. Two mutation attempts also failed to apply (a comment inside an expression, and Prettier reflowing the target line), so a green run was not taken as proof until the mutation was confirmed in the file.
- Carried forward to Arc 04: `useCareTeam` shows the pattern for a card that needs follow-up reads; the read-only E2E and the switch E2E pick up any new `data-card` automatically.

## 2026-09-26 — Arc 03 / Story 03-04 / Slice 03-04-01 — Care Team card

**Branch:** `dashboard-migration`
**Status:** ready-for-commit

### Worked on
- `web/src/mappers/careTeam.ts`:
  - `memberReferences()` lists each Practitioner and RelatedPerson reference once, and only relative references with a safe FHIR id
  - `personName()` gives "Last, First" for a provider as on the old card, and a related person as written
  - `mapCareTeams()` gives team name, status, and each member's type, name, role, facility and since. An unread member is "Name unavailable"; a role with no display is blank.
- `web/src/hooks/useCareTeam.ts`:
  - loads `CareTeam?patient=<id>` and checks it belongs to the patient, then reads each member once
  - a failed read, a read that returns a different resource, or a related person of another patient leaves the member unnamed but never fails the card
- `web/src/cards/CareTeamCard.tsx`:
  - each team heading with a status badge, then Type, Member, Role, Facility, Since
  - "No care team recorded", "No members recorded" and "Couldn't load the care team"
- `tests/support/oldDashboard.ts`: `readOldCareTeam()` reads only the old card's view-mode text.
- Unit fixtures: CareTeam bundles for TP-TYPICAL and TP-EMPTY.

### Decisions
- Parity compares team name and status, and each member's type, role and facility, in order. The member name must be the old name or "Name unavailable" (BM-028), and Since is compared only where FHIR has it (BM-037).
- On the dev stack every member read is HTTP 404, so the resolved-name path is covered only by unit tests.

### Tests
- Unit: 134 / 134 passing (care team mapper 10, hook 5, card 4)
- Playwright: 20 / 20 passing, including care team parity for TP-TYPICAL and TP-EMPTY, the API-failure E2E, and the read-only E2E now covering the care team
- Lint, typecheck and Prettier: clean
- Seen failing first: all three unit files (modules missing), then parity, error and read-only E2E (card not rendered). Parity proven red by showing a bare role code (it failed on TP-TYPICAL with role "407542009").

### BUGS-MITIGATIONS.md updates
- Resolved: BM-028, BM-030, BM-031, BM-037.

### Open questions / follow-ups
- Each member costs one extra FHIR read. That is fine for the handful of members a team has, but worth noting in the defence.

## 2026-09-26 — Arc 03 / Story 03-03 / Slice 03-03-02 — Prescriptions card

**Branch:** `dashboard-migration`
**Status:** ready-for-commit

### Worked on
- `web/src/cards/PrescriptionsCard.tsx`:
  - a table with Drug, Details, Qty, Refills and Added, newest first (the sort comes from `splitMedications`)
  - "No active prescriptions" when nothing active remains, including when every prescription is discontinued
  - "Couldn't load prescriptions" on error, and the same list-versus-prescription note as the Medications card
- The patient view gives the card the prescriptions half of the one MedicationRequest fetch.
- `tests/e2e/readonly.spec.ts`: the four clinical cards have no edit or add controls (BM-013).

### Decisions
- Parity compares drug, quantity, refills and date added of every old row, in order. Extra new rows are allowed only when the name is on the old medication list (BM-019: TP-TYPICAL Atorvastatin). Details is not compared (BM-038).
- BM-012 and BM-013 are struck through now that the allergy, problem and medication cards are all built, as the Story 03-01 note planned.

### Tests
- Unit: 115 / 115 passing (prescriptions card 5)
- Playwright: 18 / 18 passing, including prescriptions parity for four fixtures, the API-failure E2E and the read-only E2E
- Lint, typecheck and Prettier: clean
- Seen failing first: card unit test (module missing), then parity and E2E (card not rendered). Parity proven red by reversing the prescription sort (it failed on TP-TYPICAL with Amlodipine and Omeprazole swapped). The read-only E2E was proven red with a temporary Edit button on the Prescriptions card.

### BUGS-MITIGATIONS.md updates
- Resolved: BM-012, BM-013, BM-023, BM-024, BM-038.

### Open questions / follow-ups
- None.

## 2026-09-26 — Arc 03 / Story 03-03 / Slice 03-03-01 — Shared medication mapper and Medications card

**Branch:** `dashboard-migration`
**Status:** ready-for-commit

### Worked on
- `web/src/mappers/medications.ts`, `splitMedications()`:
  - keeps active MedicationRequests only
  - intent `order` goes to Prescriptions (sorted by authoredOn, newest first) and everything else to Medications (API order)
  - each row carries name, dosageInstruction text, quantity, refills and the "Added" date for the Prescriptions card in the next slice
- `web/src/cards/MedicationsCard.tsx`: name and dosage per row, "None recorded" when empty, "Couldn't load medications" on error, and a visible note that entries marked as an order appear under Prescriptions.
- The patient view makes one `MedicationRequest?patient=<id>` fetch through `useBundleCard`; `selectLoadState` (web/src/hooks/loadState.ts) hands each card its half.
- Unit fixtures: MedicationRequest bundles for TP-TYPICAL, TP-HISTORY, TP-LONG and TP-EMPTY.
- The old-dashboard reader now returns each row's child texts (`parts`); a row with no child elements, such as "Nothing Recorded", is one part holding its own text.

### Decisions
- Parity compares the set of names, not the order (BM-036). A name on the old list may be missing from the new card only if FHIR reports it with intent `order`, which covers BM-019 and BM-020.

### Tests
- Unit: 110 / 110 passing (medications mapper 6, card 3, selectLoadState 1)
- Playwright: 15 / 15 passing, including medications parity for four fixtures and the API-failure E2E
- Lint, typecheck and Prettier: clean
- Seen failing first: mapper and card (modules missing), then parity and E2E (card not rendered). Parity proven red by sending intent-order entries to Medications (it failed on TP-TYPICAL with Omeprazole).

### BUGS-MITIGATIONS.md updates
- Resolved: BM-019, BM-020, BM-036.

### Open questions / follow-ups
- "Added" keeps authoredOn's local date and time as written; this assumes the server's stored time is the clinic's local time (check on the droplet).

## 2026-09-26 — Arc 03 / Story 03-02 / Slice 03-02-01 — Problem list card

**Branch:** `dashboard-migration`
**Status:** ready-for-commit

### Worked on
- `web/src/mappers/problems.ts`, `mapProblems()`:
  - keeps every Condition whose clinicalStatus is not `inactive`
  - labels `resolved` ("resolved per FHIR"), `recurrence`, `relapse` and `remission`
  - sorts by onset ascending, with a missing onset first and ties in API order
  - name from `code.text`, else a real coding display, else the narrative, else "Unnamed problem"
- `web/src/cards/ProblemListCard.tsx`: titled "Medical Problems" as on the old dashboard. "None recorded" when empty, "Couldn't load medical problems" on error, and `data-patient-id`.
- The patient view loads `Condition?patient=<id>&category=problem-list-item` through `useBundleCard`.
- Unit fixtures: problem-list Condition bundles for TP-TYPICAL, TP-HISTORY, TP-LONG and TP-EMPTY.

### Decisions
- Parity compares names in order. The approved exceptions are BM-012 (empty wording) and BM-017 (the label is stripped before comparing). No fixture has `outcome = 1`, so the case where the new card shows a truly resolved problem the old one hides is covered only by the mapper test.

### Tests
- Unit: 100 / 100 passing (problems mapper 5, card 3)
- Playwright: 13 / 13 passing, including problem parity for four fixtures (the 60-row order included) and the API-failure E2E
- Lint, typecheck and Prettier: clean
- Seen failing first: mapper and card (modules missing), then parity and E2E (card not rendered). Parity proven red by removing the sort (it failed on TP-LONG).

### BUGS-MITIGATIONS.md updates
- Resolved: BM-017, BM-018.

### Open questions / follow-ups
- None.

## 2026-09-26 — Arc 03 / Story 03-01 / Slice 03-01-01 — Allergies card

**Branch:** `dashboard-migration`
**Status:** ready-for-commit

### Worked on
- `web/src/mappers/narrative.ts`: `narrativeText()` parses a narrative div with DOMParser and reads `textContent`, never rendering it (BM-009). `realCodingDisplay()` skips OpenEMR's data-absent "Unknown" coding.
- `web/src/mappers/allergies.ts`, `mapAllergies()`:
  - name from a real coding display, else `code.text`, else the narrative, else "Unnamed allergy"
  - reactions from the manifestations
  - criticality labels "Low risk", "High risk" or "Unable to assess", with high highlighted
  - active only
  - API order kept
- `web/src/hooks/useBundleCard.ts`: the shared card hook. It fetches `<Type>?patient=<id>`, runs `assertBelongsTo`, maps, and tags the result by patient. Every clinical card will use it.
- `web/src/cards/AllergiesCard.tsx`: "Name (risk)" rows with a "Name Reaction: … - risk" tooltip, and the old highlight classes on high risk. "No allergies recorded" when empty, "Couldn't load allergies" on error, and `data-patient-id`.
- Test support: `tests/unit/fixtures/load.ts` (under jsdom, `import.meta.url` is not a file URL); `newApp.ts` returns row tooltips, highlights, empty text and the card's patient id; `oldDashboard.ts` gains `showOldPatient()`.
- Unit fixtures: AllergyIntolerance bundles for TP-TYPICAL, TP-HISTORY, TP-ESCAPING, TP-LONG and TP-EMPTY.

### Decisions
- One shared `useBundleCard` hook instead of a per-card hook, so the patient check and tag-by-patient safety are written once.
- Parity compares names (in order) and reactions. The approved exceptions are applied explicitly in the spec: BM-009 markup, BM-011 risk wording, BM-012 empty wording, BM-015 brackets.

### Tests
- Unit: 92 / 92 passing (allergies mapper 9, shared hook 3, card 3)
- Playwright: 11 / 11 passing, including allergy parity for six fixtures and the API-failure E2E
- Lint, typecheck and Prettier: clean. Typecheck caught a `Coding` type mismatch under `exactOptionalPropertyTypes` that the Vite build and the tests had let through.
- Seen failing first: mapper, hook, card, parity and E2E. Parity proven red by breaking the active filter (it failed on TP-HISTORY).

### BUGS-MITIGATIONS.md updates
- Resolved: BM-009, BM-010, BM-011, BM-014, BM-015, BM-016.

### Open questions / follow-ups
- None.

## 2026-09-26 — Arc 02 complete: Header

**Slices:** 3 (02-01-01, 02-01-02, 02-02-01)  **BM rows resolved:** BM-004, BM-005, BM-007 (BM-040 added and handled)

### Retrospective
- What worked: pinning the age port to OpenEMR's own output (run in the container) instead of hand-written expectations, which found the "4months" bug. Header parity now runs against the real old identity bar for all seven fixtures.
- What didn't: two tests passed before they could fail. The rejected-session E2E passed because no card existed yet, and the first switch test only recorded DOM mutations, so a stale header that never changed was invisible to it. Both were rewritten and proven by breaking the code on purpose. Lesson: prove every browser test red.
- Carried forward to Arc 03: cards set `data-patient-id` and use `LoadState`, and `usePatient`'s tag-by-patient pattern is the template for every card hook.

## 2026-09-26 — Arc 02 / Story 02-02 / Slice 02-02-01 — Patient switch

**Branch:** `dashboard-migration`
**Status:** ready-for-commit

### Worked on
- `web/src/cards/PatientPicker.tsx`: searches `Patient?name=` through the proxy and lists matches as "Name (MRN) DOB: …". It says "No patients match" when nothing is found, and "Couldn't search for patients" when the search fails.
- `App.tsx`: in-app navigation (`history.pushState` plus `popstate`), and `PatientView` keyed by patient id, so a switch remounts every card.
- The header card carries `data-patient-id`, and every future card will too, so a test can check each card belongs to the header patient.

### Decisions
- The switch E2E samples every card's text on every animation frame from the moment of the click, rather than recording DOM mutations. The first version passed even with a deliberately unsafe hook, because a stale header that never changes produces no mutation.

### Tests
- Unit: 77 / 77 passing (picker 2)
- Playwright: 9 / 9 passing, including both switch E2Es against the real OpenEMR name search
- Lint, typecheck and Prettier: clean
- Seen failing first: picker, header `data-patient-id` and both switch E2Es. Proven red on broken code: the switch E2E (remount key removed and the unsafe hook), and the owner E2E (a wrong `data-patient-id`).

### BUGS-MITIGATIONS.md updates
- None new (BM-004 was resolved in 02-01-02).

### Open questions / follow-ups
- None.

## 2026-09-26 — Arc 02 / Story 02-01 / Slice 02-01-02 — Header mapper, hook and card

**Branch:** `dashboard-migration`
**Status:** ready-for-commit

### Worked on
- `web/src/mappers/header.ts`, `mapHeader(patient, {asOf, age})`:
  - name from the official name
  - MRN from the identifier with type PT
  - "DOB: … Age: …" or "DOB: … Age at death: …", matching the old identity bar
  - sex label from `gender`
  - status "Deceased (date)" from `deceasedDateTime`, otherwise "Active"; `Patient.active` is never read
  - an explicit fallback for every missing field
- `web/src/hooks/usePatient.ts`: loads `Patient/{id}` through the API client. A Patient with a different id is a `wrong-patient` LoadError (BM-004). The result is tagged with its patient id, so a patient switch reports loading and never the previous patient.
- `web/src/cards/PatientHeader.tsx`: fields tagged with `data-item` for the parity reader, and error and loading states with `data-state`.
- `App.tsx`: `/patient/:fhirId` route, the API client created once, age settings from `/app-config` (the BFF now serves `ageDisplay` from `AGE_DISPLAY_FORMAT` and `AGE_DISPLAY_LIMIT`, defaults 0 and 3), and today's date taken once from the browser.
- Test support: `tests/support/newApp.ts` (moved from 01-04-01). `oldDashboard.ts` gains `openOldSession()` and `readOldIdentityBar()`, which reads the tab frame's identity bar through `left_nav.loadFrame` without reloading main.php.
- Unit fixtures: Patient resources for TP-TYPICAL, TP-DECEASED and TP-ESCAPING, recorded from the dev stack.

### Decisions
- Added jsdom 30 and Testing Library for component and hook tests (per-file `@vitest-environment jsdom`).
- The hook test that first ran out of memory built a new client on every render. The app creates one client, and so do the tests now.
- The react-hooks lint rule forbids a synchronous `setState` in an effect. Instead of resetting to "loading", the hook tags results by patient id, which is also the safer design.

### Tests
- Unit: 75 / 75 passing (mapper 5, hook 4, card 2, config 2 more, path 2)
- Playwright: 7 / 7 passing, including header parity for all seven fixtures against the old identity bar, and the rejected-session E2E
- Lint, typecheck and Prettier: clean
- Seen failing first: mapper, hook, card and config tests (modules missing); header parity (reader first, then app). The rejected-session E2E and the patient-switch hook test were proven by breaking the code (the header rendered while signed out; the hook returning its last result), and header parity by changing the DOB wording.

### BUGS-MITIGATIONS.md updates
- BM-004: resolved (the hook rejects a Patient with another id; the API client's `assertBelongsTo` covers the clinical cards).
- BM-005: resolved (status from deceasedDateTime; pinned on TP-DECEASED).

### Open questions / follow-ups
- `deceasedDateTime` is converted to UTC by OpenEMR (`getLocalDateAsUTC`), and the header takes the date part. That's correct on this UTC dev stack, but a server in a time zone ahead of UTC could shift the death date by one day. Check before the droplet deploy.
- `date_display_format` is 0 (Y-m-d) here, and the header prints ISO dates. Other formats aren't ported.

## 2026-09-26 — Arc 02 / Story 02-01 / Slice 02-01-01 — Age rules

**Branch:** `dashboard-migration`
**Status:** ready-for-commit

### Worked on
- `web/src/mappers/age.ts`:
  - `ageDisplay(birthDate, asOf, {format, limitYears})`, a port of `PatientService::getPatientAge`, `getPatientAgeYMD` (with its fixed month lengths) and `getPatientAgeDisplay`
  - `ageAtDeath(birthDate, deathDate)`, a port of `oeFormatAge`, format 0
- Test oracle: `tmp/age_oracle.php` (gitignored) runs OpenEMR's own functions in the dev container, run as the apache user because OpenEMR refuses CLI scripts as root. Its output is saved as `tests/unit/mappers/age.oracle.json`, so the port is pinned to real PHP output.

### Decisions
- Living ages match OpenEMR exactly, including "8 month" and "24 month" (getPatientAge only switches to years above 24 months).
- Age at death returns the intended "4 months" / "1 month" rather than OpenEMR's "4months" (new BM-040, approved exception). oeFormatAge switches to years at 24 months, which is kept.

### Tests
- Unit: 60 / 60 passing (age adds 28: 10 living oracle cases, 10 YMD oracle cases, named edge cases)
- Lint, typecheck and Prettier: clean
- Seen failing first: the age tests, with the module missing.

### BUGS-MITIGATIONS.md updates
- BM-007: resolved (age rules ported and pinned to OpenEMR output).
- BM-040: new row (oeFormatAge spacing bug).

### Open questions / follow-ups
- The header mapper (02-01-02) reads `age_display_format` and `age_display_limit` from the BFF's `/app-config` (this stack: 0 and 3).

## 2026-09-26 — Arc 01 complete: Foundation and auth

**Slices:** 6 (01-01-01, 01-02-01, 01-02-02, 01-03-01, 01-04-01, 01-04-02)  **BM rows resolved:** BM-029 (BM-004 carried to 02-01-02)

### Retrospective
- What worked: the spike code carried straight over into the BFF. Writing each test first and, where code had come first, proving the test by breaking the code caught nothing wrong but gave real confidence. The SPKI-pinned certificate works in Playwright's Chromium, and a real OpenEMR login runs in about 3 s.
- What didn't: the host was missing `libnss3` (the user installed it). Two OAuth logins at the same moment made OpenEMR reject one token exchange, so the browser tests run serially.
- Carried forward to Arc 02: BM-004's strike-through and the rejected-session E2E (they need the first data-fetching card), and `tests/support/newApp.ts`.

## 2026-09-26 — Arc 01 / Story 01-04 / Slice 01-04-02 — Hidden-cards configuration

**Branch:** `dashboard-migration`
**Status:** ready-for-commit

### Worked on
- `web/src/app/hiddenCards.ts`: `CARD_KEYS` (the five old `hide_dashboard_cards` keys plus `card_encounter_history`) and `visibleCards()`.
- `server/appConfig.ts`: `loadAppConfig()` reads `config/hidden-cards.json` (or `HIDDEN_CARDS_FILE`). A missing file hides nothing, and an unknown key stops start-up. The BFF serves the result at `GET /app-config`, so a site can change it without rebuilding the SPA.

### Decisions
- The config is read at runtime by the BFF, not bundled at build time, so sites can edit it on the droplet.

### Tests
- Unit: 32 / 32 passing (6 new)
- Playwright: 5 / 5 passing
- Lint, typecheck and Prettier: clean
- Seen failing first: both new test files, with the modules missing.

### BUGS-MITIGATIONS.md updates
- None.

### Open questions / follow-ups
- The cards (ARC-02 onwards) read `/app-config` through `visibleCards()`.

## 2026-09-26 — Arc 01 / Story 01-04 / Slice 01-04-01 — Parity harness (old dashboard reader)

**Branch:** `dashboard-migration`
**Status:** ready-for-commit

### Worked on
- `tests/support/oldDashboard.ts`: `openOldDashboard(browser, pid)` logs in to http://localhost:8300 in its own browser context (the OpenEMR and BFF cookies share the host `localhost`), accepts clinical-reminder alerts and opens the dashboard. `readOldCard(page, cardId)` returns items (list rows or table rows, collapsed cards included), tooltips, highlighted text, the collapsed flag and the body text, all with whitespace collapsed.

### Decisions
- `tests/support/newApp.ts` moves to slice 02-01-02: there is no new card to read yet, and writing it now would be code without a test.
- Whitespace is collapsed in everything read, so the old "Peanuts Reaction:  -" compares as "Peanuts Reaction: -".

### Tests
- Parity: 2 / 2 passing (TP-TYPICAL allergies with tooltips; collapsed medications card with 4 items)
- Full suite: unit 26 / 26, Playwright 5 / 5 (e2e 3, parity 2)
- Lint, typecheck and Prettier: clean
- Seen failing first: the harness spec, with `oldDashboard` missing.

### BUGS-MITIGATIONS.md updates
- None.

### Open questions / follow-ups
- None.

## 2026-09-26 — Arc 01 / Story 01-03 / Slice 01-03-01 — API client, LoadError and the patient check

**Branch:** `dashboard-migration`
**Status:** ready-for-commit

### Worked on
- `web/src/api/client.ts`:
  - `createApiClient()` with `getResource` and `getBundle`, returning `Result<T>`
  - 401 calls `onUnauthenticated` (by default it goes to `/auth/login`)
  - a thrown fetch becomes `network`, and any other non-2xx becomes `http` with its status
  - a non-Bundle response or an OperationOutcome becomes `invalid-response`
- `assertBelongsTo(patientId, resources)`: a mismatched or missing patient reference becomes a `wrong-patient` LoadError (BM-004 guard).

### Decisions
- Moved the E2E "rejected session shows the login page, not empty cards" to slice 02-01-02: no card fetches data before the header exists, so there's nothing to show empty yet. BM-004 is struck through there, when the guard is first used.
- Reworded BM-004's mitigation for the chosen backend-for-frontend (a deferred minor from the final review).

### Tests
- Unit: 26 / 26 passing (the client adds 9)
- Lint, typecheck and Prettier: clean
- Seen failing first: the client tests, with the module missing.

### BUGS-MITIGATIONS.md updates
- BM-004: mitigation reworded; not yet struck through (see Decisions).

### Open questions / follow-ups
- None.

## 2026-09-26 — Arc 01 / Story 01-02 / Slice 01-02-02 — Allow-listed FHIR proxy

**Branch:** `dashboard-migration`
**Status:** ready-for-commit

### Worked on
- `server/fhirProxy.ts`, mounted at `/api/fhir/*`:
  - GET only (anything else gets 405)
  - paths must be `Resource` or `Resource/id` with an allow-listed type (Patient, AllergyIntolerance, Condition, MedicationRequest, CareTeam, Practitioner, RelatedPerson, Encounter), otherwise 400
  - 401 without a logged-in session
  - the token is refreshed through `ensureFreshToken`
  - `_include` and `_revinclude` are stripped
  - 20 s timeout, 502 on upstream failure
  - `cache-control: no-store`
- Test support: `tests/support/login.ts` (real OpenEMR login) and `tests/support/fixtures.ts` (fixture ids). `login.spec.ts` now uses the shared login helper.

### Decisions
- Dot-segment traversal (plain or `%2e`) is resolved by URL parsing before routing and lands outside `/api/fhir` (404). The test asserts "never forwarded, never 2xx" rather than a specific 400.
- Playwright runs with one worker: two OAuth logins for the same dev user at the same moment made OpenEMR reject one token exchange (HTTP 400). Serial runs are stable (3 of 3).

### Tests
- Unit: 17 / 17 passing (the proxy adds 6)
- E2E: 3 / 3 passing (smoke, login and logout, CareTeam contract)
- Lint, typecheck and Prettier: clean
- Seen failing first: all 6 proxy unit tests before `fhirProxy.ts` existed. The contract E2E was proven by turning stripping off: `_include` returned an empty Bundle (BM-029), so the test failed, then passed once stripping was restored.

### BUGS-MITIGATIONS.md updates
- BM-029 (`_include` empty Bundle): resolved by stripping `_include` and `_revinclude` in the proxy, with a contract test.

### Open questions / follow-ups
- None.

## 2026-09-26 — Arc 01 / Story 01-02 / Slice 01-02-01 — BFF login, callback, session and logout

**Branch:** `dashboard-migration`
**Status:** ready-for-commit

### Worked on
- `server/oauth.ts`: the OpenEMR token client (authorization code with PKCE, refresh; HTTP Basic client auth; 15 s timeout; validates the token response).
- `server/session.ts`: in-memory `SessionStore` with random ids, a sliding idle timeout, `sweep()` for abandoned logins, and `ensureFreshToken()`, which refreshes within 60 s of expiry.
- `server/auth.ts`: `GET /auth/login` (a new session per attempt, PKCE S256, `aud` set), `GET /auth/callback` (one-use state), `POST /auth/logout` (204, cookie cleared) and `GET /auth/me`. The cookie `pd_sid` is HttpOnly and SameSite=Lax, plus Secure when `PUBLIC_URL` is https.
- `server/config.ts`: fails fast on missing environment. The app's own confidential client, "Patient Dashboard Spike (app)", is registered with redirect `http://localhost:5180/auth/callback` (the `app` kind added to `spike/register-client.mjs`) and enabled on the dev database. Its id and secret are in the gitignored `patient-dashboard/.env`, and the dev certificate is in the gitignored `patient-dashboard/certs/`.
- SPA shell: "Log in with OpenEMR" when signed out, "Log out" when signed in.

### Decisions
- Included the final review's deferred point for this stage: Secure cookie over HTTPS, idle session timeout with a sweep, and POST-only logout.
- A new session id for every login attempt, so an id set before login is never reused after it.
- Playwright uses `http://localhost:5180` so the cookie host matches the OAuth redirect, and checks health on 127.0.0.1 because the BFF binds IPv4.

### Tests
- Unit: 11 / 11 passing (health 1, auth 5, session 5)
- E2E: 2 / 2 passing, including a real OpenEMR login and logout through the consent page, with Chromium trusting only the dev certificate by SPKI hash
- Lint, typecheck and Prettier: clean
- Seen failing first: the unit tests on the missing modules. `sweep` and the login E2E were written alongside their code, so each was proven by breaking the code on purpose (a stubbed sweep, a no-op logout), watching the test fail, then restoring.

### BUGS-MITIGATIONS.md updates
- None (BM-029 and BM-004 are in slices 01-02-02 and 01-03-01).

### Open questions / follow-ups
- Sessions are in memory, so a BFF restart logs everyone out. That's acceptable for the demo; a shared store would be needed for more than one instance.

## 2026-09-26 — Arc 01 / Story 01-01 / Slice 01-01-01 — Scaffold patient-dashboard

**Branch:** `dashboard-migration`
**Status:** ready-for-commit

### Worked on
- Created `patient-dashboard/`: React 19 + TypeScript SPA under `web/` (Vite 8), Hono BFF under `server/` (`createApp()` in `server/app.ts`, entry `server/index.ts` on 127.0.0.1:5180), Vitest, Playwright, ESLint (typescript-eslint strict and react-hooks) and Prettier.
- The BFF serves `GET /healthz` and the built SPA from `dist/web`, with an index.html fallback for client routes. The Vite dev server (port 5181) proxies `/auth` and `/api` to the BFF.

### Decisions
- TypeScript `~6.0.3`: typescript-eslint 8.70 supports TypeScript below 6.1, and TypeScript 7 is the new native compiler.
- Playwright pinned to 1.62.1, which matches the Chromium build already cached on the host (revision 1234).
- Formatting follows the repo's `.editorconfig` (4-space indentation).

### Tests
- Unit: 1 / 1 passing (`tests/unit/server/health.test.ts`)
- E2E: 1 / 1 passing (`tests/e2e/smoke.spec.ts`)
- Lint, typecheck and Prettier: clean
- Both tests were seen failing first: missing `server/app`, and the build could not resolve `web/index.html`.

### BUGS-MITIGATIONS.md updates
- None.

### Open questions / follow-ups
- The host needed `libnss3` for Playwright's Chromium; the user installed it on 2026-09-26.
- npm 11 did not run esbuild's postinstall script; `tsx` and Vite work without it.

## 2026-09-26 — Planning phase complete (Tasks 0–18 of the docs plan)

**Branch:** `dashboard-migration`
**Status:** ready-for-commit

### Worked on
- Auth spikes against the dev stack (`API-SPIKE.md`): the browser-only public client and the backend-for-frontend.
- Seven synthetic test patients plus 34 encounters (`TEST-PATIENTS.md`, `fixtures/`).
- Inventory of the old dashboard (`INVENTORY.md`), and seven field-level module audits (`modules/`).
- Bug catalogue: 39 rows (`BUGS-MITIGATIONS.md`). BM-036 to BM-039 were added after the final branch review, to record parity exceptions the audits had found but not catalogued.
- Migration options (`MIGRATION-OPTIONS.md`), the graded defence (`PATIENT_DASHBOARD_MIGRATION.md`), this spec, and five arc files.

### Decisions
- Gate 0: keep both auth options open until Gate 3.
- Gate 1: six full audits and six light reviews; no scope cuts.
- Gate 2a: extra section is Encounter history (the recommendation was Vitals).
- Gate 2: FHIR only with safe workarounds; the header adds sex and a status derived from the death date; two medication cards split on intent; problems shown unless inactive, with resolved ones labelled; allergy risk level; never claim "No Known Allergies"; "Name unavailable" for unresolved names; the parity choices were accepted; a hidden-cards config file.
- Gate 3: Option B (backend-for-frontend) with React + TypeScript (Vite) and a Node BFF, in `patient-dashboard/`, run on the dev stack and the droplet.
- Gate 4: the defence was approved, written in the first person.

### Tests
- Document checkers: every module doc passes `check-module-doc.sh`; the catalogue passes `check-bugs.sh` (39 rows); the options, defence and test-patients docs pass `check-doc.sh`.
- Spikes: A passed through the proxy and failed directly, as expected (CORS preflight 404). B passed.

### BUGS-MITIGATIONS.md updates
- Catalogue created: BM-001 to BM-039. None resolved yet.

### Open questions / follow-ups
- BM-033 (encounter sensitivity through FHIR) was read from the code only; test it with a restricted user if time allows.
- The dev stack's prescription UI is unusable by hand (BM-021, BM-022); prescriptions for fixtures are added through `tmp/seed_rx.php`.
