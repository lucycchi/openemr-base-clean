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

- [x] **0.1 Bring up the dev stack.** Done — stack was already running
  (started earlier session, 17h uptime). OpenEMR 8.2.0, PHP 8.5.6, MariaDB
  11.8.8-MariaDB-ubu2404.
- [x] **0.2 Seed data.** Done — DB already seeded (Synthea, per prior
  session). 30 patients, 1,517 `form_encounter` rows, 1,157 `lists` rows,
  234 prescriptions, 5,605 `procedure_result` rows, 395 immunizations,
  2,268 `log` rows, 0 documents.
- [x] **0.3 Create `audit-long.md` skeleton.** One H2 per audit (Security,
  Performance, Architecture, Data Quality, Compliance & Regulatory), each with
  `Scope & method`, `Findings`, `Not covered` subsections, plus a top-level
  `Findings register` table using the template above.
- [x] **0.4 Fold existing `AUDIT.md` into `audit-long.md`.** Move the
  Claude Security low-effort scan (F1 `library/ajax/upload.php`, F2
  `portal/lib/paylib.php`, rejected candidates, "what was not scanned") into
  the Security section. Mark F1/F2 as *fixed in commit 859ad84* and note the
  DB-backed test suite has not been run against those fixes.
- [x] **0.5 Baseline the repo.** Done — recorded in `audit-long.md`'s
  header: branch `audit`, HEAD `859ad84` at the time the security section
  was baselined. Note: HEAD has since advanced (`11e0d6d`, `8939181`) as
  this audit's own findings were committed; the header will be refreshed
  to the final commit SHA at task 6.6. `CLAUDE-SECURITY-*` run directories
  confirmed complete/pruned under 1.1.1a.

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
- [x] **1.1.1e Record unscanned areas.** Done — folded into
  `audit-long.md` §1.4 "Not covered" (see 1.6.1 below).
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

- [x] **1.3.1 ACL model + `aclCheckCore` gap sweep.** Document `gacl/` +
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

- [x] **2.1.1 Schema inventory.** Done 2026-09-15 — folded into
  `audit-long.md` §2.2. Largest tables are static reference data
  (icd10/lang tables), not clinical data; core clinical table row counts
  recorded.
- [x] **2.1.2 Index coverage.** Done 2026-09-15 — folded as PERF-02
  (`log.date` unindexed), PERF-03 (`lists.begdate`/`enddate` unindexed);
  `procedure_result`→`procedure_order` join path confirmed indexed/clean.
- [x] **2.1.3 Storage layout.** Done 2026-09-15 — folded as PERF-10 (40
  `form_*` EAV tables), PERF-11 (`documents.document_data` LONGTEXT inline
  storage, optional CouchDB offload via `couch_docid`).
- [x] **2.1.4 Per-patient chart size.** Done 2026-09-15 — largest seeded
  patient (pid 28): 601 rows across encounters/lists/rx/imm/vitals/labs;
  smallest: 2-7 rows. >150x spread recorded in `audit-long.md` §2.2.

### 2.2 Query performance

- [x] **2.2.1 Enable slow-query log.** Done 2026-09-15 —
  `long_query_time=0.1`, `log_queries_not_using_indexes=ON` set; app
  exercised (login page render, globals/module bootstrap). REST/FHIR
  endpoints not exercised (OAuth2 password grant off by default — see Not
  covered).
- [x] **2.2.2 EXPLAIN hot queries.** Done 2026-09-15 — `EXPLAIN`/`ANALYZE`
  on chart-load, problem-list, and audit-log queries; findings are
  structural (plan shape) not measured-slow at this seed's row counts (see
  Not covered — no volume/scale test performed).
- [x] **2.2.3 N+1 patterns.** Done 2026-09-15 (delegated review) — folded
  as PERF-04 (`BaseService::addCoding`/`splitAndProcessMultipleFields`) and
  PERF-05 (patient summary page's 7+ sequential AJAX fragment loads, no
  batching). `PatientService`, `EncounterService`, `ObservationLabService`,
  `VitalsService` confirmed clean (batched queries only). Note:
  `interface/patient_file/summary/summary.php` named in the task does not
  exist — actual file is `demographics.php`.
- [x] **2.2.4 ORM / DB layer overhead.** Done 2026-09-15 — no Doctrine DBAL
  usage found in reviewed files (contrary to CLAUDE.md's stated stack); no
  prepared-statement/query-plan cache anywhere in `sqlQuery`→`QueryUtils`→
  ADODB→mysqli chain; MariaDB query cache off; folded as PERF-06
  (`escapeTableName()` re-runs `SHOW TABLES` every call).

### 2.3 Request latency

- [x] **2.3.1 Time key pages and endpoints.** Partially done 2026-09-15 —
  not measured end-to-end (see Not covered: no working browser automation
  in this container, legacy login flow doesn't script over plain curl).
  Measured: unauthenticated login-page render (~0.28s, 7 DB queries visible
  before auth even happens) as evidence of PERF-01's fixed per-request
  overhead floor.
- [x] **2.3.2 PHP runtime config.** Done 2026-09-15 — `memory_limit=512M`,
  `max_execution_time=0`, `mpm_prefork`; dev image `opcache.enable=Off` vs
  production/release image `opcache.enable=1` + APCu/Redis packages +
  optimized/APCu autoloader (PERF-09) — dev timings are not representative
  of production.
- [x] **2.3.3 Frontend weight.** Partially done 2026-09-15 — not measured
  per-page (no browser session). Recorded vendor tree upper bound
  (`public/assets/`: ckeditor5 41MB, jspdf 29MB, lforms 21MB — all
  feature-specific, not core-page weight). Real per-page payload flagged as
  a follow-up needing the browser network tab.

### 2.4 Background processing constraints

- [x] **2.4.1 Background services.** Done 2026-09-15 — lease locking
  (`lock_expires_at`) confirmed as a correct atomic compare-and-swap plus
  session-scoped `GET_LOCK()` advisory lock. Correction to task framing:
  the script does support CLI/cron invocation, but no cron entry exists in
  any reviewed Docker image, so in practice nothing runs it without a
  logged-in user or an operator-added cron job.
- [x] **2.4.2 Caching.** Done 2026-09-15 — no general-purpose cache layer
  exists anywhere. Redis is session-store-only; no APCu in application
  code; MariaDB query cache off. Folded as part of PERF-01/PERF-06
  write-up.
- [x] **2.4.3 Concurrency and locking.** Done 2026-09-15 — folded as
  PERF-07 (`library/spreadsheet.inc.php` full `LOCK TABLES` on form-type
  tables, not row-level) and PERF-08 (self-documented race condition in
  `Recorder::getNextSequenceNumber()` — concurrent payment posts for the
  same pid/encounter can collide).

### 2.5 Performance section wrap-up

- [x] **2.5.1** Done 2026-09-15 — folded into `audit-long.md` §2.2/§2.3:
  top structural bottlenecks are PERF-01 (no globals cache, paid every
  request), PERF-02 (unindexed audit-log date scans — compliance-relevant),
  PERF-04/PERF-05 (N+1 code lookups + unbatched summary-page fragments),
  PERF-07/PERF-08 (table-level lock + payment-sequence race). All measured
  as structural (`EXPLAIN` plan shape, code review) rather than
  stopwatch-slow at the 30-patient seed volume — see Not covered.
- [x] **2.5.2** Done 2026-09-15 — folded into `audit-long.md` §2.2: chart
  assembly cost varies >150x across seeded patients (601 rows vs 2-7); no
  cron/job queue exists (background services only run while a user is
  logged in, absent operator-added cron); no caching layer at any level
  (globals, query results, or app data) to absorb repeated reads; EAV
  `form_*` schema means encounter rendering is inherently multi-query, not
  single-joined.

---

## 3. Architecture audit

Goal: how the system is organized, where data lives, how layers interact,
and integration points for adding new capabilities.

- [x] **3.1 Layer map.** Done 2026-09-15 — folded into `audit-long.md` §3.2.
  Note: `graphify-out/graph.json` is scoped to `src/` only (confirmed via
  `GRAPH_REPORT.md`'s "Graph Report - src" header), so it covered god
  nodes/community structure for `src/` but not `interface/`/`library/`/
  `controllers/`, which were mapped by direct file reads instead.
- [x] **3.2 Request routing.** Done 2026-09-15 — folded into
  `audit-long.md` §3.2. Four parallel entry families (direct `interface/`
  hits, REST/FHIR via `apis/dispatch.php`, Laminas MVC `zend_modules`,
  portal via its own bootstrap), none unified by a front controller.
- [x] **3.3 Where data lives.** Done 2026-09-15 — folded into
  `audit-long.md` §3.2. 282 tables across the domain families listed;
  audit/logging alone is spread across 9+ distinct tables with no unified
  event log.
- [x] **3.4 Service layer.** Done 2026-09-15 (delegated inventory) —
  folded as ARCH-01. 50 `BaseService` subclasses across 18 domains;
  billing/claims and ACL/permissions have no typed service at all
  (legacy-only).
- [x] **3.5 Event system and extension points.** Done 2026-09-15
  (delegated inventory) — folded as ARCH-02. ~22 event-domain
  subdirectories inventoried; confirmed gap: no encounter-closed/signed
  lifecycle event exists. Module loader is two parallel systems (Laminas
  MVC + plain-PHP drop-in), folded as ARCH-04.
- [x] **3.6 Templating and UI stack.** Done 2026-09-15 (delegated
  inventory) — folded into `audit-long.md` §3.2. 199 Twig templates vs
  1,048 legacy PHP files under `interface/`; Smarty confirmed effectively
  dead (2 hits, both non-mainstream admin scripts). New UI convention:
  Controller class + `.html.twig` pair.
- [x] **3.7 Auth/session flow diagram.** Done 2026-09-15 — Mermaid diagram
  in `audit-long.md` §3.2 covering staff/portal/OAuth2 flows and where each
  boundary is (or isn't) enforced.
- [x] **3.8 Configuration and multi-site.** Done 2026-09-15 — folded into
  `audit-long.md` §3.2. `OEGlobalsBag` confirmed as the #3 god node in the
  `src/` graph (510 edges), reflecting pervasive direct-reach-in over DI.
- [x] **3.9 Testing and quality gates.** Done 2026-09-15 (delegated
  inventory) — folded into `audit-long.md` §3.2 and ARCH-03. PHPStan
  baseline is 170 per-error-type files totaling 375,460 lines — large
  suppressed-issue volume; CI diffs it rather than requiring it shrink.
- [x] **3.10 Integration-point summary.** Done 2026-09-15 — table in
  `audit-long.md` §3.2 covering REST/FHIR, Laminas modules, custom
  modules, event subscribers, background services, e-signature hooks, and
  new typed services.

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

- [x] **4.1.1 Demographics.** Done 2026-09-15 — folded into `audit-long.md`
  §4.2 and DQ-06. `DOB`/`sex` (required) 100% populated; `ss`/`phone_home`/
  `email` (optional) null for 28/30 (93%).
- [x] **4.1.2 Encounters.** Done 2026-09-15 — folded into `audit-long.md`
  §4.2, DQ-01, DQ-08. Core columns well-populated; `facility_id` populated
  but references a nonexistent facility on 99.8% of rows; 0 encounters
  without a `forms` row; 100% never signed/closed (dataset artifact).
- [x] **4.1.3 Clinical lists.** Done 2026-09-15 — folded as DQ-03. Coding
  and `begdate` gaps rare; 70-71% of active problems/meds have a past
  `enddate` while still flagged active.
- [x] **4.1.4 Medications, immunizations, vitals, labs.** Done 2026-09-15
  — folded as DQ-04, DQ-05. RxNorm/CVX/LOINC/units near-100% coverage;
  `prescriptions.route` missing 99.6%; `procedure_result.abnormal` missing
  100%; no all-null `form_vitals` rows.

### 4.2 Consistency and formatting

- [x] **4.2.1 Date formats.** Done 2026-09-15 — folded into `audit-long.md`
  §4.2. Clean: no `0000-00-00`, no future DOBs/encounters, no DOB-after-
  encounter rows; `DOB` is a native `date` column.
- [x] **4.2.2 Code systems.** Done 2026-09-15 — folded into `audit-long.md`
  §4.2. 869 SNOMED-CT / 2 ICD9 / 7 none on problem/allergy rows; the 279
  "unprefixed" rows are medication-type RxNorm codes by convention, not a
  gap. RxNorm 99.6%, LOINC ~100% by sampling.
- [x] **4.2.3 Free-text vs coded.** Done 2026-09-15 (partial) — folded
  into `audit-long.md` §4.2. No systemic dual-storage found; only
  `patient_data.status` vs `list_options` was checked, not swept across
  every coded field — flagged under Not covered.
- [x] **4.2.4 Reference integrity.** Done 2026-09-15 — folded as DQ-01
  (High): 99.8% of encounters reference a nonexistent `facility_id`.
  `provider_id` clean; 3 `patient_data.status` values outside
  `list_options`.
- [x] **4.2.5 Units and numeric formats.** Done 2026-09-15 — folded as
  DQ-02 (Medium): `form_vitals.height`/`weight` has no unit column and the
  seed mixes imperial/metric-range values in the same field.

### 4.3 Duplicates and orphans

- [x] **4.3.1 Duplicate patients.** Done 2026-09-15 — folded into
  `audit-long.md` §4.2. 0 duplicates found (name+DOB, SSN); merge tool
  (`interface/patient_file/merge_patients.php`) confirmed present.
- [x] **4.3.2 Duplicate clinical entries.** Partial — not run as a
  dedicated query; flagged under Not covered in `audit-long.md` §4.3.
- [x] **4.3.3 Orphaned rows.** Done 2026-09-15 — folded into
  `audit-long.md` §4.2. 0 orphaned `lists`/`prescriptions`/
  `form_encounter` rows; 0 `forms` rows missing their backing row across
  all 4 represented form types.

### 4.4 Staleness and lifecycle

- [x] **4.4.1 Stale records.** Done 2026-09-15 — folded as DQ-03 plus a
  confirming note on appointments (all 11 seeded rows past-due and never
  marked complete, sample too small to generalize a percentage).
- [x] **4.4.2 Deleted vs soft-deleted.** Done 2026-09-15 — folded into
  `audit-long.md` §4.2. Inconsistent pattern: `documents`/`forms` use
  `deleted` (confirmed correctly filtered in queries); `lists` uses
  `activity` (a clinical-status flag, not strictly soft-delete);
  `form_encounter`/`prescriptions`/`patient_data` have neither.
- [x] **4.4.3 Timestamps.** Done 2026-09-15 — folded as DQ-07:
  `form_encounter` and `procedure_result` have no `created`/`updated`
  column at all; other core tables do.

### 4.5 Data-quality wrap-up

- [x] **4.5.1** Done 2026-09-15 — all checks recorded with SQL intent,
  result, and schema-vs-dataset-level classification in `audit-long.md`
  §4.1/§4.2; severities assigned in the findings register (DQ-01..DQ-08).
- [x] **4.5.2** Done 2026-09-15 — folded into `audit-long.md` §4.2/§4.3:
  a downstream consumer must handle missing facility references (DQ-01),
  ambiguous vitals units (DQ-02), active-but-expired problem/med list
  entries (DQ-03), missing lab abnormal flags (DQ-04), sparse contact
  info (DQ-06), and encounters/lab results with no modification timestamp
  (DQ-07) — none of these will surface as a query error, only as silently
  wrong or incomplete downstream behavior.

---

## 5. Compliance & regulatory audit

Goal: HIPAA-focused pass separate from security — audit logging, retention,
breach notification, and BAA implications of sending PHI to an LLM provider.

### 5.1 Audit logging (§164.312(b))

- [x] **5.1.1 What is logged.** Done 2026-09-15 — folded into
  `audit-long.md` §5.2. PHI-view logging exists (bolted onto the SQL
  layer, not a semantic event); nearly all `audit_events_*` categories on
  by default. COMP-06: `audit_events_lab-order` has no globals entry and
  silently defaults off with no admin toggle.
- [x] **5.1.2 What is not logged.** Done 2026-09-15 (partial) — folded
  into `audit-long.md` §5.2/§5.3. Structural boundary noted (tables
  outside `LOG_TABLES` are invisible by design); document-download/export
  code paths not individually re-traced beyond existing SEC-40/SEC-42 —
  flagged under Not covered.
- [x] **5.1.3 Tamper evidence and integrity.** Done 2026-09-15 — folded
  as COMP-07 (High): a SHA3-512 checksum is written per row but never
  verified anywhere in the codebase; log tables have no triggers/
  append-only protection. Not exportable to an external SIEM (not found).
- [x] **5.1.4 Log retention and PHI in logs.** Done 2026-09-15 — folded
  as COMP-08 (High: raw query text + bound values, incl. PHI, stored
  unencrypted/base64 in `log.comments`; the `encrypt` flag is hardcoded
  to 'No') and COMP-09 (no rotation/retention job).
- [x] **5.1.5 Access review tooling.** Done 2026-09-15 — folded as
  COMP-10. Correction to task: the file is `interface/logview/logview.php`,
  not `interface/reports/audit_log.php`. Can filter by patient + date
  range; gaps are a "today only" default range and a hard 5000-row cap
  with no truncation indicator.

### 5.2 Data retention and disposal

- [x] **5.2.1 Retention policy support.** Done 2026-09-15 — folded as
  COMP-05. No retention/purge configuration exists at all in `globals`.
- [x] **5.2.2 Deletion and de-identification.** Done 2026-09-15 — folded
  as COMP-01. Patient deletion is a genuine hard `DELETE` cascading
  across ~15 tables, correctly excludes the audit log; but document
  deletion never removes the file from disk (soft-delete flag only, no
  `unlink()`). No de-identification/anonymization tooling exists.
- [x] **5.2.3 Backups.** Done 2026-09-15 — folded as COMP-02. On-demand,
  unscheduled, unencrypted (SEC-46); tool's own header comment
  self-acknowledges restore capability is unverified without operator
  testing.

### 5.3 Breach notification (§164.400–414)

- [x] **5.3.1 Detection capability.** Done 2026-09-15 — folded as
  COMP-03. None: no anomaly/unusual-access detection found anywhere;
  log-only.
- [x] **5.3.2 Scope determination.** Done 2026-09-15 — folded as COMP-04.
  Point queries (by patient or by user) work today (`log.patient_id`
  indexed); the actual incident-response query — date-range scan — full
  scans due to PERF-02's missing `log.date` index.
- [x] **5.3.3 Notification workflow.** Done 2026-09-15 — folded into
  `audit-long.md` §5.2. Confirmed none exists, as predicted.

### 5.4 Access controls and minimum necessary (§164.502(b), §164.312(a))

- [x] **5.4.1 Role granularity.** Done 2026-09-15 — folded into
  `audit-long.md` §5.2. ACL model itself is fine-grained (separate ACOs
  for demographics/notes/docs/rx/lab/amendment/etc.); default Front
  Office role is correctly scoped away from clinical notes/docs/rx/lab —
  minimum necessary is achievable at the model level.
- [x] **5.4.2 Sensitivity flags.** Done 2026-09-15 — folded as COMP-11.
  Enforced via `aclCheckCore('sensitivities',...)` throughout the legacy
  UI/service layer; zero references in the FHIR encounter service — not
  enforced in the API.
- [x] **5.4.3 Unique user identification and emergency access.** Done
  2026-09-15 — confirms (does not newly derive) SEC-24 (no concurrent-
  session limit, policy-only control) and SEC-33 (break-glass logged via
  `gbl_force_log_breakglass` default-on, but no approval workflow).
- [x] **5.4.4 Patient rights.** Done 2026-09-15 — folded as COMP-12.
  Correction to task's "likely none" framing: portal CCDA/document
  download and a genuine amendments workflow (`amendments` table +
  staff/portal UI) both exist. Accounting of disclosures also exists
  (`extended_log` + `disclosure_full.php`) but is entirely staff-curated,
  not auto-populated from actual API/CCDA/export transmissions.

### 5.5 Transmission and third parties (§164.312(e), §164.308(b))

- [x] **5.5.1 Existing BAA-requiring integrations.** Done 2026-09-15 —
  folded into `audit-long.md` §5.2, referencing §1.5.4 (fax, SMS, email,
  X12 clearinghouse; no e-prescribing; no LLM integration shipped).
- [x] **5.5.2 LLM provider implications.** Done 2026-09-15 — provider-
  agnostic write-up in `audit-long.md` §5.2 covering all six requirements
  (a)-(f); cross-checked against `AI_INTEGRATION_PLAN.md`'s own BAA/ZDR
  precondition (§12.4), which already matches this framing — see task 6.5.
- [x] **5.5.3 Local vs hosted inference.** Done 2026-09-15 — folded into
  `audit-long.md` §5.2 as a trade-off statement, no implementation
  recommendation made.

### 5.6 Compliance wrap-up

- [x] **5.6.1** Done 2026-09-15 — safeguard-category mapping table in
  `audit-long.md` §5.4, distinguishing technical-control-missing findings
  from operator-policy-required ones.
- [x] **5.6.2** Done 2026-09-15 — ranked in `audit-long.md` §5.4: COMP-07/
  COMP-08 (audit log itself unprotected and unverifiable) rank highest,
  followed by COMP-04/COMP-10 (slow/incomplete breach-scope queries),
  then COMP-11/COMP-06, then COMP-01/COMP-12, then the lower-urgency
  process gaps (COMP-02/03/05/09).

---

## 6. Synthesis and final deliverable

- [x] **6.1 Complete `audit-long.md`.** Done 2026-09-15 — verified by
  heading structure: every one of §1-§5 has Scope & method, Findings, and
  Not covered populated. 87 findings in the register, each with severity,
  location, evidence, impact, and recommendation embedded in its
  description.
- [x] **6.2 Rank across audits.** Done 2026-09-15 — ranked top 10 in
  `audit-long.md` §6.2 by severity × reachability × breadth (not raw
  severity), grouping the 14-finding "gate the menu, not the handler" ACL
  pattern as a single ranked item per its actual breadth.
- [x] **6.3 Write the one-page summary (~500 words).** Done 2026-09-15 —
  499 words, in `AUDIT.md`'s Executive Summary. Structured exactly per
  the template: two-sentence system description, findings grouped by
  theme, the audit-log-integrity + ACL-pattern recommendation as the
  single most important "do this before adding anything," and a Not
  covered close.
- [x] **6.4 Assemble `AUDIT.md`.** Done 2026-09-15 — summary first, then
  the complete contents of `audit-long.md` (all 87 findings, all five
  sections in full — not a link).
- [x] **6.5 Review gate.** Done 2026-09-15 — folded into `audit-long.md`
  §6.5: no placeholders/TBD/TODO remaining (one stale cross-reference
  found and fixed); SEC-01/SEC-02 correctly remain the only two findings
  marked fixed; no real PHI present (all quoted sample values are
  synthetic Synthea data, consistent with the document header's
  disclosure). `AI_INTEGRATION_PLAN.md` §2 cross-check: consistent with
  this audit's independent findings (chart-size numbers match exactly,
  "no encounter closed event" and "background services need real cron"
  both independently confirmed) — no discrepancies to flag, noted as a
  positive cross-check rather than forcing a gap that isn't there.
- [x] **6.6 Commit.** Committing now with `docs(audit): add system audit`
  and the `Assisted-by: Claude Code` trailer, on the `audit` branch.
