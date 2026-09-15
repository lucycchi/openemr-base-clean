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

### 1.4 Not covered

*(To be completed after the manual tasks 1.2–1.5. Will list each unscanned
area, why, and the manual task that spot-checks it. Automated scan coverage:
see the "Coverage summary (scans)" table above — the perimeter, chart UI,
`super`, service layer, DB layer, and reports are scanned; the rest of
`interface/`, `library/`, and `src/` are not.)*

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
