# OpenEMR System Audit — Task List

Hard gate: `./AUDIT.md` must begin with a ~500-word summary of the most
impactful findings, followed by the full findings. All work is recorded first
in `audit-long.md` (every significant finding from every audit); the summary
and final `AUDIT.md` are produced last, from that file.

Conventions used below:

- Every task names **what** to inspect, **how**, and **what to record** in
  `audit-long.md`.
- Findings use a fixed template so they can be ranked at the end:
  `ID | Severity (Critical/High/Medium/Low/Info) | Area | Location (path:line
  or table) | Evidence | Impact | Recommendation`.
- Commands assume the `development-easy` docker stack via `openemr-cmd` (see
  `CLAUDE.md`). Run SQL with `openemr-cmd shell` → `mysql`, or via phpMyAdmin
  at http://localhost:8310/.
- Use `graphify query "<question>"` before grepping when orienting in the
  codebase (`graphify-out/graph.json` exists).

---

## 0. Setup

- [ ] **0.1 Bring up the dev stack.** `cd docker/development-easy && docker
  compose up --detach --wait`; confirm login at http://localhost:8300/
  (`admin` / `pass`). Record OpenEMR version, PHP version, MariaDB version.
- [ ] **0.2 Seed data.** `openemr-cmd import-random-patients 30` (Synthea) if
  the DB is empty. Record patient count, encounter count, and the seed method
  so data-quality findings can be reproduced.
- [x] **0.3 Create `audit-long.md` skeleton.** One H2 per audit (Security,
  Performance, Architecture, Data Quality, Compliance & Regulatory), each with
  `Scope & method`, `Findings`, `Not covered` subsections, plus a top-level
  `Findings register` table using the template above.
- [x] **0.4 Fold existing `AUDIT.md` into `audit-long.md`.** Move the
  Claude Security low-effort scan (F1 `library/ajax/upload.php`, F2
  `portal/lib/paylib.php`, rejected candidates, "what was not scanned") into
  the Security section. Mark F1/F2 as *fixed in commit 859ad84* and note the
  DB-backed test suite has not been run against those fixes.
- [ ] **0.5 Baseline the repo.** Record branch, HEAD SHA, `git status`
  cleanliness, and the `CLAUDE-SECURITY-*` run directories (all remaining
  ones are complete; dead ones were deleted under 1.1.1a).

---

## 1. Security audit

Goal: authentication and authorization risks, data exposure vectors, PHI
handling issues, HIPAA-relevant gaps.

### 1.1 Incorporate automated scans

- [x] **1.1.1a Triage prior scan runs.** Done 2026-09-15. Only
  `CLAUDE-SECURITY-20260915-075916/` completed (low effort, 436 files, F1/F2).
  Four medium-effort attempts stalled immediately after target-file
  enumeration (no candidates, no results): `-091134` and `-094745`
  (interface, library, src/Services — 2859 files), `-100258` (src — 2115
  files), `-094030` (empty, aborted before meta). Root cause: launched via
  the orchestrator subagent, which lacks the `Workflow` tool — not scope
  size. Recorded in `audit-long.md` §1.1; all four dead dirs deleted.
- [x] **1.1.1b Slice A scan.** Done — run `-110901`, 7→5 confirmed, folded into `audit-long.md` as SEC-03..SEC-10. `interface/patient_file`, `interface/super`,
  `library/documents.php` (139 files, medium effort). Focus: IDOR on
  pid/id/doc_id, string-built SQL, XSS in chart pages, upload/download path
  traversal, missing ACL on PHI reads and admin pages. Import confirmed
  findings into the register; list rejected candidates.
- [x] **1.1.1c Slice B scan.** Done — run `-145753`, 1→1 confirmed (HIGH SQLi), folded as SEC-11. `src/Services` + `src/Common/Database`
  (~200 files). Focus: dynamic SQL in search/filter builders, service-layer
  authorization assumptions. This is the data layer any new capability calls.
- [x] **1.1.1d Slice C scan.** Done — run `-145754`, 5→5 confirmed (1 HIGH SQLi, 4 report ACL gaps), folded as SEC-12..SEC-16. `interface/reports` (~150 files). Focus: bulk
  PHI export without ACL, injection in report filters.
- [ ] **1.1.1e Record unscanned areas.** `interface/billing` (beyond
  uploads), `ccdaservice/`, `gacl/` internals, `sql/`, tests, vendored trees
  — state why each was skipped and which manual task (1.3.2, 1.3.3, 1.4.1)
  spot-checks it.
- [x] **1.1.2 Dependency CVEs.** Done 2026-09-15 — folded as SEC-17 (PHP)
  and SEC-18 (JS). `composer audit`: 19 advisories / 5 packages, 4 High
  (guzzle 7.12.1→7.15.2 host bypass; phpspreadsheet 5.8.0 ×3 DoS/SSRF —
  parser DoS reachable via uploads, WEBSERVICE SSRF not), + dompdf/psr7/
  smarty medium/low and 6 abandoned packages. `npm audit --omit=dev`: 5
  moderate shipped (dompurify, jszip, dwv, fflate, validate.js); the 13
  high/critical in full `npm audit` are dev/build-chain only, not shipped.
- [x] **1.1.3 Secrets and config.** Done 2026-09-15 — folded as SEC-19
  (production compose ships `root`/`root` DB + `admin`/`pass` app creds with
  no env indirection) and SEC-20 (dev/CI TLS private `.pem` keys committed;
  not used by production). `sites/default/sqlconf.php` ships `openemr/openemr`
  but `$config=0` (uninstalled template, installer overwrites). No live API
  keys/tokens found; OAuth2 keys are runtime-generated
  (`src/Common/Auth/OAuth2KeyConfig.php`), not committed.

### 1.2 Authentication

- [x] **1.2.1 Login flow.** Done 2026-09-15 — folded into `audit-long.md`
  §1.2b. bcrypt/Argon2/SHA512 via `AuthHash.php`, rehash-on-login; server-side
  lockout (20/account, 100/IP, 1h auto-reset); password min length 9, no
  complexity regex, 180-day expiry + 30-day grace; TOTP/U2F MFA implemented
  (`MfaUtils.php`) but **not wired into the staff web login** — SEC-23.
- [x] **1.2.2 Session management.** Done 2026-09-15 — folded into
  `audit-long.md` §1.2b. No `session_regenerate_id()` on login — SEC-21;
  core/portal cookies `Secure=false` hardcoded, core also `HttpOnly=false` by
  design — SEC-22; idle-only timeout, no absolute cap, no concurrent-session
  limit — SEC-24; portal/staff sessions are cleanly namespace-separated
  (distinct cookie names, `App` selector cookie).
- [x] **1.2.3 API / OAuth2.** Done 2026-09-15 — folded into `audit-long.md`
  §1.2b. Grant types: auth_code, refresh_token, client_credentials, password
  (password grant off by default via `oauth_password_grant`). Refresh
  rotation enforced (library default). Access token 1h, refresh 3mo (ONC
  min). Dynamic client registration unauthenticated, auto-enabled for
  default-scope clients when `oauth_app_manual_approval` is off (default) —
  SEC-25. Scope enforcement centralized in `AuthorizationListener`,
  default-deny; public endpoint allowlist is narrow and explicit.
- [x] **1.2.4 Portal registration and password reset.** Done 2026-09-15 —
  folded into `audit-long.md` §1.2b. Self-registration + reset both use
  CSPRNG tokens, 1h expiry, single-use, anti-enumeration responses. Reset
  *trigger* gated on guessable PII (DOB+name+email), no dedicated rate limit
  beyond reCAPTCHA — SEC-26 (distinct from SEC-06's staff-side ACL bypass).

### 1.3 Authorization

- [ ] **1.3.1 ACL model + `aclCheckCore` gap sweep.** Document `gacl/` +
  `src/Common/Acl/` (`AclMain`): sections, ARO/ACO structure, how
  `acl_check()` / `aclCheckCore()` is called.

  **Why this matters (see `audit-long.md` §1.3.1 for the full write-up):**
  `AclMain::aclCheckCore($section, $value)` (`src/Common/Acl/AclMain.php:166`)
  is OpenEMR's server-side authorization check — deny-by-default, superuser
  always allowed, so adding a missing one is safe. The dominant security
  finding (10 of 16 findings: SEC-03–06, SEC-13–16, and the CSRF-as-authz
  root of SEC-01–02) is that endpoints skip this check and rely on
  `src/Menu/MenuRole.php` hiding the menu link instead — which direct URL
  access bypasses. Any new capability must call `aclCheckCore` itself and
  must never treat a CSRF token or UI reachability as authorization.

  **How to run the sweep (repeatable):**
  1. List candidate entry points: top-level scripts under `interface/`
     (esp. `interface/reports/`, `interface/patient_file/`),
     `library/ajax/`, `apis/routes/*`, `src/RestControllers/*`.
  2. Flag scripts with no check of their own:
     ```bash
     for f in $(git ls-files 'interface/reports/*.php' 'interface/patient_file/**/*.php'); do
       grep -q 'aclCheckCore\|aclCheckIssue\|AccessDeniedException' "$f" || echo "NO-ACL: $f"
     done
     ```
  3. Cross-check the menu's declared requirement — a gap is confirmed when
     `standard.json` declares an `acl_req` the script does not enforce:
     ```bash
     grep -rn '"<script-basename>"' interface/main/tabs/menu/menus/
     ```
  4. Confirm reachability: authenticates only via `globals.php` (login, not
     ACL), renders/exports PHI, note GET vs POST+CSRF.
  5. Record each real gap as SEC-nn with the one-line fix
     (`aclCheckCore(<section,value>)` + `AccessDeniedHelper` at the top,
     before any query); for handlers trusting client-supplied `pid`/
     `encounter`, also scope the write to the session patient.

  The SEC-13–16 findings are confirmed instances; this sweep finds the rest.

  **Done 2026-09-15** — swept `library/ajax/*`, `apis/routes/*`,
  `src/RestControllers/*`. REST layer is clean (centralized
  `AuthorizationListener` PEP, narrow public allowlist). Found 3 new gaps in
  `library/ajax/`: SEC-27 (`person_search_ajax.php`, PHI search+create, no
  ACL), SEC-28 (`upload.php`, document upload/fetch, no ACL — distinct from
  the fixed SEC-01 IDOR), SEC-29 (`addlistitem.php`, unrestricted
  `list_options` writes). ~33 other `library/ajax/*` files flagged NO-ACL by
  the grep but not confirmed as having concrete PHI/state-change impact in
  the time available — flagged for a future follow-up pass, not logged as
  findings.
- [x] **1.3.2 IDOR sweep.** Done 2026-09-15. Confirmed SEC-01/SEC-02 fixes
  are present and correct (`upload.php`, `paylib.php`). New gap: SEC-30 —
  `pnotes.php`/`pnotes_full.php`/`pnotes_full_add.php` derive the acting
  patient from request `docid`/`orderid` rather than session pid; squad ACL
  runs against the derived patient but not a pid-match check, so cross-patient
  note read/write is possible for staff with generic notes-write rights.
  Everything else checked (`portal/report/document_downloads_action.php`,
  `portal/get_patient_documents.php`, `C_Document::retrieve_action`, ~20
  other portal/ajax endpoints) was properly scoped or had no request-supplied
  id at all.
- [x] **1.3.3 Upload handlers.** Done 2026-09-15. `interface/billing/` has no
  upload handler. `interface/super/*`, `library/documents.php`
  (`addNewDocument`, reuses the SEC-01-fixed engine), and
  `library/edihistory/edih_uploads.php` (best-in-class: MIME allowlist +
  extension denylist + null-byte checks) are all safe. Two low-severity gaps:
  SEC-31 (Documents zend module — client-side-only MIME gate, bounded impact)
  and SEC-32 (fax module — no type validation, mitigated by non-webroot
  storage). Carecoordination module has no direct upload handler.
- [x] **1.3.4 Break-glass / emergency access.** Done 2026-09-15 — SEC-33.
  "Emergency Login" is a normal ACL group grantable by any `admin`/`users`
  admin with no justification field or approval workflow; default install
  grants it `admin/super`-equivalent, unscoped, indefinite access; logging is
  generic (no dedicated review UI); no rate limiting/alerting on repeated use.
- [x] **1.3.5 Privilege escalation.** Done 2026-09-15 — SEC-34 (HIGH).
  `interface/usergroup/usergroup_admin.php` correctly blocks a non-superuser
  from self-assigning any group that includes `admin/super` (including
  Emergency Login). The sibling `library/ajax/adminacl_ajax.php`, which
  performs the same group-membership-add operation, omits that check —
  any `admin`/`acl`-privileged non-superuser can add themselves to
  Administrators or Emergency Login and gain superuser access. Globals
  editing (`interface/super/edit_globals.php`) is uniformly gated on
  `admin/super`, no partial-coverage issue found there.

### 1.4 Data exposure vectors

- [x] **1.4.1 Injection.** Done 2026-09-15 — no new confirmed findings
  (folded into `audit-long.md` §1.3d as a coverage note). Sampled
  `library/`, `interface/main|forms|orders|billing|usergroup` for
  string-built SQL: all clean (parameterized or `add_escape_custom()`).
  One dead/unreachable SQLi pattern in a code comment
  (`interface/usergroup/usergroup_admin.php:514-532`), not logged. No
  Smarty templates exist (100% Twig); sampled `|raw` usage in calendar
  templates traces back to pre-escaped `CalendarViewModel` output — safe.
  Explicitly a sample, several areas not covered (see write-up).
- [x] **1.4.2 CSRF.** Done 2026-09-15 — SEC-35 (`search_payments.php`
  `DeletePayments`, POST-only, no CSRF) and SEC-36
  (`ub04_dispose.php`/`ub04_submit.php`, no CSRF **and** GET-triggerable
  write — the more serious gap since it lacks even a POST-only fallback).
  Broad sample of `library/ajax/*` (40 files) and `interface/patient_file/`,
  `interface/usergroup/` found consistent CSRF coverage elsewhere.
- [x] **1.4.3 CORS and headers.** Done 2026-09-15 — CORS conclusion
  re-verified accurate (Origin reflected but never paired with
  `Access-Control-Allow-Credentials` on the real response). New finding
  SEC-37: no CSP/X-Frame-Options/Referrer-Policy outside login/portal entry
  pages; HSTS present only via the Docker image's Apache config, absent for
  non-Docker deployments.
- [x] **1.4.4 Error and log leakage.** Done 2026-09-15. `display_errors`
  correctly `Off` in both dev and prod docker images (same base php.ini,
  no divergence). New finding SEC-38: REST/OAuth/FHIR layers return raw
  `$exception->getMessage()` to clients (traces stay server-side).
  SEC-39: CCDA import logs source filenames that may embed PHI by naming
  convention (unconfirmed, medium confidence).
- [x] **1.4.5 Direct file access.** Done 2026-09-15 — no new findings;
  `sites/*/documents/`, `bin/` have explicit `Require all denied` in the
  Docker Apache config (not relying on obscurity); `sqlconf.php` is
  protected by PHP execution (blank output) rather than an explicit deny,
  flagged as a config-dependent nuance, not a finding; `tmp/`
  (`temporary_files_dir`) is genuinely outside the docroot. Non-Docker/
  bare-metal installs depend on legacy `.htaccess` `Deny From All` syntax
  requiring `mod_access_compat` on Apache 2.4 — noted as a manual-check item
  for non-Docker deployments, not independently verifiable from source.
- [x] **1.4.6 Export and reporting surfaces.** Done 2026-09-15. SEC-40:
  CCDA/QRDA export (`Carecoordination` module) takes `pid`/`pids` from
  request with no visible per-patient ACL check and no rate limit
  (medium confidence — needs runtime confirmation). SEC-41 (INFO): bulk
  FHIR system export has no abuse-rate limiting beyond execution-time
  bounds — acceptable since the scope itself is admin-granted, but an
  operational-control gap. SEC-42: `interface/reports/patient_list.php`
  CSV export lets any authenticated user dump the entire patient list with
  no pagination/rate limit (distinct from SEC-13's ACL gap).

### 1.5 PHI handling

- [x] **1.5.1 Encryption in transit.** Done 2026-09-15 — folded into
  `audit-long.md` §1.3e. TLS terminated in-container via Apache in both
  prod and dev compose files. SEC-43: HTTP→HTTPS redirect present but
  commented out by default. SEC-44: internal LDAP traffic plaintext
  (`ldap://`) despite TLS material provisioned on the LDAP container.
- [x] **1.5.2 Encryption at rest.** Done 2026-09-15. SEC-45 (INFO): DB
  volume has no encryption by default (standard for self-hosted Docker,
  not a code defect). Document encryption (`drive_encryption`, default ON,
  `CryptoGen.php` dual-key architecture) confirmed solid — no finding.
  SEC-46: backup archives (`interface/main/backup.php`) are compressed but
  not encrypted.
- [x] **1.5.3 PHI in non-clinical stores.** Done 2026-09-15. SEC-47
  (HIGH): `email_queue`/`notification_log` store PHI-bearing message
  content plaintext, indefinitely, with no ACL-gated viewer. SEC-48
  (MEDIUM): audit-log viewer (`interface/logview/logview.php`) gates PHI-
  bearing free-text comments with a single coarse `admin/users` ACL, not
  per-patient authorization. SEC-49/50 (LOW): portal one-time-auth caches
  names in session (narrow scope); QRDA/CQM export staging uses predictable
  filenames + `chmod 0777` (contrast with the hardened CCDA export
  pattern). No PHI found in caches (no APCu usage; `background_services`
  is scheduler metadata only, not a payload queue).
- [x] **1.5.4 Third-party egress.** Done 2026-09-15. Inventoried fax
  (RingCentral/EtherFax/SignalWire), SMS (Twilio/Clickatell), email
  (SMTP), X12 clearinghouse (SFTP), HL7 lab orders (file-drop, no direct
  network client found in this repo) — all off-by-default, admin-
  configured. SEC-51 (MEDIUM): SMTP defaults to unencrypted transport with
  an admin-redirectable host. SEC-52 (LOW): Clickatell SMS puts content +
  API key in a GET query string. **No Surescripts/e-prescribing
  integration exists.** **No LLM/AI API integration exists anywhere in the
  shipped codebase** — confirmed by full-repo grep; only match is
  `AI_INTEGRATION_PLAN.md` itself (a planning doc), consistent with that
  plan's own stated premise (relevant to task 6.5's cross-check).

### 1.6 Security section wrap-up

- [x] **1.6.1** Done 2026-09-15 — folded into `audit-long.md` §1.4 "Not
  covered": areas never reached (`ccdaservice/`, `gacl/` internals, `sql/`
  migrations, `tests/`/vendored trees, most of `interface/billing/`, HL7
  network destination), areas sampled-not-exhaustive (`library/ajax/*`
  remainder, most of `interface/forms/*`, calendar/report code, non-Docker
  deployments), and explicit scope exclusions (no dynamic/runtime testing).
- [x] **1.6.2** Done 2026-09-15 — folded into `audit-long.md` §1.5:
  52 findings total, 7 High / 23 Medium / 17 Low / 5 Info, with a note on
  weighting reachability and breadth (the "gate the menu not the handler"
  root cause spans 10+ findings) for the cross-audit ranking in task 6.2.

---

## 2. Performance audit

Goal: where the system is slow, what the bottlenecks are, how data is
structured, and which constraints will affect response latency of any new
service layered on top.

### 2.1 Data structure and volume

- [ ] **2.1.1 Schema inventory.** From `sql/database.sql` (~15k lines) and
  `information_schema`, list the ~30 largest tables by row count and size on
  the seeded DB. Record the core clinical tables (`patient_data`,
  `form_encounter`, `lists`, `prescriptions`, `procedure_result`,
  `form_vitals`, `immunizations`, `documents`, `log`) with row counts.
- [ ] **2.1.2 Index coverage.** For each core table, list indexes and check
  whether `pid`, `encounter`, and date columns are indexed. Record missing or
  redundant indexes.
- [ ] **2.1.3 Storage layout.** Record engine (InnoDB), charset/collation,
  columns stored as TEXT/LONGBLOB (e.g., `documents.document_data`), and any
  EAV-style tables (`layout_options`, `form_*` per-form tables) that force
  many joins for a single chart.
- [ ] **2.1.4 Per-patient chart size.** Measure raw byte size and row count of
  one full chart for the largest, median, and smallest seeded patient. Record
  so downstream consumers know how much data a "full chart read" moves.

### 2.2 Query performance

- [ ] **2.2.1 Enable slow-query log.** Set `long_query_time=0.1` and
  `log_queries_not_using_indexes=ON` in the MariaDB container; exercise the
  app (login, patient search, open a chart with many encounters, open an
  encounter, run a report, hit `/apis/default/api/patient` and a FHIR
  `Patient` search).
- [ ] **2.2.2 EXPLAIN hot queries.** For the top 10 slow-log entries, run
  `EXPLAIN` and record full scans, filesorts, and temp tables.
- [ ] **2.2.3 N+1 patterns.** Review `src/Services/*Service.php` used for
  chart loading (`PatientService`, `EncounterService`, `ConditionService`,
  `PrescriptionService`, `ObservationLabService`, `VitalsService`) and
  `interface/patient_file/summary/` for per-row queries in loops. Record each
  with the loop location.
- [ ] **2.2.4 ORM / DB layer overhead.** Note how `QueryUtils`, ADODB
  surface, and Doctrine DBAL are layered and whether query caching or
  prepared-statement reuse exists.

### 2.3 Request latency

- [ ] **2.3.1 Time key pages and endpoints.** Using `curl -w` or the browser
  network tab against the dev stack, record p50 of: login, patient search,
  patient summary page, encounter page, calendar day view, REST `patient`
  list, FHIR `Patient` search, FHIR `$everything`-style bulk read if present.
- [ ] **2.3.2 PHP runtime config.** Record `opcache` status, `memory_limit`,
  `max_execution_time`, Apache MPM/worker settings in the docker image, and
  whether a PHP-FPM/production config differs from dev.
- [ ] **2.3.3 Frontend weight.** Record total JS/CSS payload on the patient
  summary page (Angular 1.8 + jQuery + Bootstrap bundles) and any
  render-blocking assets.

### 2.4 Background processing constraints

- [ ] **2.4.1 Background services.** Review `background_services` table and
  `library/ajax/execute_background_services.php`. Record: services only run
  while a user is logged in (Ajax-triggered), lease locking
  (`lock_expires_at`), no cron by default, no job queue. This is a hard
  constraint for any after-hours or long-running job.
- [ ] **2.4.2 Caching.** Record any caching layers (none? APCu? file cache?)
  and what is safe to cache given PHI.
- [ ] **2.4.3 Concurrency and locking.** Note table-level locks, long
  transactions, and `esign`/`lock` semantics that could block writes.

### 2.5 Performance section wrap-up

- [ ] **2.5.1** Summarize the top 5 bottlenecks with measured numbers.
- [ ] **2.5.2** List constraints that bound the latency of any new service
  (chart assembly cost, absent job queue, DB round-trips per chart).

---

## 3. Architecture audit

Goal: how the system is organized, where data lives, how layers interact,
and integration points for adding new capabilities.

- [ ] **3.1 Layer map.** Document the three code generations and how they
  call each other: `interface/` (procedural UI), `library/` (legacy helpers),
  `src/` (PSR-4 `OpenEMR\`), `controllers/`, `portal/`, `apis/` + `oauth2/`.
  Use `graphify-out/GRAPH_REPORT.md` and `graphify query` for god nodes and
  community structure; cite them.
- [ ] **3.2 Request routing.** Describe the entry points: direct PHP file
  hits under `interface/`, Laminas MVC modules, REST/FHIR dispatch in
  `apis/dispatch.php` → `src/RestControllers/`, portal entry. Record how
  `globals.php` bootstraps every legacy request and what it loads.
- [ ] **3.3 Where data lives.** Table families in `sql/database.sql` by
  domain (demographics, encounters/forms, clinical lists, orders/results,
  billing, scheduling, documents, users/ACL, audit/log, config/globals,
  layouts/list_options). Record what lives *outside* the DB: documents on
  disk under `sites/<site>/documents/`, `sites/<site>/sqlconf.php`, session
  storage, uploaded files, CCDA artifacts.
- [ ] **3.4 Service layer.** Inventory `src/Services/` (`BaseService`
  pattern, `ProcessingResult`, validators, search). Record which domains have
  a typed service and which are only reachable via legacy `library/` SQL.
- [ ] **3.5 Event system and extension points.** Inventory
  `src/Events/` (Symfony EventDispatcher), module loader
  (`interface/modules/`, `ModuleService`), menu/patient-summary hooks,
  `library/ESign/` (`SignableIF`), `background_services`. For each: what it
  lets you add, what it does not (e.g., no "encounter closed" event).
- [ ] **3.6 Templating and UI stack.** Record Twig vs Smarty vs inline PHP
  usage, where new UI should go, and the Angular 1.8 / jQuery / Bootstrap 4.6
  frontend constraints.
- [ ] **3.7 Auth/session flow diagram.** One diagram (Mermaid) of staff
  login → session → ACL check → page; portal login → portal session; OAuth2
  token → API scope check. Note where the boundaries are enforced.
- [ ] **3.8 Configuration and multi-site.** Record `globals` table,
  `OEGlobalsBag`, `sites/` multi-tenancy, and how config differs dev vs
  production docker.
- [ ] **3.9 Testing and quality gates.** Record test suites
  (`tests/Tests/{Unit,Services,Api,E2e,Isolated}`), PHPStan level 10 +
  baseline size, custom PHPStan rules, pre-commit hooks, CI. Note what is
  *not* covered (e.g., most of `interface/`).
- [ ] **3.10 Integration-point summary.** Table of viable integration points
  for a new capability: mechanism, where to register, auth context available,
  limitations.

---

## 4. Data quality audit

Goal: how complete, consistent, and reliable the data is — missing fields,
inconsistent formatting, duplicates, stale data. All queries run against the
seeded DB; record the SQL alongside the result so it can be re-run on a
production dataset.

Note in the write-up that the seeded data is Synthea-generated and may
understate real-world messiness; flag which findings are schema-level (apply
anywhere) vs dataset-level.

### 4.1 Completeness

- [ ] **4.1.1 Demographics.** For `patient_data`: % null/empty for `DOB`,
  `sex`, `ss`, `phone_home`, `email`, `street`, `postal_code`, `language`,
  `race`, `ethnicity`, `pubpid`. Record which are required by layout config
  (`layout_options.uor`) vs actually populated.
- [ ] **4.1.2 Encounters.** `form_encounter`: missing `reason`,
  `facility_id`, `provider_id`, `pc_catid`, `encounter_type_code`; encounters
  with zero forms attached; encounters lacking a signed note.
- [ ] **4.1.3 Clinical lists.** `lists` (problems/meds/allergies): missing
  `diagnosis` codes, missing `begdate`, active items with `enddate` in the
  past, free-text `title` without a code.
- [ ] **4.1.4 Medications, immunizations, vitals, labs.** `prescriptions`
  (missing `rxnorm_drugcode`, dose, route), `immunizations` (missing CVX),
  `form_vitals` (rows with all-null measurements), `procedure_result`
  (missing units, LOINC, abnormal flag).

### 4.2 Consistency and formatting

- [ ] **4.2.1 Date formats.** Find `0000-00-00`, future dates, DOB after
  encounter date, `date` columns stored as varchar.
- [ ] **4.2.2 Code systems.** Check `lists.diagnosis` prefix mix (`ICD10:`,
  `ICD9:`, `SNOMED-CT:`, none); `prescriptions` RxNorm coverage; LOINC
  coverage on results. Record % coded per system.
- [ ] **4.2.3 Free-text vs coded.** Identify fields where the same concept is
  stored both coded and as free text (e.g., allergies `title` vs `diagnosis`,
  `sex` casing/variants, `status` values outside `list_options`).
- [ ] **4.2.4 Reference integrity.** `list_options` values referenced by
  `patient_data`/`form_encounter` that do not exist in `list_options`;
  `facility_id`/`provider_id` pointing to missing rows.
- [ ] **4.2.5 Units and numeric formats.** Vitals stored in mixed units
  (imperial/metric), non-numeric strings in numeric fields, phone/postal
  formats.

### 4.3 Duplicates and orphans

- [ ] **4.3.1 Duplicate patients.** Same `fname`+`lname`+`DOB`, same `ss`,
  same `email`. Record count and whether a merge tool exists
  (`interface/patient_file/merge_patients.php`).
- [ ] **4.3.2 Duplicate clinical entries.** Same problem/med/allergy listed
  multiple times for one patient; duplicate immunizations same date/CVX;
  duplicate encounters same date/provider.
- [ ] **4.3.3 Orphaned rows.** `lists`, `form_*`, `documents`, `prescriptions`
  with `pid` not in `patient_data`; `forms` rows whose form table row is
  missing; encounters with no patient.

### 4.4 Staleness and lifecycle

- [ ] **4.4.1 Stale records.** Active problems/meds not updated in > N years;
  patients with no encounter in > N years still marked active; appointments
  in the past never marked complete.
- [ ] **4.4.2 Deleted vs soft-deleted.** Which tables use `deleted`/
  `activity` flags vs hard deletes; whether soft-deleted rows still surface in
  services/API responses.
- [ ] **4.4.3 Timestamps.** Which core tables lack `created`/`updated`
  columns (making "how fresh is this" unanswerable).

### 4.5 Data-quality wrap-up

- [ ] **4.5.1** Table of each check: SQL, result, severity, whether it is a
  schema issue or a data issue.
- [ ] **4.5.2** List of failure modes a downstream consumer must handle
  (null DOB, uncoded problems, duplicate patients, etc.).

---

## 5. Compliance & regulatory audit

Goal: HIPAA-focused pass separate from security — audit logging, retention,
breach notification, and BAA implications of sending PHI to an LLM provider.

### 5.1 Audit logging (§164.312(b))

- [ ] **5.1.1 What is logged.** Review `src/Common/Logging/` (`AuditConfig`,
  `EventAuditLogger`, `SystemLogger`) and the `log`, `log_comment_encrypt`,
  `api_log` tables. Record the event categories, whether PHI *views* (not just
  writes) are logged, whether API/FHIR reads are logged, and which globals
  control it (and their defaults — is auditing off by default for any
  category?).
- [ ] **5.1.2 What is not logged.** Grep for direct SQL reads of clinical
  tables in `interface/` and `library/` that bypass the audit logger. Record
  representative gaps (document downloads, report exports, portal views).
- [ ] **5.1.3 Tamper evidence and integrity.** Record whether the log is
  append-only, checksummed (`log_validator`), who can delete/edit it, and
  whether it is exportable to an external SIEM.
- [ ] **5.1.4 Log retention and PHI in logs.** Record any log rotation /
  purge, and whether the audit log itself contains PHI (query text with
  values) that then needs the same protection.
- [ ] **5.1.5 Access review tooling.** Does the UI support "who accessed
  patient X" reports (`interface/reports/audit_log.php`)? Record usability
  and gaps.

### 5.2 Data retention and disposal

- [ ] **5.2.1 Retention policy support.** Record whether any retention
  configuration exists (record age, log age, document age). Note state-law
  medical-record retention typically exceeds HIPAA's 6-year documentation
  rule; the system must not silently purge.
- [ ] **5.2.2 Deletion and de-identification.** Record how patient deletion
  works (`interface/patient_file/deleter.php`), whether it cascades to
  documents on disk, sessions, logs, backups; whether de-identification or
  anonymization exists.
- [ ] **5.2.3 Backups.** Record backup tooling (`interface/main/backup.php`),
  encryption, retention, and restore testing guidance.

### 5.3 Breach notification (§164.400–414)

- [ ] **5.3.1 Detection capability.** Can the system detect unusual access
  (bulk record views, off-hours, break-glass)? Record alerting: none / email /
  log-only.
- [ ] **5.3.2 Scope determination.** Given an incident, can the audit log
  answer "which patients' records were accessed by whom, when"? Test with a
  sample query.
- [ ] **5.3.3 Notification workflow.** Record any built-in support for
  incident tracking or patient notification (likely none) and what an
  operator would have to do manually.

### 5.4 Access controls and minimum necessary (§164.502(b), §164.312(a))

- [ ] **5.4.1 Role granularity.** Map default roles/ACL groups to the data
  they can see. Record whether "minimum necessary" is achievable (e.g., can a
  front-desk role see clinical notes?).
- [ ] **5.4.2 Sensitivity flags.** Record `form_encounter.sensitivity` and
  patient-level restrictions; whether they are enforced in the API and
  exports, not only the UI.
- [ ] **5.4.3 Unique user identification and emergency access.** Confirm
  shared accounts are preventable, and break-glass is logged (ties to 1.3.4).
- [ ] **5.4.4 Patient rights.** Record support for access requests
  (portal record download, CCDA export), amendments, and accounting of
  disclosures.

### 5.5 Transmission and third parties (§164.312(e), §164.308(b))

- [ ] **5.5.1 Existing BAA-requiring integrations.** From 1.5.4, list every
  integration that sends PHI to a third party and note it requires a BAA.
- [ ] **5.5.2 LLM provider implications.** Write up, provider-agnostically:
  sending chart text to an LLM API is a disclosure to a business associate
  and requires (a) a signed BAA with the provider, (b) zero data-retention /
  no-training terms, (c) data residency confirmation, (d) minimum-necessary
  scoping of what is sent, (e) audit logging of each disclosure, (f) a
  de-identification path if a BAA is unavailable (Safe Harbor 18 identifiers
  vs Expert Determination). Record which major providers offer a BAA at the
  time of writing and under which product tiers.
- [ ] **5.5.3 Local vs hosted inference.** Note the compliance trade-off of
  self-hosted models (no BAA needed, but the operator carries all security
  obligations) as an option, without recommending an implementation.

### 5.6 Compliance wrap-up

- [ ] **5.6.1** Map findings to HIPAA Security Rule safeguards
  (administrative / physical / technical) and note which are *technical
  controls missing* vs *operator policy required*.
- [ ] **5.6.2** Rank compliance findings in the register.

---

## 6. Synthesis and final deliverable

- [ ] **6.1 Complete `audit-long.md`.** Every section has Scope & method,
  Findings, Not covered. Every finding in the register has severity,
  location, evidence, impact, recommendation.
- [ ] **6.2 Rank across audits.** Sort the register by impact (severity ×
  reachability × breadth). Pick the top ~8–10 findings that a reader must
  know; these drive the summary.
- [ ] **6.3 Write the one-page summary (~500 words).** Structure: two-sentence
  system description; the top findings grouped by theme (security, data,
  operational constraints, compliance), each with impact in one line; the
  single most important "do this before adding anything" recommendation;
  what was not covered. Cut anything that is not among the most impactful.
  Count the words.
- [ ] **6.4 Assemble `AUDIT.md`.** Summary first, then the full contents of
  `audit-long.md` (or a link plus the full findings — the gate requires all
  findings in `AUDIT.md`, so include them). Keep the existing security section
  content intact within it.
- [ ] **6.5 Review gate.** Re-read for: placeholders, claims without
  evidence, findings already fixed marked as open, PHI accidentally pasted
  from the seeded DB (Synthea data is synthetic, but say so). Cross-check
  that `AI_INTEGRATION_PLAN.md` §2 "repository facts" agree with the audit,
  and note discrepancies for that plan's authors.
- [ ] **6.6 Commit.** `docs(audit): add system audit` with `Assisted-by:
  Claude Code` trailer, on the `audit` branch.
