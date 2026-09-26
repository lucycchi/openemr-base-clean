# Migration options

The choices for rebuilding the patient dashboard's presentation layer on OpenEMR's FHIR API. The evidence is `API-SPIKE.md` (both auth spikes), the seven module audits in `modules/`, and `BUGS-MITIGATIONS.md` with its Gate 2 decisions. Two decisions are needed: how the app logs in and holds tokens (the auth architecture), and which framework it's built in.

## Auth architecture

### Option A: browser-only public client (SMART standalone launch, patient-scoped)

- **Proven (Spike A):** PKCE login works from the browser, OpenEMR's patient picker binds the token to one patient, and the token endpoint answers the browser directly.
- **Needs a proxy anyway.** OpenEMR never answers a CORS preflight on FHIR routes (BM-001: `OPTIONS` returns 404). A browser page on another origin can't send an `Authorization` header to the API, so FHIR calls must go through a same-origin reverse proxy in development (Vite `server.proxy`) and in deployment (nginx or similar).
- **One patient per login.** Switching patients means a new launch through the picker. A request for another patient returns an **empty result, not an error** (BM-004), so every card must check the patient reference.
- **Tokens in the browser** (memory only), with no refresh token unless `offline_access` is granted to a public client.
- **Public client**, so no admin approval was needed on this stack.

### Option B: backend-for-frontend (confidential client, user-scoped)

- **Proven (Spike B):** one login read two patients. Tokens stayed in server memory, and the browser held only an HttpOnly session cookie. A refresh token was issued (`offline_access`).
- **No CORS problem:** the browser only talks to its own origin, and the BFF calls FHIR server-to-server.
- **Patient switching needs no new login.** BM-004 doesn't arise: `user/` scopes don't bind a patient, so a request returns the patient asked for.
- **Costs:**
  - a server process to run, which holds the client secret, the session store and the refresh logic
  - an admin must enable the confidential client (it registers disabled)
  - the BFF must restrict which FHIR paths it forwards (the spike's allow-list)

**Net:** Option A isn't "no server": it still needs a same-origin proxy. Option B's server is only slightly more code than that proxy, and it removes the patient-binding trap and keeps tokens out of the browser.

## Route 1: React + TypeScript single-page app (Vite)

- **Framework and language:** React 19 with TypeScript, built by Vite into static files.
- **Auth:**
  - Option A: SMART's `fhirclient` library, or a small PKCE module, plus Vite's dev proxy and a deployment proxy.
  - Option B: the Vite build is served by a small Node BFF (Hono or Express, about 150–250 lines, grown from `spike/bff/server.mjs`), so one container serves both the UI and `/api/fhir/*`.
- **FHIR types and client libraries:** `@types/fhir` for R4 resource types; a thin typed `fetch` client. No heavy FHIR SDK is needed.
- **Testing:** Vitest for mappers and components (Testing Library), and Playwright for the parity and E2E suites.
- **Hosting next to OpenEMR and CORS:**
  - Option B: one small Node container next to the OpenEMR stack. Its memory is small (tens of MB), which matters on the 3.9 GB droplet shared with OpenEMR, MariaDB and the Python Co-Pilot sidecar.
  - Option A: static files plus a reverse proxy.
- **Speed to build in the time left:** high. It's the most common stack, has the most examples, and the spike code carries straight over.
- **Defensibility:** strong. It's typed end to end, the mappers are pure functions and easy to test, and the component model maps one to one onto the old cards.

## Route 2: modern Angular + TypeScript single-page app

- **Framework and language:** Angular 20 with TypeScript.
- **Auth:**
  - Option A: `angular-oauth2-oidc` with PKCE, plus the Angular CLI proxy.
  - Option B: the same Node BFF as Route 1, serving the Angular build.
- **FHIR types and client libraries:** `@types/fhir`; Angular HttpClient with interceptors for 401s.
- **Testing:** Jasmine or Jest for units, Playwright for E2E.
- **Hosting next to OpenEMR and CORS:** same as Route 1.
- **Speed to build in the time left:** medium. There is more boilerplate (modules, DI, RxJS) for six small cards.
- **Defensibility:** medium. OpenEMR already ships AngularJS 1.8 (`interface/main/tabs`), so "the successor to what's there" sounds like continuity. But AngularJS to Angular is a rewrite, not an upgrade, so the continuity is mostly in name.

## Route 3: Vue 3 + TypeScript single-page app

- **Framework and language:** Vue 3 (Composition API) with TypeScript, built by Vite.
- **Auth:** same as Route 1 (a PKCE module or the Node BFF).
- **FHIR types and client libraries:** `@types/fhir`; composables for data loading.
- **Testing:** Vitest with Vue Test Utils, Playwright for E2E.
- **Hosting next to OpenEMR and CORS:** same as Route 1.
- **Speed to build in the time left:** high, similar to React.
- **Defensibility:** good. Single-file components are close to the Twig templates they replace, but the ecosystem and hiring pool are smaller than React's.

## Route 4: Next.js (React) full-stack

- **Framework and language:** Next.js with the App Router, React and TypeScript.
- **Auth:** Option B fits naturally. Route handlers do the OAuth callback and the session, and server components fetch FHIR with the token server-side. Option A is possible but works against the framework.
- **FHIR types and client libraries:** `@types/fhir`; fetch in server components.
- **Testing:** Vitest for mappers, Playwright for E2E. Server components are harder to unit-test.
- **Hosting next to OpenEMR and CORS:** one Node server, but a heavier one (typically 150–300 MB), which is tight on the droplet. It also adds the framework's own build and caching behaviour.
- **Speed to build in the time left:** medium. It's quick to start, but server/client component boundaries and caching defaults cost debugging time.
- **Defensibility:** mixed. It's modern, but moving from server-rendered PHP to server-rendered React is harder to argue as "moving the presentation layer to a better tool".

## Comparison

| Route + auth | Auth fit | FHIR types | Testing | Hosting and memory | Build speed | Defensibility |
|---|---|---|---|---|---|---|
| 1 React + Option B (Node BFF) | High: spike B code carries over | High | High: Vitest + Playwright | High: one small container | High | High |
| 1 React + Option A (proxy) | Medium: proxy plus the BM-004 guard | High | High | High: static + proxy | High | Medium: tokens in the browser, one patient per login |
| 2 Angular + Option B | High | High | Medium | High | Medium | Medium |
| 3 Vue + Option B | High | High | High | High | High | Medium-high |
| 4 Next.js (Option B built in) | High | High | Medium | Low-medium: heavier server | Medium | Medium |

## Recommendation

**Route 1 (React + TypeScript with Vite) with Option B (a small Node backend-for-frontend).**

The main reason is that the spikes settled the architecture. The browser can't call OpenEMR's FHIR API directly (BM-001), so a server sits in front either way. Given that, Option B costs little more than a proxy, and it removes the patient-binding trap (BM-004), keeps tokens out of the browser and provides refresh.

React with Vite is the fastest and most testable way to build six small typed cards in the time left. The BFF is the Spike B server grown up, and one small container keeps the droplet's memory budget intact. Next.js would also give Option B, but it's heavier and harder to defend as a presentation-layer move.

## Decision

Made by the user at Gate 3 (2026-09-26):

- **Auth architecture:** Option B, a backend-for-frontend holding a confidential OAuth client with `user/*.rs` scopes, tokens in server memory, and an HttpOnly session cookie to the browser.
- **Framework:** Route 1, React + TypeScript built with Vite. The Node BFF (Hono or Express) serves the built UI and `/api/fhir/*` from one container.
- **Code location:** a top-level `patient-dashboard/` folder in this repo, separate from the PHP code.
- **Hosting:** developed against the local `development-easy` stack, and deployed next to OpenEMR on the droplet for a live demo.
- **Commits:** as agreed in Task 0, Claude commits each slice to `dashboard-migration`, and the user merges to `main`.
