# Migration spec: patient dashboard on React + TypeScript with a Node BFF

The playbook for building the new patient dashboard chosen at Gate 3 (`MIGRATION-OPTIONS.md`, Decision). It sets out the target architecture, the per-slice loop, the documents that must stay current, and the Arc → Story → Slice roadmap. It is written so that an implementer, human or agent, can pick up at any slice without losing context. It is modelled on [MIGRATION-DOTNET.md](https://github.com/decagondev/vb6-rework-reverse-forward/blob/main/docs/MIGRATION-DOTNET.md) and scaled to a one-week build.

## Contents

1. Purpose and scope
2. Target architecture
3. Project structure
4. Tech stack and rationale
5. Coding standards
6. Test strategy
7. Required documentation artifacts
8. Git workflow
9. The slice loop
10. Epic / Story / Slice hierarchy
11. Roadmap and critical path
12. Templates
13. Definition of Done

## Purpose and scope

This file is the single source of truth for **how** the port is built. The **what** lives in companion documents:

- `modules/*.md`: what each section shows, field by field, with its FHIR mapping. The per-card spec is here.
- `BUGS-MITIGATIONS.md`: 35 catalogued problems, each with a port action, plus the **Gate 2 decisions** section. Every `fix in the new app` row is assigned to a slice in an arc file.
- `API-SPIKE.md`: exact API behaviour, including the error bodies that the error-state tests match.
- `TEST-PATIENTS.md` with `fixtures/fixture-ids.json` and `fixtures/encounter-ids.json`: the fixtures every test uses.
- `arcs/ARC-0N-*.md`: stories, slices and acceptance tests.
- `DEV-LOG.md`: the running journal.

**In scope:**
- the patient header (name, MRN, DOB and age, sex, and a status derived from the death date)
- the Allergies, Problem List, Medications, Prescriptions and Care Team cards, and the Encounter history section, all read-only and fed by the FHIR R4 API
- login through the BFF

**Out of scope:**
- any change to the OpenEMR PHP backend, schema or API. The backend plays the part the reference calls `ORIGINAL-CODE/`: frozen, read, never edited.
- edit workflows
- the cards listed as not ported in `PATIENT_DASHBOARD_MIGRATION.md`

## Target architecture

### One container, two halves

```
Browser (React SPA)  ──same origin──►  BFF (Node, Hono)  ──HTTPS + Bearer──►  OpenEMR FHIR R4 / OAuth2
  cards ← hooks ← api client             /auth/*   login, callback, logout, session
  (no tokens, HttpOnly cookie only)      /api/fhir/*   allow-listed GET proxy
                                         /*   serves the built SPA
```

- **The BFF** is the Spike B server, rebuilt in TypeScript. It holds the confidential client, runs the authorization-code-with-PKCE flow, keeps access and refresh tokens in a server-side session, and refreshes before expiry. It forwards only allow-listed `GET` requests (`Patient`, `AllergyIntolerance`, `Condition`, `MedicationRequest`, `CareTeam`, `Practitioner`, `RelatedPerson`, `Encounter`). It never forwards `_include`, which returns an empty Bundle (BM-029). It never exposes a token to the browser.
- **The SPA** has four layers, with one-way dependencies:

| Layer | Folder | Rule |
|---|---|---|
| API client | `web/src/api/` | the only code that calls `fetch`. It returns `Result<T, LoadError>`, turns 401 into a re-login, and treats an unexpected empty Bundle, when a count was expected, as a `LoadError` |
| Mappers | `web/src/mappers/` | pure functions from FHIR resources to view models. No React, no fetch. This is where parity lives, and it has the most unit tests |
| Hooks | `web/src/hooks/` | one per card: fetch, map, and expose `{ status: 'loading' \| 'error' \| 'ready', data }` |
| Cards | `web/src/cards/` | components that render a view model. They never read raw FHIR |

- **Patient safety rule:** every card checks that each resource's patient reference equals the header patient's id. A mismatch renders a load error, never an empty card (BM-004).
- **Empty versus error:** a card shows its empty-state wording only for a successful response with no qualifying entries. Any failure shows "Couldn't load <card>", with a retry option.

### Modular by card

Adding or changing a card touches one file per layer: `api/allergies.ts`, `mappers/allergies.ts`, `hooks/useAllergies.ts` and `cards/AllergiesCard.tsx`, plus its tests.

## Project structure

```
patient-dashboard/
├── package.json            scripts: dev, build, test, test:e2e, test:parity, lint, typecheck
├── tsconfig.json           strict, noUncheckedIndexedAccess
├── vite.config.ts          dev server proxies /auth and /api to the BFF
├── playwright.config.ts    projects: e2e, parity
├── config/
│   └── hidden-cards.json   mirrors hide_dashboard_cards (Gate 2)
├── server/                 BFF (Hono on Node 24)
│   ├── index.ts            wiring, static files
│   ├── auth.ts             login, callback, logout, refresh
│   ├── session.ts          in-memory session store, HttpOnly cookie
│   ├── fhirProxy.ts        allow-listed GET proxy
│   └── config.ts           env parsing (OEMR_BASE, client id and secret, cookie secret)
├── web/src/
│   ├── api/  mappers/  hooks/  cards/  app/
│   └── main.tsx
└── tests/
    ├── unit/               Vitest: mappers, api client, BFF units
    ├── e2e/                Playwright: login, errors, switching
    ├── parity/             Playwright: old dashboard vs new app, one spec per section
    └── support/            fixture ids, old-dashboard reader, cert trust
```

## Tech stack and rationale

| Concern | Choice | Why |
|---|---|---|
| Runtime | Node 24 | already on the host (`~/.nvm`); one runtime for the BFF and the tooling |
| Language | TypeScript 5, strict | FHIR field access is type-checked (`@types/fhir`) |
| UI | React 19 + Vite | fast builds; one component per card |
| BFF | Hono | small, typed, standard `fetch`, easy to test |
| FHIR types | `@types/fhir` (R4) | no heavy SDK needed |
| Unit tests | Vitest + Testing Library | fast; shares Vite config |
| E2E and parity | Playwright | drives both the old dashboard and the new app in one test |
| Styling | Bootstrap 4.6 CSS classes | the old dashboard's look, so this isn't a redesign |
| Container | one Node image | fits the 3.9 GB droplet next to OpenEMR, MariaDB and the Co-Pilot sidecar |

## Coding standards

- TypeScript `strict`, `noUncheckedIndexedAccess`; `any` is not allowed. Narrow FHIR fields explicitly.
- Mappers are pure and total: every optional FHIR field has a defined fallback, named in the module doc.
- FHIR narrative (`text.div`) is **never** rendered as HTML. It's parsed with `DOMParser` and read through `textContent` (BM-009).
- No `dangerouslySetInnerHTML` anywhere.
- One exported component per file, named after the file.
- ESLint (typescript-eslint, react-hooks) and Prettier; `lint` and `typecheck` must be clean.
- Server logs never include tokens, secrets or patient names; log FHIR ids only.

## Test strategy

Every slice ends with these gates green:

1. **Unit (Vitest).** Each mapper is tested against recorded fixture JSON, captured from the dev stack with `spike/fhir-get.mjs`, covering missing fields, ordering and every Gate 2 decision. The API client is tested for 401 → re-login, network error → `LoadError`, and unexpected empty → `LoadError`. The BFF is tested for the proxy allow-list, `_include` stripping, and session handling.
2. **Parity (Playwright, one spec per section).**
   - The test opens the old dashboard at **http://localhost:8300**, logs in, dismisses clinical-reminder alerts, opens the fixture patient, and reads each field listed in the module doc's section 2, including tooltips and the highlight class. It then does the same in the new app and compares **field by field**.
   - **Each parity spec compares only the fields its module doc marks as ported**, with an explicit field list at the top of the spec. Fields the audits found unavailable in FHIR are not compared; they're listed as approved exceptions.
   - Approved exceptions, by BM id: BM-011, 012, 015, 017, 019, 020, 023, 024, 028, 030, 032, 035, 036, 037, 038, 039. Each spec lists the ids that apply to its section (see the arc files).
   - Every section runs against `TP-TYPICAL`, `TP-EMPTY`, `TP-HISTORY`, `TP-LONG` and `TP-ESCAPING`, plus `TP-NKA` for allergies and `TP-DECEASED` for the header.
3. **E2E (Playwright).**
   - login and logout
   - a rejected or expired session goes back to login and never shows empty cards (match the 401 bodies in `API-SPIKE.md` Error responses)
   - an API failure, simulated by stopping the BFF upstream, shows "Couldn't load" on every card
   - switching patients: nothing from the previous patient stays on screen, and every resource on every card references the header patient
   - `TP-LONG` shows all 60 problems, 60 medications, 25 allergies, and 20 encounters plus "Show all" for 30
4. **Regression:** the full unit, parity and E2E suite runs before each slice is marked ready.

**TLS in tests:** the BFF trusts OpenEMR's dev certificate through `NODE_EXTRA_CA_CERTS`. Playwright's Chromium trusts exactly that certificate through `--ignore-certificate-errors-spki-list=<SPKI hash of dev-cert.pem>`, computed in `tests/support`. Verification is never switched off globally.

**Fixtures are shared state.** Tests only read the dev database. If a slice needs new data, it adds it to `TEST-PATIENTS.md` with the seeding step.

## Required documentation artifacts

- **`DEV-LOG.md`:** one entry per slice, newest first, using the template below. It's what makes resuming after a context loss possible.
- **`arcs/ARC-0N-*.md`:** written before the arc starts; tick slices off as they complete.
- **`BUGS-MITIGATIONS.md`:** when a slice resolves a row, wrap its Citation in `~~` and add *(Resolved in slice NN-NN-NN, YYYY-MM-DD.)* to the Mitigation. Never delete rows. Run `tools/check-bugs.sh` afterwards.
- **`PATIENT_DASHBOARD_MIGRATION.md`:** ARC-05 fills in its parity results table and updates the tense once the app exists.

## Git workflow

- Work happens on the `dashboard-migration` branch (Task 0 agreement). Claude commits each slice there, and the user reviews and merges to `main`.
- Each slice gets at least one commit, in Conventional Commits form with scope `dashboard` (for example `feat(dashboard): allergies card with FHIR mapper`), the `Assisted-by: Claude Code` trailer, and the session's `Co-Authored-By` line.
- The pre-push hook runs; don't bypass it. Never force-push, and never rewrite history the user has pulled.
- Tag arc completion on the branch as `dashboard-arc-0N-complete`, as a record only.

## The slice loop

Every slice, in every arc, is built inline with `superpowers:test-driven-development` (user decision, 2026-09-26).

1. **Pick** the next unticked slice in the current arc file. Read this spec, the arc file, the last three DEV-LOG entries, the module doc for the card, and the BM rows the slice lists.
2. **Plan** in five to ten lines in a scratch note (not committed): goal, files, tests, BM rows.
3. **Test first:** write the mapper unit tests and the parity spec for the slice's fields; run them and watch them fail.
4. **Build** the smallest code that passes: API function, mapper, hook, card.
5. **Run the gates:** `npm run lint && npm run typecheck && npm test && npm run test:parity -- <section> && npm run test:e2e`.
6. **Update docs:** DEV-LOG entry, arc checkbox, BM rows struck through.
7. **Commit** on `dashboard-migration`, and report the slice id, test counts and files changed.

If a slice grows past about 4 hours or 400 lines of meaningful diff, stop, split it in the arc file, and log why.

## Epic / Story / Slice hierarchy

| Level | Size | Lives in |
|---|---|---|
| Arc | up to about 1.5 days | `arcs/ARC-0N-*.md` |
| Story | one user-visible capability | a section in the arc file |
| Slice | 2–4 hours, one vertical piece (for example mapper + hook + card + parity + error state for one card) | a subsection in the story |

Slice ids are `NN-NN-NN` (arc, story, slice).

## Roadmap and critical path

| Arc | Content | Estimate |
|---|---|---|
| ARC-01 Foundation and auth | scaffold, BFF auth and session, API client and error states, parity harness, hidden-cards config | 10 h |
| ARC-02 Header | header mapper and card (including age rules and status), patient switching | 5 h |
| ARC-03 Clinical cards | Allergies, Problem List, Medications and Prescriptions (one story, shared mapper), Care Team | 14 h |
| ARC-04 Encounter history | the encounter card with provider names and "Show all" | 4 h |
| ARC-05 Deploy, defence and demo | droplet deploy, full parity run and results table, defence update, demo walkthrough | 6 h |
| Buffer | problems found while building (the Gate 2 areas are the likeliest) | 4 h |
| **Total** | | **about 43 h, or 5 to 6 working days** |

### Critical path

ARC-01 (the auth and parity harness everything depends on) → ARC-02 header → ARC-03 cards → ARC-05 deploy and parity run. ARC-04 can run in parallel with the second half of ARC-03.

**If time runs short, cut in this order:**
1. the "Show all" toggle on encounters (keep the 20 most recent)
2. the hidden-cards config (show all cards)
3. per-field tooltip parity (keep text parity)
4. the droplet deploy (demo on the dev stack)

The required sections and the defence are never cut.

## Templates

### Arc file

```markdown
# ARC-0N — <Name>

**Status:** planned | in-progress | complete
**Estimate:** <hours>

## Goal
<one paragraph>

## Out of scope
<what this arc leaves to another arc>

## BUGS-MITIGATIONS items resolved
- BM-NNN — <short title>

## Stories

### Story 0N-01 — <title>
**Acceptance:** <observable behaviour>

#### Slice 0N-01-01 — <title>
- [ ] Tests first (named below), watched failing
- [ ] Implement
- [ ] Gates green
- [ ] DEV-LOG, arc file, BUGS-MITIGATIONS updated
**Acceptance tests:** <test file :: test name, one per line>
**Resolves:** BM-NNN
**Touches:** <folders>

## Definition of Done for this arc
- [ ] All slices ticked, all listed BM rows struck through
- [ ] DEV-LOG has an arc-completion entry
```

### DEV-LOG entry

```markdown
## YYYY-MM-DD — Arc NN / Story NN-NN / Slice NN-NN-NN — <short title>

**Branch:** `dashboard-migration`
**Status:** ready-for-commit | in-progress | blocked

### Worked on
- ...

### Decisions
- <decision>: <one-line rationale>

### Tests
- Unit: N / N passing
- Parity: N / N passing
- E2E: N / N passing
- Lint and typecheck: clean

### BUGS-MITIGATIONS.md updates
- BM-NNN — resolved by <how>

### Open questions / follow-ups
- ...
```

### Arc completion entry

```markdown
## YYYY-MM-DD — Arc NN complete: <name>

**Slices:** N  **BM rows resolved:** BM-…
### Retrospective
- What worked / what didn't / carried forward
```

## Definition of Done

### Slice
- [ ] Tests written first and seen failing, now green: unit, the parity spec for its fields, and any E2E it names
- [ ] `lint`, `typecheck` and the full `npm test` are clean
- [ ] Every listed BM row is struck through, and `tools/check-bugs.sh` passes
- [ ] DEV-LOG entry added and arc checkbox ticked
- [ ] Committed on `dashboard-migration`

### Arc
- [ ] All slices done; arc Definition of Done ticked; completion entry in DEV-LOG

### Project
- [ ] All five arcs complete
- [ ] Full parity suite green, with approved exceptions only, and the results table in `PATIENT_DASHBOARD_MIGRATION.md`
- [ ] Deployed next to OpenEMR on the droplet with login working, or the cut is recorded
- [ ] Every `fix in the new app` row in `BUGS-MITIGATIONS.md` struck through
