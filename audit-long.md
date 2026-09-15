# OpenEMR System Audit — Full Findings

Working document. Every significant finding from each audit is recorded here
first; the ~500-word summary and the final `AUDIT.md` are produced from this
file last. Task tracking lives in `AUDIT_TASKS.md`.

**System under audit:** OpenEMR 8.2.0 (database schema v541, ACL v13), fork
of `Gauntlet-HQ/openemr-base-clean`, branch `audit`, HEAD `859ad84`. All data
in the dev stack is synthetic (Synthea-generated); no real PHI is present.

**Severity scale:** Critical / High / Medium / Low / Info — exploitability and
impact, not confidence. Confidence is recorded separately where it matters.

---

## Findings register

Cross-audit ranking happens in §6 of the task list; this table collects every
finding by ID. `SEC-` security, `PERF-` performance, `ARCH-` architecture,
`DQ-` data quality, `COMP-` compliance.

| ID | Sev | Area | Location | One-line | Status |
|---|---|---|---|---|---|
| SEC-01 | High | Portal authz | `library/ajax/upload.php:140` | Portal patient can read/overwrite any patient's document by `doc_id` (CWE-639) | Fixed in 859ad84 (untested) |
| SEC-02 | Medium | Portal authz | `portal/lib/paylib.php:189` | Portal patient can write payment audit rows for any `form_pid` (CWE-639) | Fixed in 859ad84 (untested) |
| SEC-03 | Medium | Staff authz | `interface/patient_file/encounter/diagnosis.php:105` | Billing rows written before the coding ACL check runs (CWE-862) | Open |
| SEC-04 | Medium | Staff authz | `interface/patient_file/encounter/diagnosis_full.php:37` | Any user can add/deactivate/clear any billing row by id; no ACL (CWE-862) | Open |
| SEC-05 | Medium | Staff authz | `interface/patient_file/encounter/superbill_codes.php:51` | Any user can add charges from GET params; no ACL (CWE-862) | Open |
| SEC-06 | Medium | Staff authz / portal | `interface/patient_file/summary/create_portallogin.php:61` | Any user can reset any patient's portal credentials and log in as them (CWE-862) | Open |
| SEC-07 | Low | Staff authz | `interface/patient_file/summary/add_edit_issue.php:268` | Issue save path skips per-issue-type ACL (`aclCheckIssue`); non-default config only (CWE-862) | Open |
| SEC-08 | Info | Session scoping | `interface/patient_file/encounter/encounter_top.php` | `set_pid`/`set_encounter` bind the session to any patient with no ACL check — the primitive that makes SEC-03..06 reach every patient | Open |
| SEC-09 | Info | Defense in depth | `interface/super/edit_layout.php:598` | `copytolayout` branch lacks CSRF check; unexploitable because core cookie is `SameSite=Strict` | Open |
| SEC-10 | Info | Defense in depth | `interface/patient_file/front_payment_cc.php:85` | Unescaped exception text echoes raw `$_POST['payment']`; self-XSS only under `SameSite=Strict` | Open |
| SEC-11 | High | API / SQLi | `src/Services/Search/SearchFieldStatementResolver.php:293` | Query-parameter *names* become SQL column names unvalidated; arbitrary table read via REST search, reachable by portal patients on `/employer` (CWE-89) | Open |
| SEC-12 | High | Reports / SQLi | `interface/reports/ippf_statistics.php:1456` | `form_facility` interpolated into stats query; full DB read by acct/rep user (CWE-89) | Open |
| SEC-13 | Medium | Reports authz | `interface/reports/patient_list.php:260` | Patient List report runs with no ACL check; bulk demographics + insurance to any user (CWE-862) | Open |
| SEC-14 | Medium | Reports authz | `interface/reports/charts_checked_out.php:83` | Chart-tracker report discloses patient names/IDs on GET, no ACL (CWE-862) | Open |
| SEC-15 | Medium | Reports authz | `interface/reports/unique_seen_patients_report.php:225` | Unique-seen report exports names+addresses, no ACL (CWE-862) | Open |
| SEC-16 | Medium | Reports authz | `interface/reports/patient_flow_board_report.php:417` | Flow-board report discloses appointment PHI + drug-screen status, no ACL (CWE-862) | Open |
| SEC-17 | High | Dependencies (PHP) | `composer.lock` | 19 advisories across 5 packages; 4 High (guzzle host-check bypass, phpspreadsheet ×3 DoS/SSRF), all reachable | Open |
| SEC-18 | Medium | Dependencies (JS) | `package-lock.json` | 5 moderate in shipped deps (dompurify XSS, jszip path-traversal/proto-pollution, dwv, fflate, validate.js ReDoS); 13 High/Critical are dev-only | Open |
| SEC-19 | Medium | Config / default creds | `docker/production/docker-compose.yml:13,37-41` | Production compose ships hardcoded `root`/`root` DB and `admin`/`pass` app creds with no env indirection | Open |
| SEC-20 | Low | Committed key material | `docker/library/*-ssl-cert-keys/`, `ci/nginx/dummy-key` | Real TLS private keys committed to the repo; dev/CI stacks only, not used by production compose | Open |
| SEC-21 | High | Session mgmt | `library/auth.inc.php:52-80` | No session ID regeneration on login (core or portal) — session fixation (CWE-384) | Open |
| SEC-22 | Medium | Session mgmt | `src/Common/Session/SessionConfigurationBuilder.php:83-122` | Core and portal session cookies ship `Secure=false` unconditionally (not HTTPS-derived); core cookie also `HttpOnly=false` by design (CWE-614/CWE-1004) | Open |
| SEC-23 | Medium | Authentication | `library/auth.inc.php`, `src/Common/Auth/MfaUtils.php:75-78` | TOTP/U2F MFA exists but is never invoked on the primary staff web login; only reachable via OAuth2 password-grant; no admin-enforceable "require MFA" setting | Open |
| SEC-24 | Low | Session mgmt | `src/Common/Session/SessionTracker.php:38-56` | No absolute session lifetime cap (idle-timeout only) and no concurrent-session limit per user | Open |
| SEC-25 | Medium | OAuth2 / API | `src/RestControllers/AuthorizationController.php:287-410` | Dynamic client registration (`/registration`) is unauthenticated; clients requesting only default scopes are auto-enabled with `oauth_app_manual_approval` off by default | Open |
| SEC-26 | Low | Portal authn | `portal/account/account.lib.php:242-302` | Self-service password-reset trigger gated on guessable PII (DOB+name+email), no dedicated rate limit beyond reCAPTCHA (distinct from SEC-06) | Open |
| SEC-27 | Medium | Staff authz | `library/ajax/person_search_ajax.php:115,353` | No ACL check: any authenticated user can search PHI (name/DOB/phone/email) and create new person records; stack trace echoed on error | Open |
| SEC-28 | Medium | Staff authz | `library/ajax/upload.php:131-149` | No ACL check on document upload/fetch: any authenticated user can write documents into any patient's chart; `doc_id` fetch not ownership-checked for core users | Open |
| SEC-29 | Low | Staff authz | `library/ajax/addlistitem.php:70` | No ACL check: any authenticated user can insert rows into `list_options` (system-wide reference lists) via GET | Open |
| SEC-30 | Medium | Staff authz / IDOR | `interface/patient_file/summary/pnotes_full_add.php:37-146` | `docid`/`orderid` request param (not session pid) determines which patient's chart notes are read/written; squad-checked but not pid-checked | Open |
| SEC-31 | Low | Upload validation | `interface/modules/zend_modules/module/Documents/.../DocumentsController.php:70-93` | Upload type gate relies on client-supplied `Content-Type`, not server-side sniffing; bounded impact since storage flows through the protected Document engine | Open |
| SEC-32 | Low | Upload validation | `oe-module-faxsms` `*Client.php faxProcessUploads()` | No MIME/extension validation before `move_uploaded_file()`; mitigated by storage outside webroot (`temporary_files_dir`, default `/tmp`) | Open |
| SEC-33 | Medium | Break-glass | `src/Common/Logging/BreakglassChecker.php`, `library/classes/Installer.class.php:1413-1436` | Break-glass ("Emergency Login") grants unscoped superuser-equivalent access, self-service with no justification/approval workflow, no rate limit, no dedicated review UI | Open |
| SEC-34 | High | Privilege escalation | `library/ajax/adminacl_ajax.php:44-84` | Missing superuser-inclusion check (present in sibling `usergroup_admin.php`) lets any `admin/acl`-privileged non-superuser add themselves to Administrators or Emergency Login group | Open |
| SEC-35 | Low | CSRF | `interface/billing/search_payments.php:50-69` | `DeletePayments` POST handler has no CSRF check; ACL-gated, mitigated by `SameSite=Strict` | Open |
| SEC-36 | Medium | CSRF | `interface/billing/ub04_dispose.php`, `ub04_submit.php` | No CSRF check; handler accepts writes via GET (`$_POST[...] ?? $_GET[...]`), so only `SameSite=Strict` mitigates — no POST-only fallback defense | Open |
| SEC-37 | Low | Security headers | app-wide; `interface/login/login.php:31-32`, `portal/index.php:20-21` | No CSP/X-Frame-Options/Referrer-Policy outside login/portal entry pages; HSTS only present via Docker-image Apache config, not app-level | Open |
| SEC-38 | Low | Info disclosure | `src/RestControllers/Subscriber/ExceptionHandlerListener.php:50-56`, `AuthorizationController.php:628,1256,1416`, FHIR operation controllers | REST/OAuth/FHIR error responses return raw `$exception->getMessage()` to clients (traces stay server-side) | Open |
| SEC-39 | Low | PHI in logs | `src/Services/Cda/CdaTemplateImportDispose.php:2344,2371,2373` | CCDA import logs source filenames, which may embed patient name/MRN by convention (indirect, unconfirmed) | Open |
| SEC-40 | Medium | Export authz | `interface/modules/zend_modules/.../EncounterccdadispatchController.php:76` | CCDA/QRDA export controller takes `pid`/`pids` from request with no visible per-patient ACL check and no rate limit on batch export | Open |
| SEC-41 | Info | Export rate limiting | `src/RestControllers/FHIR/Operations/FhirOperationExportRestController.php` | Bulk FHIR system export has no abuse-rate limiting (only execution-time/retention bounds); full-population dump is an intended, scope-gated capability | Open |
| SEC-42 | Medium | Export/scraping | `interface/reports/patient_list.php:35-41,187-295` | Any authenticated user can CSV-export the entire patient list (name, DOB, address, phone) with no pagination or rate limit, beyond basic login+CSRF | Open |
| SEC-43 | Low | Transit encryption | `docker/flex/openemr.conf:145-159` | HTTP→HTTPS redirect is present in config but commented out / disabled by default; plain HTTP served on port 80 | Open |
| SEC-44 | Low | Transit encryption | `docker/development-easy/docker-compose.yml:97` | App connects to LDAP over plaintext `ldap://` even though the LDAP container is provisioned with TLS cert material | Open |
| SEC-45 | Info | At-rest encryption | `docker/production/docker-compose.yml:11,63` | DB data volume has no disk/volume-level encryption configured by default (relies on host/cloud disk encryption if any) | Open |
| SEC-46 | Low | At-rest encryption | `interface/main/backup.php` | Backup archives (SQL dump + web dir tar/zip) are compressed but not encrypted by the backup tool itself | Open |
| SEC-47 | High | PHI in non-clinical store | `sql/database.sql:1666-1681` (`email_queue`), `:10093-10110` (`notification_log`) | Full PHI-bearing message bodies (appointment reminders, notification content) stored plaintext, indefinitely, with no dedicated ACL-gated viewer — bypasses the normal per-patient ACL model | Open |
| SEC-48 | Medium | Audit log ACL | `interface/logview/logview.php:29-31` | Audit-log viewer (which can surface PHI in free-text `comments`) is gated by a single coarse `admin/users` ACL, not per-patient authorization | Open |
| SEC-49 | Low | PHI in session | `src/Common/Auth/OneTimeAuth.php:324,328` | Patient portal one-time-auth caches full provider/patient names in `$_SESSION` (narrow scope: patient's own session only) | Open |
| SEC-50 | Low | PHI in temp files | `src/Cqm/QrdaControllers/QrdaReportController.php:61,117,210,272,335` | QRDA/CQM export staging uses predictable temp filenames (`time()`-based) and `chmod 0777` directories, unlike the hardened random-name pattern used for CCDA export | Open |
| SEC-51 | Medium | Third-party egress | `library/classes/postmaster.php:24,209`, `library/globals.inc.php:2474-2519` | SMTP email defaults to unencrypted (`SMTP_SECURE` default `''`) with an admin-redirectable `SMTP_HOST`; PHI-bearing notification content can be sent to any relay without forced TLS | Open |
| SEC-52 | Low | Third-party egress | `interface/modules/custom_modules/oe-module-faxsms/src/Controller/ClickatellSMSClient.php:49` | SMS message content and API key placed in a GET query string (TLS-wrapped, but risks exposure via access/proxy logs) | Open |

---

## 1. Security audit

### 1.1 Scope & method

Two complementary methods:

1. **Automated scans** — Claude Security plugin v0.11.0. Each scan reads
   source only (no code executed, no exploit fired); candidates are voted on
   by a three-lens panel (reachability, impact, defenses) and only unanimous
   or majority survivors are reported. Nondeterministic; a clean result means
   "nothing surfaced in one pass", not proof of absence.
2. **Manual review** — targeted reads of the auth/session/ACL stack, upload
   handlers, and PHI paths (tasks 1.2–1.5 in `AUDIT_TASKS.md`; pending).

#### Scan runs

| Run dir | Effort | Scope | Files | Candidates → confirmed | Status |
|---|---|---|---|---|---|
| `CLAUDE-SECURITY-20260915-075916/` | low | `apis/`, `oauth2/`, `src/RestControllers/`, `src/Common/{Auth,Http,Csrf,Acl,Session}`, `src/Controllers/Portal`, `portal/`, `library/ajax/`, `controllers/`, `interface/login` | 436 | 4 → 2 | Verified |
| `CLAUDE-SECURITY-20260915-110901/` ("slice A") | low | `interface/patient_file`, `interface/super`, `library/documents.php` | 139 | 7 → 5 | Verified |
| `CLAUDE-SECURITY-20260915-145753/` ("slice B") | low | `src/Services`, `src/Common/Database` | 422 | 1 → 1 | Verified |
| `CLAUDE-SECURITY-20260915-145754/` ("slice C") | low | `interface/reports` | 47 | 5 → 5 | Verified |

Four earlier medium-effort attempts (run dirs `-091134`, `-094030`,
`-094745`, `-100258`; scopes of 2,115–2,859 files) never got past target-file
enumeration. Root cause was not scope size: they were launched through the
plugin's orchestrator subagent, which lacks the `Workflow` tool the scan
pipeline needs. Those directories held no findings and were deleted on
2026-09-15. All later scans were launched from the main session and
completed.

Scan run directories are gitignored; the `CLAUDE-SECURITY-RESULTS.md` in
each holds the full per-finding write-up, exploit walk-through, and panel
votes. This document carries the audit-relevant substance.

#### Coverage summary (scans)

Scanned: the API/OAuth2 perimeter, the auth/session/CSRF/ACL stack, the
patient portal, AJAX endpoints, login, the staff chart UI
(`interface/patient_file`), the layout/globals admin (`interface/super`),
`library/documents.php`, the service layer (`src/Services`) and DB
connection layer (`src/Common/Database`), and the reporting pages
(`interface/reports`). No further automated slices are planned; remaining
security work is the manual review tasks (1.2–1.5).

Not scanned by any automated pass: `interface/` outside `patient_file`,
`super`, `reports`, `login` (billing, calendar, forms, usergroup, main,
modules, …); `library/` outside `ajax/` and `documents.php`; `src/` outside
the auth directories, `Services`, `Common/Database`, `RestControllers`;
`gacl/`; `ccdaservice/`; `sql/`; tests; vendored trees. See §1.4 for the
reasoning and which manual tasks spot-check each.

### 1.2 Findings

#### SEC-01 — Portal patient can read and overwrite any document (HIGH) — *fixed*

**Location.** `library/ajax/upload.php:140`, `dicom_history_action`. CWE-639.

**What.** `action=fetch`/`save` with a POSTed `doc_id` flowed directly into
`SELECT document_data FROM documents WHERE id = ?` and the matching `UPDATE`.
Dispatch ran for any session with `pid` and `patient_portal_onsite_two` set —
normal portal login — with no check that the document belonged to the
requesting patient. The only gate was CSRF, which proves session ownership,
not authorization.

**Impact.** A portal patient could enumerate document ids, read any other
patient's stored document, and overwrite it. Cross-patient PHI confidentiality
and integrity breach in one endpoint, reachable by the least-trusted
authenticated user class the system has.

**Fix applied (commit 859ad84).** In a portal session, the endpoint now loads
`documents.foreign_id` for the requested id and returns 403 unless it equals
the session `pid`; missing rows are rejected. `php -l` passes; the DB-backed
test suite has **not** been run against the change.

#### SEC-02 — Portal patient can write payment audit rows for another patient (MEDIUM) — *fixed*

**Location.** `portal/lib/paylib.php:189` (also 106, 145, 197). CWE-639.

**What.** The AuthorizeNet, Stripe, `portal-save`, and `review-save` branches
read `$form_pid = $_POST['form_pid']` and passed it to `SaveAudit()` /
`CloseAudit()` as `patient_id` with no comparison to the session `pid`. The
sibling Sphere branch already had the check.

**Impact.** A portal patient could create or overwrite payment audit records
in `onsite_portal_activity` attributed to any other patient — corrupting the
audit trail staff rely on when reviewing pending payments. No money movement
or PHI disclosure, hence MEDIUM.

**Fix applied (commit 859ad84).** All four branches now use
`$form_pid = isset($pid) ? $pid : $_POST['form_pid']`, matching
`portal/portal_payment.php:115`. Untested beyond `php -l`.

#### SEC-03 — Billing rows written before the coding ACL check (MEDIUM, confidence high)

**Location.** `interface/patient_file/encounter/diagnosis.php:105`. CWE-862.
Panel 3/3.

**What.** `$mode`, `$type`, `$code`, `$fee`, `$text` come from `$_REQUEST`
(lines 28–36). Inside `if (isset($mode))` (line 55) they are written to
`billing` via `BillingUtilities::addBilling` (lines 68, 81, 105) and a raw
`UPDATE billing ... WHERE encounter = $_POST['encounter_id'] AND pid =
$_POST['patient_id']` (lines 131–137, 151–154). The only authorization check,
`AclMain::aclCheckCore('encounters', 'coding_a'/'coding')`, is at lines
216–236 — after the writes. `addBilling`
(`src/Billing/BillingUtilities.php:1434–1475`) has no ACL of its own.

**Impact.** Any authenticated staff user, including the default *Front
Office* group, can add charges/copays and rewrite justifications on any
encounter. The page then prints "Coding not authorized" — after the row is
already in.

**Recommendation.** Move the ACL and squad checks above the `$mode` block and
deny via `AccessDeniedHelper`. Bind the justify-path `UPDATE` to the session
encounter/pid rather than POSTed ids.

#### SEC-04 — Any user can add, deactivate or clear any billing row (MEDIUM, confidence high)

**Location.** `interface/patient_file/encounter/diagnosis_full.php:37`.
CWE-862. Panel 3/3.

**What.** `$_GET['mode']` and `$_GET['id']` drive `addBilling` /
`deleteBilling` / `clearBilling` (lines 35–39) behind only a CSRF check. No
`AclMain` call in the file. `deleteBilling`/`clearBilling`
(`BillingUtilities.php:1484, 1489`) run `UPDATE billing SET activity = 0
WHERE id = ?` with no pid/encounter scoping; ids are sequential.

**Impact.** A user with no coding or billing rights can walk the `billing`
table and deactivate every charge and diagnosis line in the system.

**Recommendation.** Enforce the `encounters/coding(_a)` ACL before `$mode`
handling; scope delete/clear to rows whose `pid`/`encounter` match the
session.

#### SEC-05 — superbill_codes.php adds charges from GET without authorization (MEDIUM, confidence high)

**Location.** `interface/patient_file/encounter/superbill_codes.php:51`.
CWE-862. Panel 3/3.

**What.** `$type`, `$code`, `$fee`, `$modifier`, `$units`, `$text` from
`$_GET` (lines 33–39) go to `addBilling` (47–51) after only CSRF (43). No
`AclMain` call anywhere — unlike sibling `superbill_custom_full.php:34–35`,
which does check.

**Impact.** Arbitrary charges on any encounter by any staff user.

**Recommendation.** Same ACL gate as `diagnosis.php` intends, before `$mode`.

#### SEC-06 — Any user can reset any patient's portal credentials (MEDIUM, confidence medium)

**Location.** `interface/patient_file/summary/create_portallogin.php:61`.
CWE-862. Panel 3/3.

**What.** The dashboard card linking here is gated by `patients/demo`
(`src/Patient/Cards/PortalCard.php:75`); the endpoint is not. It checks CSRF
(line 50 — token obtainable by GETting the same page, line 77) and calls
`PatientAccessOnsiteService::saveCredentials()`
(`src/Services/PatientAccessOnsiteService.php:88–131`), which hashes
`$_POST['pwd']` and overwrites `portal_username`, `portal_login_username`,
`portal_pwd` for the session `$pid`. Neither page nor service calls
`AclMain`.

**Impact.** A low-privilege staff account can set portal credentials for
any patient and log into the portal as that patient: read their record and
messages, sign documents, act in their name. This crosses from staff-side
integrity into patient-facing impersonation — the most consequential of the
slice A findings for PHI confidentiality.

**Preconditions.** Portal enabled for the site and patient.

**Recommendation.** `AclMain::aclCheckCore('patients', 'demo', '', 'write')`
plus squad check at the top of the page; enforce again inside
`saveCredentials()` so future callers cannot skip it.

#### SEC-07 — Issue save path skips per-issue-type ACL (LOW, confidence medium)

**Location.** `interface/patient_file/summary/add_edit_issue.php:268`.
CWE-862. Panel 3/3.

**What.** On POST `form_save`, the type comes from `$_POST['form_type']`
(line 212) and `updateIssue`/`createIssue` run (268, 274) with no
`aclCheckIssue`. The only two checks are at line 79 (requires optional
`thistype`, which the form at 779–790 never sends) and line 318 (GET display
path, after the save path has `exit()`ed at 311).

**Impact.** Bypass of per-issue-type write ACLs on problems, medications,
allergies — but only where an administrator has populated
`issue_types.aco_spec`. The default is NULL, which makes `aclCheckIssue`
return true for everyone, so on a stock install there is nothing to bypass.
LOW for that reason.

**Recommendation.** Resolve `$text_type` first in the save branch and require
`aclCheckIssue($text_type, '', ['write','addonly'])` for new issues and
`aclCheckIssue($existingRow['type'], '', 'write')` for edits.

#### SEC-08 — Session patient binding has no ACL check (INFO — systemic)

**Location.** `interface/patient_file/encounter/encounter_top.php`
(`set_pid`, `set_encounter`).

**What.** Any authenticated user can rebind their session's `pid` and
`encounter` to any patient. This is not itself a vulnerability — it is how
the chart UI works — but it means every handler that trusts "session pid"
without its own ACL is reachable for every patient. SEC-03 through SEC-06
all depend on it.

**Why it matters for the audit.** It is the recurring pattern in this slice:
*the menu item, card, or button is ACL-gated; the handler it posts to is
not.* Any new capability that acts on behalf of a staff session must do its
own authorization check at the handler, not inherit trust from the UI that
led there.

#### SEC-11 — SQL injection via query-parameter names in REST search (HIGH, confidence high)

**Location.** Sink `src/Services/Search/SearchFieldStatementResolver.php:293`
(`resolveStringSearchField`); entry
`src/Services/Search/FhirSearchWhereClauseBuilder.php:38`. CWE-89. Panel
3/3; path independently re-read after the panel.

**What.** The untrusted input is the *name* of an HTTP query parameter.
`PrescriptionRestController::getAll` (`:154`) and the
`GET /api/patient/:puuid/employer` and `/insurance` route closures
(`apis/routes/_rest_routes_standard.inc.php:524, 541`) pass
`$request->getQueryParams()` unfiltered into `PrescriptionService::getAll`,
`EmployerService::search`, `InsuranceService::search`. Each calls
`FhirSearchWhereClauseBuilder::build()`, which wraps any plain-string entry
as `new StringSearchField($key, $value, EXACT)` — the array key becomes the
column name. The resolver then emits `"BINARY " . getField() . ' = ?'`;
only the value is bound. No allowlist exists on these three paths
(contrast `PatientRestController::SUPPORTED_SEARCH_FIELDS`, `:137, :484`,
which filters keys first). PHP's `$_GET` parsing leaves parentheses,
commas, tabs (`%09`), and encoded `=` intact in parameter names, which is
enough to write a subquery.

**Exploit.** `GET /api/prescription?%28select%09group_concat%28username,0x3a,password%29%09from%09users%29=x`
→ WHERE fragment `BINARY (select group_concat(username,0x3a,password) from
users) = ?` executed at `PrescriptionService.php:77`. Boolean/error/time
techniques then read any table.

**Impact.** Full database read — every patient's PHI and `users` password
hashes — by anyone holding an API token that reaches one affected route.
The `/employer` route explicitly serves **patient-portal API sessions**
(`$request->isPatientRequest()`), so a portal patient with API access can
exploit it with their own token; the token's data scope is irrelevant once
the injection lands. This is the highest-severity open finding in the audit
and the first that crosses from authorization bypass into unrestricted data
extraction.

**Preconditions.** Valid API session passing
`RestConfig::request_authorization_check` for one affected route:
`patients:med` for `/api/prescription`; `patients:demo` (staff) or a portal
session (`/employer`). Other standard controllers that forward
`getQueryParams()` without an allowlist would share the defect; only these
three were traced.

**Recommendation.** Fix at the sink: `FhirSearchWhereClauseBuilder` /
`SearchFieldStatementResolver` must reject any field name not in the
service's known column set before concatenation. Also allowlist accepted
search keys in each controller, as `PatientRestController` does. The sink
fix closes every caller including future ones.

**Why it matters beyond the API.** `src/Services` is the layer the audit
recommends new capabilities call (see §3). A consumer that builds a
`$search` array from any user-influenced keys — a free-text filter, a
column picker, a tool-call argument — inherits this injection unless it
constructs `ISearchField` objects with hard-coded field names.

#### SEC-12 — SQL injection via form_facility in the IPPF statistics report (HIGH, confidence medium)

**Location.** `interface/reports/ippf_statistics.php:1456` (source line 47).
CWE-89. Panel 3/3.

**What.** Every filter in this query uses a `?` placeholder except
`form_facility`, which is concatenated verbatim
(`AND fe.facility_id = '$form_facility'`) *and* also pushed onto the bind
array (line 1457). It runs via `sqlStatement` on the default reachable
branch. The ADODB mysqli driver uses emulated (client-side) binding, so a
payload containing a `?` inside a trailing SQL comment balances the extra
bind and the injection executes.

**Exploit.** `form_facility = 1' UNION SELECT username,password,3,... FROM
users -- ?` → full-table read via UNION.

**Impact.** Arbitrary database read (patient PHI, billing, `users` password
hashes). Unlike SEC-11, this file *does* enforce an ACL
(`AclMain::aclCheckCore('acct', 'rep')`, line 34), so exploitation is
limited to accounting/report users — a smaller population than SEC-11's
portal-reachable path, which is why SEC-11 ranks first among the two HIGH
SQLi findings despite equal impact.

**Recommendation.** Bind `form_facility` as a parameter like the
`form_content == 5` branch does; remove the interpolation.

#### SEC-13 to SEC-16 — Report pages run without their authorization check (MEDIUM each)

Four report scripts authenticate via `globals.php` and check CSRF but never
call `AclMain::aclCheckCore`. Each is gated in the menu (`standard.json`
`acl_req`), but that gate is consumed only by `src/Menu/MenuRole.php` for
*rendering* — direct navigation to the script URL bypasses it entirely, so
any authenticated user, regardless of role, can run the report. Sibling
reports in the same directory (`patient_list_creation.php:35`,
`clinical_reports.php:28`, `appointments_report.php:56`) gate themselves,
which is what makes these omissions stand out. All four confirmed by the
panel (SEC-14 at 2/3; the rest 3/3).

| ID | File:line | Discloses | Trigger |
|---|---|---|---|
| SEC-13 | `patient_list.php:260` | Name, DOB, full address, phones, primary+secondary insurance for every patient in a range; CSV export | POST `form_refresh` |
| SEC-14 | `charts_checked_out.php:83` | Patient public ID, full name, chart holder, timestamp for every checked-out chart | GET (renders on load, no form) |
| SEC-15 | `unique_seen_patients_report.php:225` | Name + full address for every unique patient in a range; CSV export | POST `form_refresh`/`form_labels` |
| SEC-16 | `patient_flow_board_report.php:417` | Patient name, public ID, appointment date/time, provider, drug-screen status | POST `form_refresh` |

**Recommendation (all four).** Add `AclMain::aclCheckCore(<the acl_req from
standard.json>)` with `AccessDeniedHelper` at the top of each script, before
any query runs. SEC-14 is the most exposed of the four because it needs no
form submission — the PHI renders on a bare GET.

**Audit note.** These are confirmed instances, not a census. The pattern
(menu-gated, handler-ungated) is the same as SEC-03–06 in the chart UI and
almost certainly recurs in other report scripts that were not individually
traced in one low-effort pass. A directory-wide grep for report scripts that
lack an `aclCheckCore` call is the cheap follow-up (manual task 1.3.1).

#### SEC-17 — Outdated PHP dependencies with known CVEs (HIGH aggregate, confidence high)

**Location.** `composer.lock`. Method: `composer audit` in the openemr
container (2026-09-15), versions read from `composer.lock`. No code executed.

**What.** 19 published advisories affect 5 installed packages. The four
HIGH-severity ones (task 1.1.2 records High/Critical explicitly):

| Package | Installed | Fixed in | High advisory | Reachable here? |
|---|---|---|---|---|
| `guzzlehttp/guzzle` | 7.12.1 | 7.15.2 | CVE-2026-69246 — noncanonical host bypasses host-based checks | Yes — Guzzle is the outbound HTTP client (`JWTClientAuthenticationService`, `CqmCalculator`, FHIR client, 9 call sites). Practical impact depends on OpenEMR feeding attacker-influenced hosts to Guzzle's host checks; treat as reachable pending review. |
| `phpoffice/phpspreadsheet` | 5.8.0 | >5.8.0 | CVE-2026-59933, -59932 — XLS/Gnumeric parser memory exhaustion (DoS) | Yes — `IOFactory::load()` readers are reached from document upload and the Carecoordination/Documents Zend modules and `CDADocumentService`; a user who can upload a spreadsheet reaches the parser. |
| `phpoffice/phpspreadsheet` | 5.8.0 | >5.8.0 | CVE-2026-59931 — SSRF via HTTP redirect in `WEBSERVICE()` whitelist | Unlikely — no `WEBSERVICE`/formula-calculation use found (grep clean); `SpreadSheetService` uses the library as a *writer*. |

The remaining 15 are MEDIUM/LOW: `guzzlehttp/guzzle` (6 more — cookie scope,
proxy-auth header leak, referer fragment leak), `guzzlehttp/psr7` 2.12.1 (host
confusion, fix 2.12.3), `dompdf/dompdf` v3.1.5 (6 — SVG/BMP file-read and DoS,
fix 3.1.6; reachable only via the Carecoordination module), `smarty/smarty`
v4.5.6 (2 — `{fetch}` SSRF and symlink traversal, fix 4.5.7; no `{fetch}`
templates found, low reachability).

**Also:** 6 abandoned packages (`laminas/laminas-config`, `-json`, `-loader`;
`php-http/message-factory`; `symfony/inflector`; `yubico/u2flib-server` — the
U2F server library, notable because it underpins a security feature and has no
maintained replacement).

**Impact.** The reachable High items are availability (spreadsheet-parser DoS
via a crafted upload) and a host-validation bypass in the HTTP client. None is
by itself remote code execution, but all are one `composer update` behind a
fix and there is no evidence the fork tracks upstream dependency bumps.

**Recommendation.** `composer update` the five packages to the fixed versions
(guzzle 7.15.2, psr7 2.12.3, phpspreadsheet >5.8.0, dompdf 3.1.6, smarty
4.5.7), re-run `composer audit`, and run the test suite. Plan a migration off
the abandoned packages, especially `yubico/u2flib-server`. Establish a
recurring dependency-audit step (see recommendation in §1.3.1 style — this is
a process gap, not a single bug).

#### SEC-18 — Outdated JS dependencies with known CVEs (MEDIUM aggregate, confidence high)

**Location.** `package-lock.json`. Method: `npm audit` (2026-09-15).

**What.** `npm audit --omit=dev` (shipped runtime deps) reports **5 moderate,
0 high/critical**:

| Package | Range | Fix | Issue |
|---|---|---|---|
| `dompurify` | ≤3.4.12 | 3.4.15 | XSS: IN_PLACE hook removal leaves a detached executable subtree — relevant because DOMPurify is the client-side HTML sanitizer |
| `jszip` | ≤3.7.1 | via `dwv` major bump | Prototype pollution + path traversal in `loadAsync` |
| `dwv` | 0.20.0–0.31.4 | 0.36.4 (major) | DICOM viewer; pulls the vulnerable jszip |
| `fflate` | 0.8.0–0.8.2 | patch | Infinite loop on malformed ZIP64 |
| `validate.js` | * | none | ReDoS; no upstream fix (abandoned) |

The full `npm audit` (including dev/build deps) shows 26 total (2 critical, 11
high), but those extra 13 are in the **build toolchain** (webpack/babel/test
tooling) and are not shipped to the browser, so they are a supply-chain /
developer-machine concern, not a runtime exposure.

**Impact.** The DOMPurify XSS and jszip path-traversal are the ones that touch
user-facing behavior (sanitization bypass; malicious archive handling in the
DICOM viewer). Moderate severity; reachability depends on which UI surfaces
feed untrusted HTML/archives.

**Recommendation.** Bump `dompurify` and `fflate` (non-breaking), plan the
`dwv` major upgrade to clear jszip, and replace or accept-and-document
`validate.js`. Keep dev-dependency advisories on a separate, lower-priority
track but do address the 2 critical build-chain ones before trusting CI
output.

#### SEC-19 — Production docker-compose ships weak default credentials (MEDIUM, confidence high)

**Location.** `docker/production/docker-compose.yml` — `MYSQL_ROOT_PASSWORD:
root` (line 13), and on the openemr service `MYSQL_ROOT_PASS: root`,
`MYSQL_PASS: openemr`, `OE_PASS: pass` (lines 37–41). Related:
`sites/default/sqlconf.php` ships `$login/$pass = openemr/openemr` (but with
`$config = 0`, the uninstalled-template marker, so the installer overwrites it
on setup).

**What.** The *production* compose file hardcodes every credential inline
rather than reading from an `.env`/secret: MariaDB root is `root`, the app DB
user is `openemr/openemr`, and the initial OpenEMR admin is `admin/pass`.
There is no variable indirection — an operator who runs
`docker compose -f docker/production/docker-compose.yml up` (which the file
name invites) gets all of these defaults live, and the DB root password is
fixed at `root` with no override path in the file at all.

**Impact.** Any deployment that uses this file as shipped exposes a known
admin login (`admin`/`pass`) and known DB credentials on a system holding PHI.
The header comment documents the defaults, which makes accidental production
use more likely, not less. This is an OpenEMR upstream default rather than a
fork-introduced bug, but it is a real gap for the "deploy it" stage of this
project and for any real HIPAA deployment.

**Recommendation.** Parameterize all credentials via `.env`/Docker secrets
with no committed defaults; force `OE_PASS`/`MYSQL_ROOT_PASSWORD` to be
supplied at deploy time (fail fast if unset); require an admin password change
on first login. For this project's deployment specifically, set strong values
before exposing the instance publicly.

#### SEC-20 — TLS private keys committed to the repository (LOW, confidence high)

**Location.** `docker/library/couchdb-config-ssl-cert-keys/{easy,insane}/*.pem`,
`docker/library/ldap-ssl-certs-keys/**`, `ci/nginx/dummy-key`, and sibling
dev-nginx keys. All are real PEM private keys (`BEGIN RSA PRIVATE KEY` /
`BEGIN PRIVATE KEY`).

**What.** Self-signed TLS key material for the CouchDB and OpenLDAP dev
containers and the CI/dev nginx is committed in cleartext. These are wired
only into the development stacks (`development-easy`, `-easy-redis`,
`-insane`) and CI — the `production` compose references none of them (grep
clean).

**Impact.** Low: because they are dev/CI-only and every checkout has the same
keys, they provide no confidentiality even in dev. The risk is (a) a dev/test
stack accidentally exposed to a network trusts a keypair whose private half is
public, and (b) normalization — committed `.pem` private keys train
contributors that this is acceptable, raising the chance a *real* key is
committed later. No production key is exposed.

**Recommendation.** Generate dev/CI certs at container build/first-run instead
of committing them; if kept for reproducibility, document clearly that they
are throwaway and never valid for any real deployment. Add a pre-commit/secret
scanner rule so a future real key is caught.

**Note (no finding).** OAuth2 signing keys are *not* committed — they are
generated at runtime via `RandomGenUtils` and stored under the site's
documents directory (`src/Common/Auth/OAuth2KeyConfig.php`). A broad grep for
API-key/token/secret literals in tracked non-vendor, non-test files returned
only constant *names* (e.g. config-option identifiers), no live secrets.

#### SEC-09, SEC-10 — Defense-in-depth items (INFO)

Both raised as candidates and rejected 0/3 as *not exploitable*, but both
are real code defects worth recording:

- `interface/super/edit_layout.php:598–634` (`copytolayout`) omits
  `CsrfUtils::checkCsrfInput` while every sibling branch has it, and performs
  `INSERT layout_options` plus `ALTER TABLE` on an attacker-chosen target
  layout. Not exploitable cross-site because the core session cookie ships
  `SameSite=Strict` (`src/Common/Session/SessionConfigurationBuilder.php:25`,
  applied via `HttpSessionFactory.php:72`).
- `interface/patient_file/front_payment_cc.php:85` echoes a
  `Money\Number` `InvalidArgumentException` message containing raw
  `$_POST['payment']` unescaped. Same `SameSite=Strict` reasoning: only the
  logged-in user can trigger it against themselves.

Also rejected in the first scan: CORS `Origin` reflection in
`src/RestControllers/Subscriber/CORSListener.php:57` —
`Access-Control-Allow-Credentials: true` is emitted only on the OPTIONS
preflight, never on the actual response, so browsers withhold credentialed
cross-origin responses. Not exploitable as described.

### 1.2b Authentication — manual review (task 1.2)

**Scope & method.** Manual code review (single-pass, not panel-verified like
the automated scans above) of `interface/login/`, `library/auth.inc.php`,
`src/Common/Auth/`, `src/Common/Session/`, `oauth2/`, `apis/`,
`src/RestControllers/`, and `portal/account/`. Covers 1.2.1 login flow,
1.2.2 session management, 1.2.3 OAuth2/API, 1.2.4 portal registration and
password reset.

**Facts recorded (no finding attached).** Password hashing is
`password_hash()`/`password_verify()` via `AuthHash.php` (bcrypt by default,
Argon2id/Argon2i/SHA512 selectable, admin-configurable cost), with
rehash-on-login. Brute-force lockout is real and server-side: 20 failed
logins/account, 100/IP, both auto-reset after 1 hour
(`src/Common/Auth/AuthUtils.php:1163-1231`, `library/globals.inc.php:2202-2228`).
Password expiry defaults to 180 days + 30-day grace
(`AuthUtils.php:1092-1103`); minimum length 9, **no character-class
complexity requirement** (info only — length-only policies are common and
not flagged as a standalone finding here). OAuth2 refresh-token rotation is
enforced by the underlying `league/oauth2-server` default
(`revokeRefreshTokens = true`), access tokens are 1h, refresh tokens 3
months (ONC minimum), auth codes 1 minute. Scope enforcement is centralized
in `AuthorizationListener::onRestApiSecurityCheck()`
(`src/RestControllers/Subscriber/AuthorizationListener.php:134-196`) with a
narrow, explicit public-endpoint allowlist and default-deny otherwise.
Portal self-registration and password reset use CSPRNG tokens
(`RandomGenUtils::produceRandomString`, `random_int`), 1-hour expiry,
single-use consumption, `hash_equals()` PIN comparison, and are designed to
avoid enumeration (identical responses for existing/non-existing accounts).

#### SEC-21 — No session ID regeneration on login (HIGH, confidence: manual review)

**Location.** `library/auth.inc.php:52-80` (staff); portal login path
(`src/Controllers/Portal/PatientPortalLoginController.php`) shows the same
pattern. CWE-384 (Session Fixation).

**What.** A full-tree grep found no call to `session_regenerate_id()` (or
Symfony's session `migrate()`) anywhere outside vendor/build artifacts. The
login success path authenticates via `AuthUtils::confirmPassword()`
(`auth.inc.php:62`), clears the failure counter, and continues using the
pre-existing session ID.

**Impact.** A session ID established before authentication (e.g. seeded via
a crafted link or shared kiosk) remains valid and gains full privileges
after the victim logs in — classic session fixation. Combined with the
core session cookie's `SameSite=Strict` this is harder to exploit remotely
than a bare fixation bug, but a local/network attacker able to set the
`OpenEMR`/`PortalOpenEMR` cookie pre-login (e.g. shared machine, XSS
elsewhere, response-splitting) can pre-authenticate a known session ID.

**Recommendation.** Call `session_regenerate_id(true)` (or the session
wrapper's equivalent) immediately after successful authentication, for both
core and portal login paths, before any privileged state is written to the
session.

#### SEC-22 — Session cookies not HTTPS-conditioned; core cookie not HttpOnly (MEDIUM, confidence: manual review)

**Location.** `src/Common/Session/SessionConfigurationBuilder.php:20-28`
(defaults), `:83-91` (`forCore`), `:115-122` (`forPortal`). CWE-614
(missing `Secure`), CWE-1004 (missing `HttpOnly`).

**What.** `cookie_secure` defaults to `false` and is never derived from the
request scheme, `HTTPS`, or `X-Forwarded-Proto` anywhere in
`src/Common/Session/` or `src/Common/Http/HttpSessionFactory.php` — it is a
fixed boolean per session type, and neither the core (staff) nor the
portal (patient) session overrides it to `true`. The core session
additionally sets `HttpOnly=false` explicitly (`:88`), which
`SessionUtil.php:8-14` documents as intentional, to let JavaScript read/
write the session cookie for a multi-login "restore session" feature.

**Impact.** On a deployment served over HTTPS without an environment-level
enforcement layer, both the staff and patient session cookies can still be
sent over a downgraded/plain-HTTP connection, since the flag is hardcoded
false rather than conditioned on the actual connection. The core cookie's
`HttpOnly=false` is a deliberate, documented tradeoff that also means any
XSS on a staff-facing page (however unlikely given other controls) can
read the session cookie directly, not just act via same-origin requests.

**Recommendation.** Derive `cookie_secure` from the effective request
scheme (respecting a configured trusted-proxy header) rather than hardcoding
`false`, at minimum for production deployment profiles. Re-evaluate whether
the `restore_session()` mechanism can be implemented without disabling
`HttpOnly` on the primary session cookie (e.g. a separate, narrowly-scoped
non-HttpOnly cookie carrying only what that feature needs).

#### SEC-23 — MFA exists but is not enforced on the primary staff login (MEDIUM, confidence: manual review)

**Location.** `src/Common/Auth/MfaUtils.php:20-241` (TOTP/U2F
implementation); `library/auth.inc.php` (staff login path, zero references
to `MfaUtils`); `src/RestControllers/AuthorizationController.php:874-911`
and `src/Common/Auth/OpenIDConnect/Repositories/UserRepository.php:109-128`
(the only call sites, both OAuth2/API-only).

**What.** `MfaUtils::isMfaRequired()` (`:75-78`) is per-user opt-in — it
checks whether the user has rows in `login_mfa_registrations`, with no
global "require MFA" switch (`library/globals.inc.php` has no matching
setting). The classic web login flow that every staff user hits
(`interface/login/login.php` → `library/auth.inc.php`) never calls
`MfaUtils` at all; MFA is only invoked when authenticating via the
OAuth2/API password grant.

**Impact.** A staff user with MFA credentials registered can still log
into the main OpenEMR web UI with just a username and password — MFA
provides no protection on the primary attack surface (credential-stuffing
or phished-password login to the clinical UI), only on the API path, which
is off by default (`oauth_password_grant`, see SEC finding table). There is
no way for an administrator to mandate MFA for staff web-UI access at all.

**Recommendation.** Wire `MfaUtils` into `library/auth.inc.php`'s staff
login success path when the authenticating user has MFA registrations, and
add an admin-configurable global to require registration (e.g. for
superuser/admin roles at minimum) before first login completes.

#### SEC-24 — No absolute session lifetime cap; no concurrent-session limit (LOW, confidence: manual review)

**Location.** `src/Common/Session/SessionTracker.php:24-90`; `timeout`
global (default 7200s, `interface/globals.php:626`).

**What.** `SessionTracker::isSessionExpired()` (`:38-56`) only compares
time-since-last-activity against the idle `timeout` global; the tracker row's
`created` timestamp (`:33`) is never checked against a maximum lifetime.
`session.gc_maxlifetime` (4h, `SessionUtil.php:96`) is PHP's storage
garbage-collection horizon, not an enforced application-level cap. Separately,
nothing in the login path (`auth.inc.php:52-80`) looks up or revokes existing
sessions for the same user before creating a new one — the `session_tracker`
table has no uniqueness constraint per user.

**Impact.** A continuously active session — legitimate or attacker-held —
can persist indefinitely with no forced re-authentication, and the same
credentials can be used to hold multiple simultaneous sessions with no
admin visibility or single-session enforcement. Low severity on its own;
it compounds SEC-21 (no ID regeneration) and any credential-theft finding
by removing a natural time bound on stolen-session usefulness.

**Recommendation.** Add an absolute session lifetime (e.g. require
re-authentication after N hours regardless of activity), and consider
optional single-session-per-user enforcement for high-sensitivity roles.

#### SEC-25 — Unauthenticated OAuth2 dynamic client registration, auto-enabled by default (MEDIUM, confidence: manual review)

**Location.** `src/RestControllers/AuthorizationController.php:287-410`
(`clientRegistration()`);
`src/Common/Auth/OpenIDConnect/Repositories/ClientRepository.php:73-88`
(auto-enable logic); routed with no bearer-token gate in
`src/RestControllers/Subscriber/OAuth2AuthorizationListener.php:167-170`.

**What.** RFC 7591 dynamic client registration at `/registration` is
reachable without any authentication (expected for the spec), and a new
client requesting only the default scopes (`openid email phone address
api:oemr api:fhir api:port`) is enabled immediately —
`hasScopesThatRequireManualApproval()` gates this on the global
`oauth_app_manual_approval`, which defaults to `'0'` (approval off).
Clients requesting `system/`/`user/` scopes are held for manual approval
and confidential clients must present `jwks`/`jwks_uri`
(`AuthorizationController.php:324-337`), so the sensitive-scope path is
already gated — only the default-scope path is currently unattended.

**Impact.** Any unauthenticated actor can self-register an OAuth2 client
that is immediately usable for default-scope API access (patient portal
API scopes, FHIR read of whatever those scopes cover), without
administrator review, unless the deployment has explicitly turned
`oauth_app_manual_approval` on. This is standard per the RFC but is a
meaningful exposure for a clinical system if the recommended/production
default for that global is not confirmed to be "on".

**Recommendation.** Confirm and document the production-recommended value
of `oauth_app_manual_approval` (default it to on for clinical deployments),
or at minimum rate-limit/log dynamic registrations and alert on volume, so
unattended client creation is visible even when auto-enabled.

#### SEC-26 — Portal self-service password reset trigger uses guessable PII, no dedicated rate limit (LOW, confidence: manual review)

**Location.** `portal/account/account.lib.php:242-302` (`resetPassword()`);
`account.php:103-124` (AJAX handler). Distinct from SEC-06 (staff-side,
unauthenticated-ACL credential overwrite) — this is the separate,
self-service, patient-facing flow.

**What.** The reset-request step identifies the target account by DOB +
first name + last name + email match (`account.lib.php:249-258`) —
commonly breached/guessable data — gated only by CSRF and Google reCAPTCHA
(`account.php:106-116`), with no per-IP/per-account throttle comparable to
the login lockout in `AuthUtils.php`. The design correctly avoids
enumeration (identical response regardless of match,
`account.lib.php:240-241,260-274`) and correctly requires the emailed
CSPRNG token + 6-digit PIN (checked via `hash_equals()`,
`PatientPortalLoginController.php:281-284`) to complete the reset, so this
is not a full bypass — the weak identity gate only controls whether a reset
email is *sent*, and the email still goes only to the address on file.

**Impact.** An attacker who knows or controls the victim's registered
email inbox, plus commonly available PII (DOB, name), can trigger reset
emails at will; absent rate limiting beyond reCAPTCHA, they could also
attempt to enumerate/guess the identity fields or flood a patient's inbox.
Low severity because the token+PIN step still gates actual takeover and
delivery is restricted to the email of record.

**Recommendation.** Add a dedicated rate limit (per-IP and per-account) on
the `reset_password` action independent of reCAPTCHA, mirroring the login
lockout pattern in `AuthUtils.php`.

### 1.3 Observations across scans

- **Both scans found the same defect class.** The perimeter scan found
  CWE-639 (portal patient reaches another patient's rows by id); the chart
  scan found CWE-862 (staff handler skips the ACL the UI applies). In both
  cases CSRF was the only gate, and CSRF is being treated as if it were
  authorization. It is not: the default token is issued to every logged-in
  session (`src/Common/Csrf/CsrfUtils.php:49–55`).
- **"Gate the menu, not the handler" is the dominant finding of the whole
  security audit.** It appears in the chart UI (SEC-03–06), across the report
  pages (SEC-13–16), and the same trust-CSRF-as-authz mistake underlies the
  portal findings (SEC-01–02). Ten of the sixteen findings are one root
  cause: authorization enforced at the UI layer that renders a link, not at
  the endpoint that acts. `src/Menu/MenuRole.php` consuming `acl_req` for
  display only, with no corresponding server-side enforcement, is the
  structural reason. This is the single most important thing for any new
  capability to internalize: never inherit authorization from the UI path
  that reached you.
- **The modern layer is not automatically safer.** The one HIGH finding
  (SEC-11) is in `src/Services/Search`, the PSR-4 typed code, not the
  legacy `interface/` tree. It is a design defect (column names from
  request keys) rather than a missed escape, so it survived the move to
  bound parameters. Legacy-vs-modern is not a useful proxy for risk here.
- **The searched-for classics did not surface.** Slice A was aimed at IDOR
  in chart *reads*, string-built SQL, reflected XSS, and upload path
  traversal. One low-effort pass found none of these in `patient_file`,
  `super`, or `documents.php`. That is weak evidence (see method caveats),
  not clearance; manual tasks 1.3.2, 1.3.3, 1.4.1 remain.
- **`SameSite=Strict` on the core cookie is doing real work.** Two
  candidates were rejected solely because of it. Any future change that
  relaxes it (e.g. to `Lax` for an embedded iframe or SSO flow) would
  re-open SEC-09/SEC-10 and likely more.

### 1.3.1 The `aclCheckCore` control — why it matters and how to audit it

**What it is.** `AclMain::aclCheckCore($section, $value, $user = '',
$return_value = '')` at `src/Common/Acl/AclMain.php:166` is OpenEMR's core
server-side authorization check — the modern wrapper over the legacy phpGACL
library. It answers "is this user allowed to do this?" and returns `bool`.

- `$section` / `$value` — the permission being asked for, an ACO
  (access-control object) category and subcategory: `('patients','demo')` =
  demographics, `('encounters','coding')` = encounter coding, `('acct','rep')`
  = accounting/reports, `('admin','super')` = superuser.
- `$user` — defaults to the logged-in user (`authUser` from the session).
- `$return_value` — the access *level*: `'view'`, `'write'`, `'addonly'`,
  `'wsome'`; empty means "any access at all."

Two behaviors the findings rely on: a **superuser always returns `true`**
(the check short-circuits), and if **no ACL entry matches, it returns
`false`** — deny by default, access granted only by an explicit allow, and an
explicit deny overrides an allow. This makes adding a missing check safe: it
never locks out admins and correctly denies roles that were never granted the
permission.

**Why it is the crux of the security audit.** OpenEMR's intended pattern is
that every page or handler calls `aclCheckCore` *itself*, at the top, before
doing any work:

```php
if (!AclMain::aclCheckCore('patients', 'demo')) {
    // render Access Denied via AccessDeniedHelper and stop
}
```

What the scans found is that many endpoints skip this and instead rely on the
**menu** to hide the link: `src/Menu/MenuRole.php` reads a menu item's
`acl_req` (from `interface/main/tabs/menu/menus/standard.json`) and only
decides whether to *render* it. Hiding a link is not enforcement — anyone who
types the URL, replays a request, or is a lower-privilege role reaches the
ungated handler and it acts. Every SEC-03–06 (chart UI) and SEC-13–16 (report
pages) finding is exactly this: the code should have called `aclCheckCore`
before acting and did not, so the recommended fix in each is literally to add
the correct `aclCheckCore(...)` gate. Ten of the sixteen security findings
reduce to this one root cause (see the "gate the menu, not the handler"
observation above).

**Implication for the AI capability.** Any new endpoint, tool, or service
method that acts on a patient must run its own `aclCheckCore` against the
principal (or use a scoped repository that does). It must never infer
authorization from the fact that the UI, menu, or a prior page allowed the
user to reach it, and it must never treat a valid CSRF token as authorization
(the token is issued to every logged-in session). For API/tool paths that
take a field/column from caller input, pair the ACL check with an allowlist
(see SEC-11).

**How to audit for missing checks at a future time.** The confirmed findings
are instances of a pattern, not a census. To find the rest cheaply:

1. **List candidate entry points** — top-level PHP scripts under `interface/`
   (especially `interface/reports/`, `interface/patient_file/`) and handlers
   in `library/ajax/`, plus `apis/routes/*` closures and
   `src/RestControllers/*`.

2. **Find scripts that never call the check.** From the repo root:
   ```bash
   # report/interface scripts with no aclCheckCore anywhere in the file
   for f in $(git ls-files 'interface/reports/*.php' 'interface/patient_file/**/*.php'); do
     grep -qL . "$f" 2>/dev/null
     grep -q 'aclCheckCore\|aclCheckIssue\|AccessDeniedException' "$f" || echo "NO-ACL: $f"
   done
   ```
   Each `NO-ACL:` line is a script that performs no server-side authorization
   of its own — a candidate. Not every one is a vulnerability (some are
   includes, some legitimately public), so triage each.

3. **Cross-check against the menu's declared requirement.** A script is a
   confirmed gap when `standard.json` (or another menu file) declares an
   `acl_req` for it but the script itself has no matching `aclCheckCore`. Grep
   the menu JSON for the script name to recover the intended
   `(section, value)`:
   ```bash
   grep -rn '"<script-basename>"' interface/main/tabs/menu/menus/
   ```

4. **Confirm reachability.** Verify the script authenticates only via
   `globals.php` (login, not ACL) and that any data it renders/exports is PHI.
   Note whether it acts on GET (worst — no form needed, e.g. SEC-14) or needs
   a POST + CSRF token (still trivially satisfied by the logged-in user).

5. **Record and fix.** For each real gap, add a finding (SEC-nn) and the
   one-line fix: `aclCheckCore(<the acl_req section,value>)` with
   `AccessDeniedHelper` at the top of the script, before any query. For
   handlers that trust a client-supplied `pid`/`encounter` (SEC-03/04/06),
   additionally scope the write to the session's patient rather than the
   POSTed id.

This procedure is captured as task 1.3.1 in `AUDIT_TASKS.md`.

### 1.3c Authorization — manual review (task 1.3)

**Scope & method.** Manual sweeps (single-pass, not panel-verified) applying
the §1.3.1 `aclCheckCore` procedure and the SEC-01/02 IDOR pattern to areas
the automated scans did not cover: `library/ajax/*`, `apis/routes/*` and
`src/RestControllers/*` (1.3.1); `portal/`, `library/ajax/`,
`interface/patient_file/` id/doc_id/pid handling (1.3.2); upload handlers in
`interface/super/`, `interface/billing/`, `library/documents.php`,
`library/edihistory/`, and the Documents/Carecoordination modules (1.3.3);
`src/Common/Logging/BreakglassChecker.php` (1.3.4); and
`interface/usergroup/` user/role administration (1.3.5).

#### SEC-27 — `person_search_ajax.php` has no ACL check on PHI search or person creation (MEDIUM, confidence high)

**Location.** `library/ajax/person_search_ajax.php:115` (`handleSearchPersons`),
`:353` (`handleCreatePerson`). CWE-862.

**What.** Session auth + CSRF (line 43) are the only gates — no
`aclCheckCore` call anywhere in the file. `handleSearchPersons()` runs raw
SQL across `person`/`patient_data`/`contact_telecom` and returns
name/DOB/phone/email to any logged-in user regardless of role (normally
`patients`→`demo`). `handleCreatePerson()` lets any authenticated user
INSERT new person/contact records. A caught exception at lines 95-108 also
echoes `$e->getTraceAsString()` to the client — stack-trace/path disclosure;
the file header literally reads "FULL DEBUG VERSION," suggesting debug code
left in production.

**Impact.** Any authenticated user, regardless of assigned role, can search
PHI demographics system-wide and create new person/contact records with no
authorization check.

**Recommendation.** Add `aclCheckCore('patients', 'demo')` (or equivalent)
to both handlers; remove the trace-string echo from the error response;
confirm whether this is dead "debug" code that should be deleted rather
than shipped.

#### SEC-28 — `library/ajax/upload.php` has no ACL check on document upload or fetch (MEDIUM, confidence high/medium)

**Location.** `library/ajax/upload.php:131-140` (core-user upload branch),
`:149` (`dicom_history_action('fetch', ...)`). CWE-862; distinct from
SEC-01 (the already-fixed portal-side IDOR in this same file).

**What.** Session + CSRF are verified, but no `aclCheckCore` call gates the
core-user branch: any authenticated staff user, of any role, can call
`addNewDocument()` against an arbitrary `patient_id`/`category_id` taken
from `$_GET`, uploading files into any patient's chart regardless of
whether they have document-write rights to that chart. The `fetch` action
returns raw base64 `document_data` for any `doc_id` supplied, with no
traced ownership check for the core (non-portal) branch.

**Impact.** Any authenticated staff account can write documents into an
arbitrary patient's record, and potentially read arbitrary document
content by `doc_id`. High-confidence on the missing-ACL-on-upload; medium
confidence on the fetch IDOR, since an outer caller filtering `doc_id` was
not fully ruled out.

**Recommendation.** Add `aclCheckCore('patients', 'docs', '', 'write')`
(or the appropriate ACO) before `addNewDocument()`, and verify `doc_id`
ownership/ACL before returning document content on `fetch` for core users,
mirroring the portal branch's `foreign_id === session pid` check already
fixed under SEC-01.

#### SEC-29 — `addlistitem.php` allows unrestricted writes to system reference lists (LOW, confidence medium-high)

**Location.** `library/ajax/addlistitem.php:70`. CWE-862.

**What.** Session + CSRF verified, no `aclCheckCore` call. Any authenticated
user can `sqlInsert` new rows into `list_options` for an arbitrary
`list_id` via GET — this table drives system-wide dropdowns (diagnosis,
billing, admin lists), normally an admin-only operation.

**Impact.** Data-integrity and low-grade privilege-escalation risk on
shared configuration rather than direct PHI exposure; GET for a
state-changing action compounds CSRF/logging concerns.

**Recommendation.** Add an admin-level `aclCheckCore('lists', 'default')`
(or equivalent) check; convert to POST.

#### SEC-30 — Patient chart notes scoped by request `docid`/`orderid`, not session pid (MEDIUM, confidence medium)

**Location.** `interface/patient_file/summary/pnotes.php:25-37`,
`pnotes_full.php:43-56`, `pnotes_full_add.php:37-146`. CWE-639.

**What.** All three derive `$patient_id` from an attacker-suppliable
`docid` (looked up against `documents.foreign_id`) or `orderid`
(`procedure_order`) instead of the session-bound `$pid`. Write actions in
`pnotes_full_add.php` (`addPnote`, `updatePnote`, `deletePnote`,
`reappearPnote`/`disappearPnote`) then operate against this derived
`$patient_id`. A squad ACL check (`AclMain::aclCheckCore('squads', ...)`)
runs against the derived patient, so this is a scoping gap layered on an
existing ACL, not a full bypass — a staff user with generic notes-write
rights can act on a different patient's notes than the one open in their
session, by supplying a `docid`/`orderid` belonging to (or enumerated for)
another patient, as long as that patient's squad membership doesn't itself
block them.

**Impact.** Cross-patient note read/write for staff outside the
patient/squad they believe they're acting on, undermining the assumption
that "the chart I have open is the chart I'm writing to."

**Recommendation.** Verify the derived `$patient_id` equals the session's
currently open chart pid before permitting any write action, or restrict
`docid`/`orderid`-derived context to read-only display.

#### SEC-31, SEC-32 — Upload handler validation gaps (LOW each, confidence medium)

- `interface/modules/zend_modules/module/Documents/.../DocumentsController.php:70-93`
  — the XML-upload type gate checks client-supplied `$file['type']`
  (request `Content-Type`), not a server-side MIME sniff; trivially
  bypassable, but bounded impact since accepted content still flows through
  the protected `Document::createDocument()` engine (no path-traversal or
  direct-execution route identified).
- `oe-module-faxsms` — `SignalWireClient::faxProcessUploads()`,
  `EtherFaxActions::faxProcessUploads()`, `RCFaxClient::faxProcessUploads()`
  use `basename()` (blocks traversal) but perform no MIME/extension check
  before `move_uploaded_file()` into `temporary_files_dir` (default
  `sys_get_temp_dir()`, outside the web document root per
  `library/globals.inc.php:100`). Not remotely exploitable as configured,
  but `temporary_files_dir` is admin-configurable, so a misconfiguration
  could move this inside the webroot.

**Recommendation.** Add server-side MIME/extension checks to both as
defense-in-depth; low priority given bounded current impact.

#### SEC-33 — Break-glass emergency access is unscoped, self-service, and unmonitored (MEDIUM, confidence high)

**Location.** `src/Common/Logging/BreakglassChecker.php:39-62`;
`library/classes/Installer.class.php:1413-1436` (default ACL grant);
`interface/usergroup/usergroup_admin.php` (activation UI).

**What.** Break-glass is implemented as an ACL group ("Emergency Login"),
not a distinct workflow. Granting it is a normal admin ACL-group
assignment with no justification/reason field and no approval step —
`Documentation/Emergency_User_README.txt` describes pre-creating a
deactivated account and flipping `active=1` "during emergency situations,"
entirely self-declared. The default install grants this group write access
to `admin/super` plus essentially every `patients` sub-ACO for all
patients (`Installer.class.php:1435`, comment: "Emergency Login user can do
anything") — i.e. de-facto superuser, not scoped to a patient, encounter,
or time window. Logging is generic (`security-administration-update`
events in the normal `log` table via `EventAuditLogger`); there is no
dedicated break-glass review screen, and no rate limiting or alerting on
repeated activation.

**Impact.** Once granted, break-glass access is effectively
unrestricted and indefinite, discoverable only by searching the general
audit log rather than a purpose-built review flow, with no technical
control forcing time-boxing, scope limits, or post-hoc justification —
weakening the audit trail HIPAA's emergency-access provision expects
(§164.312(a)(2)(ii), covered further in the compliance audit).

**Recommendation.** Add a justification/reason field captured at
activation, an expiry/auto-deactivation timer, and a dedicated break-glass
event report; consider narrowing the default ACL grant from
superuser-equivalent to the minimum needed for emergency chart access.

#### SEC-34 — Privilege escalation via `adminacl_ajax.php` group-membership endpoint (HIGH, confidence high)

**Location.** `library/ajax/adminacl_ajax.php:44-47` (ACL gate),
`:72-84` (`AclExtended::addUserAros()`); contrast with the correct
pattern in `interface/usergroup/usergroup_admin.php:50-70`. CWE-269.

**What.** `usergroup_admin.php` correctly blocks a non-superuser from
assigning any ARO group that itself grants `admin/super` — it checks
`AclExtended::isGroupIncludeSuperuser()` against every submitted group
before allowing the assignment (this is what makes it impossible to
self-elevate via *that* screen, and correctly blocks granting "Emergency
Login" too, since that group also carries `admin/super` per SEC-33).
`library/ajax/adminacl_ajax.php`, which backs
`interface/usergroup/adminacl.php` ("Administration → ACL → User
Membership") and performs the same underlying group-membership-add
operation, gates only on `aclCheckCore('admin', 'acl')` and has **no**
superuser-inclusion check — it will add any submitted username to any
submitted group, including "Administrators" or "Emergency Login."

**Impact.** Any account granted the `admin`/`acl` right (intended for
managing non-privileged ACL groups — e.g. a facility-scoped admin) but
*not* `admin`/`super`, can call `adminacl_ajax.php` with
`control=membership&action=add&name=<own username>&selection[]=Administrators`
and immediately gain superuser-equivalent access, bypassing the exact
protection enforced one screen over. This is the same "two paths to the
same privileged operation, only one of them checked" pattern as the
menu-vs-handler theme elsewhere in this audit — here the inconsistency is
between two admin-facing handlers rather than a UI link vs. its handler.

**Recommendation.** Port `AclExtended::isGroupIncludeSuperuser()` (or call
the same check `usergroup_admin.php` uses) into
`adminacl_ajax.php`'s membership-add path before persisting the
assignment; require `admin/super` specifically for any group whose ACL
includes `admin/super`. Treat as high priority — this directly enables
privilege escalation to superuser.

### 1.3d Data exposure vectors — manual review (task 1.4)

**Scope & method.** Manual samples (single-pass, not panel-verified) of
injection patterns, CSRF coverage, CORS/security headers, direct file
access, error/log leakage, and export/reporting surfaces — the six
sub-areas of task 1.4.

**1.4.1 Injection — no new findings.** Sampled `library/*.php` (excluding
`documents.php`) and `interface/main`, `interface/forms` (eye_mag,
track_anything, newpatient subfolders), `interface/orders`,
`interface/billing`, `interface/usergroup` for string-built SQL: all
sampled call sites were either parameterized or passed through
`add_escape_custom()`. One dead, commented-out SQLi pattern was found in
`interface/usergroup/usergroup_admin.php:514-532` (inside a block comment,
not reachable — noted only as a "don't reintroduce this" flag, not logged
as a finding). XSS: no Smarty templates exist (`templates/` is 100% Twig,
285 files); ~30 `|raw` usages in calendar templates were sampled back to
`CalendarViewModel::buildDayPrintEventContent()`
(`src/PostCalendar/ViewModel/CalendarViewModel.php:813-890+`), which
pre-escapes every user-controlled field via `text()`/`attr()` before
building the raw-marked string — verified safe for the primary pattern.
**Not covered in this pass**: `interface/main/calendar/*`,
`interface/patient_file/pos`, most of `library/report*.inc.php`, the bulk
of `interface/forms/*` beyond the three subfolders sampled, and ~25 of the
~30 `|raw` Twig sites (admin/category templates not individually traced).
This remains a sample, not exhaustive coverage, consistent with the scope
of a single pass noted throughout this document.

#### SEC-35 — Missing CSRF check on payment deletion (LOW, confidence high)

**Location.** `interface/billing/search_payments.php:50-69` (`DeletePayments`
POST handler). CWE-352.

**What.** No `CsrfUtils` reference anywhere in the file; the delete branch
runs on ACL pass alone (`AclMain::aclCheckCore('acct','bill')`, line 40)
before `payment_row_delete()`/`payment_row_modify()`. POST-only, so cross-site
exploitation additionally requires defeating `SameSite=Strict` on the core
session cookie — the sole layer of defense here.

**Recommendation.** Add `CsrfUtils::verifyCsrfToken()` before the delete
branch, matching the pattern used consistently elsewhere in `library/ajax/`.

#### SEC-36 — Missing CSRF check with GET-triggerable write (MEDIUM, confidence high)

**Location.** `interface/billing/ub04_dispose.php`,
`interface/billing/ub04_submit.php:19`,
`interface/billing/ub04_form.php:26`. CWE-352.

**What.** No CSRF check anywhere in these three files. Worse than SEC-35:
every parameter is read as `$_POST[...] ?? $_GET[...]`
(`ub04_dispose.php:22` pattern), and `ub04_dispose()` is called with no
request-method gate — so a pure GET request (e.g.
`ub04_submit.php?handler=payer_save&payerid=X&ub04id=...`) can trigger
`savePayerTemplate()`/`BillingUtilities::updateClaim()`, real UPDATEs to
`insurance_companies`/claims data. Unlike SEC-35, this endpoint has no
"POST-only" fallback defense — `SameSite=Strict` (which does block
cross-site GET navigation and background-GET cookie attachment in modern
Chrome/Firefox) is the *only* thing standing between this and exploitation,
and it depends on the cookie attribute being honored end-to-end (broken by
a proxy cookie rewrite, legacy/embedded webview, etc.).

**Recommendation.** Add `CsrfUtils::verifyCsrfToken()`; separately, stop
accepting state-changing parameters via GET — restrict `ub04_dispose()`'s
write handlers to `$_POST` only, so the GET-as-write design flaw is closed
independent of the CSRF fix.

#### SEC-37 — Application ships with no CSP/X-Frame-Options/Referrer-Policy outside login/portal, no app-level HSTS (LOW, confidence high)

**Location.** App-wide; the only headers present are `Content-Security-Policy:
frame-ancestors 'none'` and `X-Frame-Options: DENY` on
`interface/login/login.php:31-32` and `portal/index.php:20-21`.

**What.** CORS was re-verified as previously concluded: `CORSListener.php`
reflects `Origin` unrestricted (`:57`, `@TODO` acknowledged in-code) but
`Access-Control-Allow-Credentials: true` is emitted only on the OPTIONS
preflight (`:67`, gated on `onKernelRequest()` method check), never merged
into the actual response — so credentialed cross-origin data exposure is
not currently possible via this path (confirmed, not a new finding).
Separately: no CSP/X-Frame-Options/Referrer-Policy is set for the bulk of
the application — the main interface, patient portal pages beyond the
entry script, and REST/FHIR API responses ship none of these. HSTS is set
only at the Docker image's Apache layer (`docker/flex/openemr.conf:62`
etc., baked into `openemr/openemr:latest`/`:flex`) — a bare-metal or
non-Docker deployment gets no HSTS at all, since there is no equivalent
PHP-level `header()` call.

**Impact.** Clickjacking and content-injection defense-in-depth is limited
to two entry pages; the authenticated application surface (where PHI is
rendered) has no CSP or frame-busting header of its own, relying entirely
on session/ACL controls rather than browser-enforced defense-in-depth.
HSTS gaps affect only non-Docker installs.

**Recommendation.** Set CSP, `X-Frame-Options: DENY` (or CSP
`frame-ancestors`), and `Referrer-Policy: strict-origin-when-cross-origin`
globally (e.g. in the shared bootstrap `globals.php` or a response
listener), not per-entry-script; add an app-level HSTS `header()` fallback
for non-Docker deployments.

#### SEC-38 — API/OAuth/FHIR error responses leak exception messages (LOW, confidence high)

**Location.** `src/RestControllers/Subscriber/ExceptionHandlerListener.php:50-56`;
`src/RestControllers/AuthorizationController.php:628,1256,1416`; FHIR
operation controllers (`FhirOperationExportRestController.php`,
`FhirOperationDocRefRestController.php:116-123`). CWE-209.

**What.** Full stack traces are consistently kept server-side (logged, not
returned), but raw `$exception->getMessage()` text is returned to the
client in the JSON/OperationOutcome error body across the general REST
exception handler, the OAuth2 authorization controller, and FHIR operation
controllers. This is a narrower, lower-severity variant of the
already-recorded `person_search_ajax.php` full-trace leak (SEC-27).

**Impact.** Internal detail (e.g. DB driver text, library exception
messages) can leak to any API caller on error, aiding reconnaissance,
though not as severely as a full trace.

**Recommendation.** Return a generic error message to API clients; log
`getMessage()`/trace server-side only, per the project's own error-handling
standard (CLAUDE.md: "Never expose `$e->getMessage()` in user-facing
output").

#### SEC-39 — CCDA import may log PHI-bearing filenames (LOW, confidence medium)

**Location.** `src/Services/Cda/CdaTemplateImportDispose.php:2344,2371,2373`.

**What.** Logs the `file_name` of imported CCDA documents. Filenames in
this workflow commonly embed patient name/MRN by convention (not directly
confirmed — depends on the sending system's naming convention), which
would put PHI into the application log outside the structured audit-log
protections.

**Recommendation.** Confirm the actual filename conventions used in
production CCDA exchange; if they can contain PHI, log a generated/opaque
identifier instead of the raw filename.

#### SEC-40 — CCDA/QRDA export lacks visible per-patient authorization and rate limiting (MEDIUM, confidence medium)

**Location.**
`interface/modules/zend_modules/module/Carecoordination/src/Carecoordination/Controller/EncounterccdadispatchController.php:76`
(`indexAction`), batch download actions
(`downloadccda`/`downloadqrda`/`downloadqrda3`/`downloadqrda3_consolidated`).
CWE-862 (candidate).

**What.** Reached only through an authenticated session
(`interface/modules/zend_modules/public/index.php:28` requires
`globals.php`), but no explicit `acl_check()`/`aclCheckCore()` call was
found within `indexAction` scoping the request to a patient the caller is
authorized for — `patient_id`/`pids` (including a pipe-delimited batch
list) come straight from request parameters (lines 86, 161-166, 213-217).
No rate limiting on repeated single or batch export.

**Impact.** If confirmed (medium confidence — needs a runtime check against
whatever global request-pipeline ACL enforcement may exist for this
module), any authenticated user could export CCDA/QRDA for any patient by
id, and batch-export at will with no throttle.

**Recommendation.** Add an explicit per-patient ACL check
(`patients`/`docs` or equivalent) inside `indexAction` before generating
any export, and rate-limit batch export requests.

#### SEC-41 — Bulk FHIR system export has no abuse-rate limiting (INFO, confidence high)

**Location.** `src/RestControllers/FHIR/Operations/FhirOperationExportRestController.php`.

**What.** System-level bulk export correctly requires a `system/<resource>.read`
OAuth2 scope (`isValidResource()`, `AccessDeniedException` at
`:637`/`:642`), consistent with the SMART Bulk Data spec — this is an
intended, admin-granted capability, not an open hole. The only bounds found
are `MAX_EXPORT_TIME_INTERVAL` (30s/slice, `:43`) and
`MAX_DOCUMENT_ACCESS_TIME` (1hr, `:49`), which are execution/retention
limits, not request-rate limiting.

**Impact.** Any client granted a broad `system/*.read` scope can dump the
entire patient population repeatedly with no throttle — acceptable given
the scope is itself admin-controlled, but worth noting as an operational
control gap (logging/alerting on repeated full exports) rather than an
access-control bug.

**Recommendation.** Consider rate-limiting/alerting on repeated bulk-export
invocations per client, as defense-in-depth for over-broadly-scoped
clients (see also SEC-25, unauthenticated dynamic client registration).

#### SEC-42 — Unbounded patient-list CSV export enables scraping by any authenticated user (MEDIUM, confidence high)

**Location.** `interface/reports/patient_list.php:35-41,187-295`
(`form_csvexport`); same pattern in `patient_list_creation.php:241-295+`.
CWE-799 (missing anti-automation), distinct from SEC-13
(`patient_list.php`'s separate missing-ACL finding).

**What.** Any authenticated user can CSV-export the *entire* patient list
(name, DOB/last-visit, address, phone, `pubpid`) with no `LIMIT`/pagination
and no rate limit — only login-session + CSRF gates the export, and (per
SEC-13) not even an ACL check does. Even setting SEC-13 aside, an
authorized-but-limited user could still scrape the full patient roster
in one request.

**Impact.** Bulk PHI exfiltration in a single request by any authenticated
account, independent of role-appropriate "minimum necessary" access.

**Recommendation.** Paginate the export and/or cap row count per request;
rate-limit repeated exports per user; combine with the SEC-13 ACL fix.

### 1.3e PHI handling — manual review (task 1.5)

**Scope & method.** Manual review (single-pass) of transit/at-rest
encryption configuration, session/cache/queue/log stores for PHI outside
the normal ACL-protected clinical tables, and every outbound third-party
integration capable of carrying PHI — tasks 1.5.1 through 1.5.4.

**Facts recorded (no finding attached).** TLS is terminated in-container
via Apache (`docker/flex/openemr.conf:167-215`, `SSLEngine on`), in both
`docker/production/` and `docker/development-easy/` compose files — no
separate reverse-proxy TLS layer. Document/file encryption at rest is
controlled by `drive_encryption` (`library/globals.inc.php:1035-1040`),
**default ON**, enforced in `library/classes/Document.class.php:988-1005`
via a dual-key architecture (`src/Common/Crypto/CryptoGen.php`,
`KeySource.php`) — when off, files fall back to base64 only, not
encryption. E-prescribing/Surescripts integration does not exist in the
shipped code (only vestigial `erx_*` column names). **No LLM/AI API
integration exists anywhere in the shipped codebase** — confirmed by a
full-repo grep for provider names/hostnames; the only match is
`AI_INTEGRATION_PLAN.md` itself, a planning document for a not-yet-built
feature. This directly confirms `AI_INTEGRATION_PLAN.md`'s own "repository
facts" premise (relevant to task 6.5's cross-check). Fax/SMS/clearinghouse
integrations (RingCentral, EtherFax, SignalWire, Twilio, X12 SFTP) are all
off by default, admin-configured, and transport-secure (HTTPS/SFTP) except
where separately noted below.

#### SEC-43 — HTTP→HTTPS redirect disabled by default (LOW, confidence high)

**Location.** `docker/flex/openemr.conf:145-159` (same pattern in
`docker/release/openemr.conf`, `docker/binary/openemr.conf`).

**What.** The `<VirtualHost *:80>` block's `RewriteRule` forcing HTTPS is
present but commented out, with an explicit comment that an admin must
uncomment it. Plain HTTP on port 80 is served as-is by default.

**Recommendation.** Enable the redirect by default for production image
variants; document the opt-out for deployments that terminate TLS
upstream.

#### SEC-44 — Internal LDAP traffic plaintext despite provisioned TLS (LOW, confidence high)

**Location.** `docker/development-easy/docker-compose.yml:97`
(`gbl_ldap_host: 'ldap://openldap:389'`); LDAP container has
`LDAP_TLS_*` cert material configured (`:174-178`) but is connected to
over plain `ldap://`, not `ldaps://`.

**What.** TLS is provisioned server-side but unused by the app's default
connection string. Internal-Docker-network traffic, so exploitability
requires network-level access, but this is a config default worth
correcting, especially since it would be trivial to replicate the same
plaintext pattern in a non-Docker deployment with LDAP on a separate host.

**Recommendation.** Default to `ldaps://` (or STARTTLS) when LDAP TLS
material is configured.

#### SEC-45 — No default DB volume encryption at rest (INFO, confidence high)

**Location.** `docker/production/docker-compose.yml:11,63`.

**What.** The MySQL/MariaDB data volume is a plain named Docker volume
with no encryption driver configured — standard for most self-hosted
Docker deployments, relies entirely on host/cloud-provider disk
encryption. Recorded for completeness per the compliance audit's
encryption-at-rest requirements, not treated as a code defect.

#### SEC-46 — Backup archives are not encrypted (LOW, confidence high)

**Location.** `interface/main/backup.php` (SQL dump gzip at line 521,
`Archive_Tar`/`ZipArchive` at lines 374-388, 574-593).

**What.** Backups (DB dump + web directory archive) are compressed but no
`openssl`/GPG/password-protected-archive step exists in this script.
Whatever protection documents already had on disk (per `drive_encryption`,
default on) likely survives archiving as raw files, but the SQL dump
itself — including any plaintext PHI in `email_queue`/`notification_log`
(SEC-47) — is not separately encrypted.

**Recommendation.** Add an optional encrypted-backup mode (e.g. GPG
recipient key or archive password) for operators who need it; document
that backup storage must be secured independently.

#### SEC-47 — Notification tables store PHI in plaintext indefinitely with no ACL-gated viewer (HIGH, confidence high)

**Location.** `sql/database.sql:1666-1681` (`email_queue`);
`sql/database.sql:10093-10110` (`notification_log`); populated by
`library/classes/postmaster.php:84,106` and
`interface/modules/custom_modules/oe-module-faxsms/src/Notification/AppointmentNotificationRunner.php:252-256`.
CWE-311/CWE-284.

**What.** `email_queue.body`/`subject` and `notification_log.message`/
`patient_info` store rendered message content (appointment reminders,
patient communications) verbatim, in plaintext, with rows never deleted
after `sent=1` (`postmaster.php:128`) — indefinite retention. No
`interface/` screen was found reading either table, meaning there is no
application-level ACL check gating who can view this PHI; protection is
whatever raw DB access control exists outside the app's own ACL model —
the same access-control bypass pattern (a data path that skips the normal
per-patient authorization) that recurs throughout this audit (SEC-01/02,
SEC-08).

**Impact.** Anyone with DB access (or any future admin tool that reads
these tables) sees PHI-bearing message history for every patient with no
per-patient or role-based restriction, and it accumulates without bound.

**Recommendation.** Add a retention/purge policy for sent notifications;
if an admin UI is ever built to view these tables, gate it with the same
per-patient ACL model used for clinical data; consider encrypting `body`/
`message` at rest given it's a durable PHI store outside the audited
clinical schema.

#### SEC-48 — Audit log viewer uses coarse ACL instead of per-patient authorization (MEDIUM, confidence medium-high)

**Location.** `interface/logview/logview.php:29-31`;
`sql/database.sql:7758-7774` (`log.comments`, `log.user_notes`,
free-text); `sql/database.sql:12560-12568` (`log_comment_encrypt`, opt-in
comment encryption, off unless enabled).

**What.** The only check gating the audit-log viewer is
`AclMain::aclCheckCore('admin', 'users')` — a single global right, not the
per-patient/per-encounter authorization enforced for clinical data
elsewhere. Since `log.comments` can contain PHI-bearing free text
(disclosure reasons, note text) and comment encryption is opt-in
(default likely plaintext), any user with `admin/users` can browse
audit-trail PHI for every patient in the system regardless of whether
they're otherwise authorized to view that patient's chart.

**Impact.** The audit log — the very mechanism meant to detect
unauthorized access (relevant to the compliance audit's §164.312(b)) — is
itself a broad, under-scoped PHI exposure surface once an account has
`admin/users`.

**Recommendation.** Consider enabling `log_comment_encrypt` by default, or
at minimum documenting the tradeoff; for the viewer itself, this may be an
accepted design tradeoff (audit review inherently needs broad read access)
but should be explicitly documented as such and the `admin/users` grant
should be tightly restricted, with its own use logged.

#### SEC-49 — Portal one-time-auth caches full names in session (LOW, confidence medium)

**Location.** `src/Common/Auth/OneTimeAuth.php:324,328`
(`providerName`, `ptName` set from `fname`/`lname`).

**What.** Unlike the clinician-side session path
(`PatientSessionUtil::setPid()`, which stores only `pid`/`encounter` IDs —
the correct pattern), the patient-portal one-time-auth login caches full
provider and patient names directly in `$_SESSION`. Scope is narrow (a
patient's own portal session only holds their own name), but it is PHI
held outside the normal DB-table access path, and would additionally land
in Redis if a Redis session backend is configured
(`src/Common/Session/Predis/LockingRedisSessionHandler.php`).

**Recommendation.** Low priority given narrow scope; if a shared Redis
session store is used in production, confirm it is not exposed and apply
the same session hardening as SEC-21/22.

#### SEC-50 — QRDA/CQM export staging uses predictable filenames and world-writable directories (LOW, confidence medium)

**Location.** `src/Cqm/QrdaControllers/QrdaReportController.php:61,117,210,272,335`
(`'/out_' . time()`, `'/qrda_export_' . time()`, `chmod(..., 0777)` near
line 120). Contrast with the hardened pattern in
`src/Services/CDADocumentService.php:182-230` (random 16-byte names,
`0700` directories, `finally`-block cleanup with an explicit PHI-exposure
comment).

**What.** QRDA/CQM reports (which can embed multi-patient measure data)
are staged under predictable, `time()`-based paths in a `chmod 0777`
directory before being zipped and streamed, then cleaned up. On a shared
host, another local user/process could read or tamper with these files
during the window before cleanup.

**Recommendation.** Apply the same hardening already used for CCDA export:
random unguessable directory names, `0700` permissions, cleanup in a
`finally` block.

#### SEC-51 — SMTP email defaults to unencrypted transport (MEDIUM, confidence high)

**Location.** `library/classes/postmaster.php:24,209` (PHPMailer,
`SMTPSecure` from `SMTP_SECURE` global); `library/globals.inc.php:2474-2519`
(`SMTP_SECURE` default `''` = "None"; `SMTP_HOST` default `localhost`,
fully admin-configurable).

**What.** OpenEMR ships three configurable email transports
(`PHPMAIL`/`SENDMAIL`/`SMTP`, default `SMTP`), but `SMTP_SECURE` defaults
to no encryption — an admin must explicitly select SSL or TLS.
`SMTP_HOST` can point anywhere, so PHI-bearing notification content
(appointment reminders, portal notifications) can be routed to an
arbitrary third-party relay with no forced transport encryption unless an
admin actively configures it.

**Impact.** A default/lightly-configured deployment can send PHI-bearing
email in plaintext over the network to whatever mail relay is configured.

**Recommendation.** Default `SMTP_SECURE` to `TLS` and require an explicit
opt-out (with a warning) to send unencrypted, consistent with the "secure
by default" posture already applied elsewhere (e.g. EtherFax's
`http_verify_ssl` default-true pattern, noted during this same review).

#### SEC-52 — SMS content and API key placed in a GET query string (LOW, confidence high)

**Location.**
`interface/modules/custom_modules/oe-module-faxsms/src/Controller/ClickatellSMSClient.php:49`.

**What.** The Clickatell SMS client builds a GET request
(`https://platform.clickatell.com/messages/http/send?apiKey=%s&to=%s&from=%s&content=%s`)
with the API key and message content (which may include patient
name/appointment info) in the URL query string. The channel itself is
TLS-encrypted, so this is not a network-sniffing risk, but query strings
commonly end up in server/proxy access logs, browser-equivalent history,
or get exposed via `Referer` headers on redirects — a logging/retention
risk rather than a transport one.

**Recommendation.** Switch to a POST body if the Clickatell API supports
it; if not, ensure access logs on any intermediary do not retain full
query strings for this endpoint.

### 1.4 Not covered

**Never reached by any pass (automated or manual).**

- `ccdaservice/` (the separate Node.js CCDA generation service) — not
  reviewed at all; the PHP-side `Carecoordination` module that calls it was
  spot-checked (SEC-40), but the service's own code was not.
- `gacl/` internals (the underlying GACL library `AclMain` wraps) — reviewed
  only through its `aclCheckCore` call surface, never its own source.
- `sql/` migration files — not reviewed for injection/logic issues (schema
  content was used for finding tables/columns, not audited itself).
- `tests/` and vendored/third-party trees (`vendor/`, `node_modules/`, module
  vendor bundles) — explicitly out of scope throughout; CVE exposure from
  vendored dependencies is covered separately by SEC-17/18 (dependency
  audit), not by source review.
- `interface/billing/` beyond the files sampled for 1.4.1/1.4.2 (injection
  sample, CSRF sweep) and the upload-handler check (1.3.3, found no upload
  handler there) — the directory is large; only a targeted sample was
  reviewed, not every script.
- HL7 lab-order network destination — `interface/orders/*hl7*` generates/
  parses HL7v2 to local files; where those files travel after leaving this
  codebase (a separately configured interface engine such as Mirth) was not
  traced, since that infrastructure isn't in this repository.

**Sampled, not exhaustive (real coverage gaps within reviewed areas).**

- `library/ajax/*` — the 1.3.1 ACL sweep flagged ~33 files as having no
  local `aclCheckCore` call beyond the 3 confirmed findings (SEC-27/28/29);
  those 33 were spot-checked for session-auth presence but not individually
  confirmed to have concrete PHI/state-change impact. Flagged for a future
  follow-up pass, not treated as clean.
- `interface/forms/*` — only `eye_mag`, `track_anything`, `newpatient`
  subfolders were sampled for injection (1.4.1); the rest of the forms
  directory (dozens of per-encounter-type form handlers) was not.
- `interface/main/calendar/*` and most of `library/report*.inc.php` — not
  covered by the 1.4.1 injection sample.
- ~25 of the ~30 Twig `|raw` usages outside the primary
  `CalendarViewModel`-sourced calendar templates (e.g. calendar admin
  templates) were not individually traced back to their PHP source.
- CCDA/QRDA export authorization (SEC-40) is medium-confidence — no runtime
  verification was done against whatever request-pipeline ACL enforcement
  might exist for the `Carecoordination` zend module beyond what's visible
  in `indexAction` itself.
- Non-Docker/bare-metal deployments — most of this audit's infrastructure
  findings (TLS, headers, file-access protection) were verified against the
  Docker images this repo builds; a manual/non-Docker install's actual
  Apache/PHP configuration was not independently reviewed and may differ
  (flagged specifically for SEC-37's HSTS gap and the legacy `.htaccess`
  `Deny From All` syntax under 1.4.5).

**Explicitly out of scope for this pass.** Runtime/dynamic testing
(fuzzing, live penetration testing, dependency exploitation attempts) —
this audit is static code + config review only, cross-referenced against
the automated scans in §1.1. No attempt was made to actually run any
proof-of-concept exploit against the dev stack.

### 1.5 Security section wrap-up

**1.6.2 — Findings ranked by severity** (52 total: SEC-01–52; `*fixed*`
noted where applicable).

- **High (7):** SEC-01\* (portal doc IDOR), SEC-11 (REST search SQLi),
  SEC-12 (reports SQLi), SEC-17 (PHP dependency CVEs), SEC-21 (no session
  regeneration on login), SEC-34 (privilege escalation via
  `adminacl_ajax.php`), SEC-47 (PHI plaintext in `email_queue`/
  `notification_log`, no ACL gate).
- **Medium (23):** SEC-02\*, SEC-03, SEC-04, SEC-05, SEC-06, SEC-13,
  SEC-14, SEC-15, SEC-16, SEC-18, SEC-19, SEC-22, SEC-23, SEC-25, SEC-27,
  SEC-28, SEC-30, SEC-33, SEC-36, SEC-40, SEC-42, SEC-48, SEC-51.
- **Low (17):** SEC-07, SEC-20, SEC-24, SEC-26, SEC-29, SEC-31, SEC-32,
  SEC-35, SEC-37, SEC-38, SEC-39, SEC-43, SEC-44, SEC-46, SEC-49, SEC-50,
  SEC-52.
- **Info (5):** SEC-08, SEC-09, SEC-10, SEC-41, SEC-45.

**Reading the ranking.** Severity alone understates two things the
cross-audit ranking in task 6.2 should weight in: *reachability* (SEC-34 is
High severity but requires an already-privileged `admin/acl` account —
narrower reach than SEC-11's REST SQLi, reachable by any portal patient)
and *breadth* (SEC-03 through SEC-06, SEC-13 through SEC-16, and the
`library/ajax/` gaps SEC-27 through SEC-29 are ten-plus instances of one
root cause — "gate the menu, not the handler," §1.3 — and should likely be
weighted as one systemic finding with many instances, not ten independent
ones, when picking the top findings for the executive summary). SEC-47
(plaintext PHI in notification tables) and SEC-21 (session fixation) are
the two High findings least dependent on an attacker already having
elevated access, and are good candidates for the summary's top findings
alongside SEC-34 and SEC-11/12.

---

## 2. Performance audit

### 2.1 Scope & method
*(pending — tasks 2.x)*

### 2.2 Findings

### 2.3 Not covered

---

## 3. Architecture audit

### 3.1 Scope & method
*(pending — tasks 3.x)*

### 3.2 Findings

### 3.3 Not covered

---

## 4. Data quality audit

### 4.1 Scope & method
*(pending — tasks 4.x; all queries against the seeded Synthea dataset)*

### 4.2 Findings

### 4.3 Not covered

---

## 5. Compliance & regulatory audit

### 5.1 Scope & method
*(pending — tasks 5.x)*

### 5.2 Findings

### 5.3 Not covered
