# Challenge compliance: patient dashboard port

Checked on 2026-09-26, on branch `dashboard-migration` at `336768d`. The check covered every requirement in `AgentForge — Clinical Co-Pilot W2 — Surprise Challenge_ Modernize the Patient Dashboard (1).pdf` and every requirement for `PATIENT_DASHBOARD_MIGRATION.md`. Each status below rests on a test run or a screenshot from this check, not on earlier notes.

## Verdict

Every required feature is built, tested and deployed:
- the login
- the header fields
- the five clinical cards
- the extra section
- the written defence

Two things are still missing:

1. **The page does not look like the old dashboard, and the header is not persistent.** The challenge says to reimplement the interface, not redesign it. The new page shows every card's data correctly, but as plain headings and bullet lists in one column. The patient header scrolls away on a long chart. See [Gap 1](#gap-1-the-look-and-the-persistent-header).
2. **The work is not in the submitted repo yet.** `PATIENT_DASHBOARD_MIGRATION.md` and `patient-dashboard/` exist only on the local branch `dashboard-migration`. That branch is not pushed to GitLab or GitHub, and `main` doesn't include it. See [Gap 2](#gap-2-the-defence-is-not-in-the-submitted-repo).

## Requirements

| # | Requirement (challenge wording) | Status | Evidence |
|---|---|---|---|
| 1 | Port the dashboard to a modern framework | Met | React 19 + TypeScript (Vite) in the browser, with a Hono (Node) backend-for-frontend, in `patient-dashboard/` |
| 2 | "consuming OpenEMR's existing REST and FHIR API as your data layer" | Met | Every read goes through the BFF (`server/fhirProxy.ts` for FHIR, `server/listDates.ts` for three Standard REST API lists). The app has no database access. |
| 3 | "You are not touching the backend" | Met | No dashboard commit changes a file outside `patient-dashboard/`, `clinical_copilot_week2/`, `PATIENT_DASHBOARD_MIGRATION.md` or `.gitignore`. The other files on the branch come from earlier Co-Pilot commits, not the port. Two settings were changed on the droplet: OpenEMR's `site_addr_oath` global, and enabling the registered API clients. Both were done by SQL rather than the admin screens and are written down in `patient-dashboard/deploy/README.md`. |
| 4 | "You are not redesigning the interface" | **Not met** | The content and wording follow the old cards, but the layout and look do not. See Gap 1. |
| 5 | Authentication: login via OAuth2/OpenID Connect | Met | Authorization-code flow with PKCE (S256) through a confidential client; the requested scope includes `openid`. Tokens stay on the server, and the browser holds only an HttpOnly session cookie. Tests: `tests/e2e/login.spec.ts` and `session.spec.ts`, plus the deployed login test. The ID token is received but not used, because the page never shows the signed-in user's identity. |
| 6 | Patient header: name, date of birth, sex, MRN and active status | Met, except "persistent" | All five fields are shown. The parity test (`tests/parity/header.spec.ts`) matches name, MRN and DOB with age against the old identity bar for all seven test patients. Sex and status are unit-tested, and the deployed test checks TP-DECEASED's status. The MRN appears as "(36)" after the name, exactly as the old bar shows it, with no "MRN" label. **The header is not persistent**: it scrolls off the screen, while the old one stays in view (Gap 1). |
| 7 | Allergies, Problem List, Medications, Prescriptions and Care Team, each with live data from the FHIR API | Met, with a documented deviation | All five cards read live data on every patient open. Three cards also read OpenEMR's Standard REST API, because FHIR reports those lists wrongly: FHIR drops some problems, and it marks future-ended medications and allergies as finished. Specifically, the Problem List is built from the standard problem list, Medications from the standard medication list plus FHIR dosage, and Allergies from FHIR plus end dates from the standard list. The defence explains each case (BM-044, BM-047, BM-051), and the challenge's own introduction allows "REST and FHIR". Risk: a grader who reads "from the FHIR API" literally may mark this down. |
| 8 | One additional section of your choice | Met | Encounter history, from FHIR `Encounter`. Parity test: `tests/parity/encounters.spec.ts`. |
| 9 | A working reimplementation | Met | Deployed at https://dashboard.146-190-139-37.sslip.io; 4 of 4 deployed smoke tests pass. |
| 10 | "Feature parity with the original is the standard" | Met for data; not met for look and card controls | 9 of 9 parity tests pass against the running old dashboard. Every difference is an approved exception in `BUGS-MITIGATIONS.md`. The old cards' collapse and expand toggles were not built, although the module audits say the new app would keep its own collapse state (Gap 1). The edit buttons are left out on purpose, because the port is read-only, and the defence says so. |
| 11 | Explain why you chose your framework | Met | `PATIENT_DASHBOARD_MIGRATION.md`, "Why this framework" (four reasons, including the CORS spike that required a server in front of the API) |
| 12 | Explain what you gained by moving away from PHP | Met | "What moving off PHP gained" |
| 13 | Explain what tradeoffs came with that choice | Met | "Tradeoffs and costs", plus "Parity evidence" and "Not ported" |
| 14 | "document your defense in PATIENT_DASHBOARD_MIGRATION.md and put that file in your repo" | **Not yet** | The file is at the repo root on `dashboard-migration`, but no remote has that branch (Gap 2). |

## Checks run for this review

All were run on `336768d` on 2026-09-26.

| Check | Command | Result |
|---|---|---|
| Unit tests | `npm test` | 245 of 245 pass (38 files) |
| Lint | `npm run lint` | clean |
| Types | `npm run typecheck` | clean |
| Formatting | `npx prettier --check .` | clean |
| End-to-end and parity, against the development-easy stack | `npx playwright test` | 35 of 35 pass: 26 end-to-end and 9 parity |
| Deployed smoke tests | `npx playwright test -c playwright.deployed.config.ts` | 4 of 4 pass |
| Deployed health | `GET /healthz` | 200; the container is up and healthy on the droplet |
| Backend untouched | files changed by the dashboard commits on `main..dashboard-migration` | only `patient-dashboard/`, `clinical_copilot_week2/`, the defence and `.gitignore` |
| Visual comparison | screenshots of TP-TYPICAL and TP-LONG in both dashboards at 1400×900 | see Gap 1 |

## Accuracy of `PATIENT_DASHBOARD_MIGRATION.md`

I checked every number and every claim that can be tested against the code and the runs above. Two were wrong and are now corrected:
- **Review count.** The reviews are listed as "Codex three times, Fable twice, Opus once", which adds up to six, but the text says five. The second Codex run hit the usage limit before it produced any findings (`DEV-LOG.md`, slice 03-05-04). The doc now says "Codex twice".
- **Results commit.** The results table named commit `94d82aa`. It now names `336768d`, the commit the suites were re-run on today, with the same counts.

Everything else holds:
- the seven sections
- the data sources
- the approved exceptions (all present in `BUGS-MITIGATIONS.md`)
- the test counts
- the deployed URL
- the "Not ported" list

## Gap 1: the look and the persistent header

**What the old dashboard shows.** Each section is a Bootstrap card: a bordered box with a blue title, an expand or collapse toggle and an edit pencil. At full width, Allergies, Medical Problems and Medications sit side by side in three columns, and Prescriptions and Care Team run full width below them. The identity bar (name with MRN, then DOB and age) sits in OpenEMR's frame above the tabs, so it stays on screen while the dashboard scrolls.

**What the new dashboard shows.**
- **Layout:** one column of plain headings, bullet lists and two tables.
- **Controls:** the browser's default buttons.
- **Header:** its fields run together on one line ("DOB: 1958-03-14 Age: 68 Sex: Female Active"), and it scrolls away: on TP-LONG, with 60 problems and 60 medications, it is off screen after the first scroll.

Bootstrap 4.6 is loaded, but the cards only use it for their tables (`table table-sm`) and muted text. `MIGRATION-SPEC.md` (Tech stack) promised "Bootstrap 4.6 CSS classes: the old dashboard's look, so this isn't a redesign", and the module audits promised a local collapse toggle on each card. Neither was built.

**What fixing it would take.** The change is presentation only; no data or rules change.
- Wrap each card in the old card markup: a title bar and a collapse toggle whose state is kept only in the page.
- Lay the cards out in the old grid.
- Keep the header at the top of the screen (a sticky position), with its fields spaced apart.

The parity and end-to-end tests read the `data-*` labels rather than the layout, so they should keep passing. Two new end-to-end tests would pin the fix: the header stays visible after scrolling TP-LONG, and a card collapses and expands. Rough effort: half a day by hand, well under an hour with Claude Code.

## Gap 2: the defence is not in the submitted repo

- `dashboard-migration` (75 commits ahead of local `main`) has not been pushed to `gitlab`, `gitlab-group` or `origin`, and `main` does not include it.
- Local `main` is also 47 commits ahead of `origin/main`.

Until the branch is merged and pushed, a grader looking at the repo finds neither `PATIENT_DASHBOARD_MIGRATION.md` nor `patient-dashboard/`.

To close it: merge `dashboard-migration` into `main` (the owner merges), then push `main` to the submission remote. The deployed site does not depend on this, because it was built from a copy of the local source.

## Not required by the challenge, but worth knowing

- The challenge does not ask for a demo video. A recording script exists in `DEMO-WALKTHROUGH.md`, which is kept out of git on purpose.
- The approved exceptions, such as "Name unavailable" for providers without an NPI and "Low risk" in place of "Moderate", are all argued in the defence. None is a gap against the challenge, but a grader comparing screens side by side will notice them, so the video should explain one (the walkthrough suggests allergy severity).
