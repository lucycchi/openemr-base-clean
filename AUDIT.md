# Security Audit

## Scope and method

Scan date: 2026-09-15. Tool: Claude Security plugin (v0.11.0), `low` effort, scoped to the attacker-reachable surface of this OpenEMR fork. Revision scanned: `a1494c5a` on branch `audit`.

The scan covered 436 tracked files (409 PHP) across the directories most exposed to untrusted input:

- `apis/`, `oauth2/`, `src/RestControllers/` — REST and FHIR API dispatch and controllers
- `src/Common/Auth`, `src/Common/Http`, `src/Common/Csrf`, `src/Common/Acl`, `src/Common/Session` — authentication, session, CSRF, and access-control stack
- `src/Controllers/Portal`, `portal/` — patient portal
- `library/ajax/`, `controllers/`, `interface/login` — AJAX endpoints, legacy controllers, login

This is roughly 5% of the repository by file count. Production code was the target; tests, fixtures, and vendored trees (notably `portal/patient/fwk/libs`) were background. At `low` effort one researcher read the scope as a single unit plus a dedicated secrets pass, so coverage inside the scope is fast triage rather than an exhaustive file-by-file read.

Four candidate vulnerabilities were raised. Each was reviewed by a three-voter verification panel (reachability, impact, defenses). Two survived unanimously; two were rejected unanimously. No code was executed; every finding is derived from reading the source.

Full machine-readable output (JSONL, SARIF, revision stamp) is in `CLAUDE-SECURITY-20260915-075916/`.

## Findings

### F1 — Portal patient can read and overwrite any document (HIGH)

**Location:** `library/ajax/upload.php:140`, function `dicom_history_action`
**CWE:** CWE-639 Authorization Bypass Through User-Controlled Key

**What was wrong.** The endpoint accepts `action=fetch` and `action=save` with a `doc_id` from POST. That id flowed straight into `SELECT document_data FROM documents WHERE id = ?` and the matching `UPDATE`. The dispatch ran for any session that had `pid` and `patient_portal_onsite_two` set — the normal portal login state — with no check that the document belonged to the requesting patient. The only gate before dispatch was a CSRF token check, which proves the request came from the caller's own session but says nothing about ownership.

**Why it matters.** A logged-in portal patient could enumerate document ids and read the stored `document_data` of any other patient's document, then overwrite it with arbitrary content. In an EMR that is a cross-patient PHI confidentiality breach and an integrity breach in one endpoint. The patient portal is by design exposed to the least-trusted authenticated users the system has, so a missing ownership check there is reachable by anyone who can register or be given a portal account. Severity was rated HIGH because the impact is severe and the only hurdle is holding a portal session.

**Fix applied.** Before dispatching `save` or `fetch` in a portal session, the endpoint now loads `documents.foreign_id` for the requested id and returns 403 unless it equals the session `pid`. Missing rows are also rejected. Core staff sessions are unchanged.

### F2 — Portal patient can write payment audit rows for another patient (MEDIUM)

**Location:** `portal/lib/paylib.php:189` (also lines 106, 145, 197)
**CWE:** CWE-639 Authorization Bypass Through User-Controlled Key

**What was wrong.** The AuthorizeNet, Stripe, `portal-save`, and `review-save` branches all read `$form_pid = $_POST['form_pid']` and passed it to `SaveAudit()` / `CloseAudit()` as the `patient_id` key. The session gate only required a logged-in portal patient; nothing compared `form_pid` to the session `pid`. The Sphere branch had already added that check, which is what made the omission in the sibling branches stand out.

**Why it matters.** A portal patient could create or overwrite payment audit records in `onsite_portal_activity` attributed to any other patient. That corrupts another patient's payment and audit trail, which staff rely on when reviewing pending payments. Rated MEDIUM because the impact is bounded to audit rows rather than money movement or PHI disclosure, and it requires the portal-payment CSRF token the patient legitimately holds.

**Fix applied.** All four branches now use `$form_pid = isset($pid) ? $pid : $_POST['form_pid']`. In a portal session `$pid` is always set from the session, so the POST value is ignored; the POST value is honored only for authenticated core staff, matching the existing pattern in `portal/portal_payment.php:115`.

## Rejected candidates

Two candidates were raised and unanimously rejected by the panel. They are recorded here so nobody re-investigates them from scratch.

- **CORS Origin reflection in `src/RestControllers/Subscriber/CORSListener.php:57`.** The `Origin` header is reflected verbatim into `Access-Control-Allow-Origin` on every API response. This looks like a credentialed-CORS hole, but `Access-Control-Allow-Credentials: true` is emitted only inside the OPTIONS preflight handler (`getInitialResponse`, line 67), never on the actual response path (`onKernelResponse`, lines 44–59). Browsers therefore withhold credentialed cross-origin responses from the attacker page. Not exploitable as described.
- A fourth candidate was likewise rejected 0/3; details are in the panel record that was consumed when the report was rendered.

## Status of fixes

Both fixes were applied directly to the working tree (not via the plugin's patch flow, because the scan was taken over a tree with uncommitted changes to `.gitignore` and `CLAUDE.md`, and the plugin refuses to build patches from a dirty-stamped report). `php -l` passes on both files. The DB-backed test suite has not been run against these changes.

## What was not scanned

Everything outside the scope above — in particular `interface/` (the bulk of the staff UI, except `login`), `library/` (except `ajax`), `src/Services`, `src/Common` beyond the five auth-related directories, `modules/`, `ccdaservice`, `gacl`, and `sql`. A grep for `$_FILES` shows upload handlers outside the scope in `interface/super/`, `interface/billing/`, `library/documents.php`, `library/edihistory/`, and the Documents and Carecoordination Zend modules; those were found by grep, not reviewed.

A follow-up `medium`-effort scan of `interface/`, `library/`, and `src/Services` (2859 files) was started on 2026-09-15 and is in progress at the time of writing. Its results will be appended here when it completes.

## Caveats

Scans are nondeterministic and complement, rather than replace, SAST, dependency scanning, and human code review. A clean result in a scanned area means "no confirmed finding in one pass," not proof of absence.
