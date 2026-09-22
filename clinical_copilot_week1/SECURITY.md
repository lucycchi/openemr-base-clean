# Security findings — Clinical Co-Pilot

Last scan: 2026-09-18, run `1789765085996-00c1d4f45f65f10a` (gstack `/cso`,
static, diff-scoped). Previous scan: 2026-09-16. This document explains what
was scanned, what was found, what was already fixed before the Co-Pilot was
built, and what remains open. Security terms are defined the first time they
appear and again in the [glossary](#glossary) at the end.

## Contents

1. [What was scanned and how](#1-what-was-scanned-and-how)
2. [Findings in the Co-Pilot code](#2-findings-in-the-co-pilot-code)
3. [What held up under review](#3-what-held-up-under-review)
4. [Mitigations already made in the OpenEMR base](#4-mitigations-already-made-in-the-openemr-base)
5. [Open issues in the base that the Co-Pilot inherits](#5-open-issues-in-the-base-that-the-co-pilot-inherits)
6. [Limits of this scan](#6-limits-of-this-scan)
7. [Glossary](#glossary)

---

## 1. What was scanned and how

**Tool.** The gstack `/cso` skill: a **static** audit, meaning it reads the
source code and reasons about what an attacker could do; it does not run the
application or send it hostile requests. Each candidate problem is written up
with the attacker, the boundary crossed, the impact, and the controls that
were checked before it counts as a finding. No independent reviewer agent was
available, so each finding was challenged by a second, sceptical pass by the
same author ("sequential challenge").

**Scope.** Everything that changed on the `audit` branch after the 2026-09-16
scan (about 40 commits), plus the unchanged code those changes call into
(login, session, CSRF, ACL, database helpers). Concretely:

| Area | Files reviewed |
|---|---|
| Co-Pilot HTTP endpoints | `public/chat.php`, `alerts.php`, `prewarm.php`, `ready.php`, `health.php` |
| Pre-warm feature | `Command/PrewarmCommand.php`, `Prewarmer.php`, `DbScheduleSource.php`, `DbPrewarmReceipts.php`, `WarmOutcome.php`, `FileRunLock.php`, `BriefingPipelineFactory.php` |
| Observability | `Ops/LangfuseTracer.php`, `Ops/StepRecorder.php`, `Ops/AlertReceiver.php`, `Ops/ReadinessProbes.php`, `Ops/FileReadinessStore.php` |
| Request parsing | `ChatRequest.php`, `Config.php`, `Controller/ChatController.php` |
| Deployment | `.gitlab-ci.yml`, `docker/vps/docker-compose.yml`, `docker/vps/deploy.sh` |
| Base fixes | `SearchFieldStatementResolver.php`, `adminacl_ajax.php`, `create_portallogin.php`, `ippf_statistics.php` |

**Severity scale** used below: *Critical / High / Medium / Low /
Informational*. Severity is about impact and how realistic the attacker's
prerequisites are in *this* application — not a generic score for the pattern.
**Confidence** is separate: how strongly the evidence supports the specific
claim. All findings below are "supported" — a complete code trace from an
attacker-controlled entry point to the impact — but none was reproduced at
runtime, so confidence is *medium*, not *high*.

---

## 2. Findings in the Co-Pilot code

Three findings, all **Low**. None allows reading or changing patient data.

### 2.1 Alert webhook accepted the shared secret in the URL — **fixed 2026-09-18**

**Where.** `public/alerts.php`, the endpoint that Langfuse alert rules POST to
when a metric crosses a threshold (see [ALERTS.md](ALERTS.md)).

**What a webhook is.** A URL that an external service calls to tell your
application something happened. Because it is called by a machine, not a
logged-in user, it cannot rely on a session cookie; it needs its own way to
prove the caller is legitimate.

**How the endpoint authenticates.** Two options, either one is enough:

- *Signed request* (preferred). Langfuse computes an **HMAC** — a hash of the
  request body mixed with a secret only Langfuse and the server know — and
  sends it in a header. The server recomputes it; a mismatch or a timestamp
  more than five minutes old is rejected. This proves the body was not
  tampered with in transit and cannot be replayed later.
- *Shared token* (fallback, for senders that cannot sign). A random secret
  (`ALERT_WEBHOOK_SECRET`, generated with `openssl rand -hex 32` and kept in
  the server's `.env` file) that the sender includes with each request.

**The problem.** The token was accepted from two places: the `X-Alert-Token`
HTTP header, *or* a `?token=…` query string appended to the URL. Query strings
are part of the URL, and URLs are written to web-server access logs, reverse
proxy logs, and any intermediary's logs, in plain text. Headers are not. So
the URL form quietly copies the secret into every log file along the request
path. Anyone who can read those logs — a support engineer, a log-aggregation
vendor, an attacker who has compromised the proxy — learns the secret.

**What an attacker gains.** With the secret, they can POST arbitrary alerts.
Each accepted alert is written to the application log and, more importantly,
to the **OpenEMR audit log** (`log` table) as a `clinical-copilot-alert`
event. The audit log is the tamper-evidence record that compliance reviews
rely on. Polluting it with fake "critical" alerts hides real ones (alert
fatigue) and undermines trust in the record. There is no path from this
endpoint to any patient data.

**Why Low.** The secret was never *stored* in the URL by the code; it only
leaked if an operator chose to configure the sender with the query form. The
header form and the signed form were always available. Impact is limited to
log and audit-trail integrity.

**Fix applied.** `alerts.php` now reads the token from the `X-Alert-Token`
header only; a correct secret in `?token=` is refused with `401 Invalid
token`. Docs (`ALERTS.md`, `docker/vps/.env.example`) no longer advertise the
query form, and Bruno request 19 in [api-collection/](api-collection/README.md)
asserts the refusal. Verified in-container: query form → 401, header form →
passes authentication.

**Operator follow-up.** If any Langfuse webhook was ever configured with
`?token=`, switch it to the signed form or the header and rotate
`ALERT_WEBHOOK_SECRET`, because the old value may already be in access logs.

### 2.2 `prewarm.php` is unauthenticated and discloses daily appointment volume

**Where.** `public/prewarm.php`, line 17 (`$ignoreAuth = true`).

**Background.** The morning **pre-warm** (see [DESIGN.md](DESIGN.md)) is a
cron job that generates briefings for every patient on the day's schedule
before clinic opens, so the first chart open is a cache hit. `prewarm.php`
exists so an alerting rule can tell "the 06:00 sweep did not run" apart from
"it ran and failed". Like `ready.php`, it deliberately needs no login so a
monitoring system can poll it.

**What it returns.** Whether pre-warm is enabled, and for the most recent run:
its id, target date, finish time, and how many patients were *scheduled*,
*warmed*, *already cached*, *skipped*, and *errored*.

**The problem.** Two things.

1. *Information disclosure.* "Scheduled" is the number of patients with
   appointments on that date — the clinic's daily volume. That is not
   patient-identifying (no names, ids, or dates of birth), but it is business
   information a competitor or scraper can collect for free every day, and the
   error count reveals operational health to anyone.
2. *Uncached database work for anonymous callers.* Every request runs a
   `GROUP BY` aggregate over the `copilot_prewarm` table. `ready.php` caches its
   probe results for 60 seconds precisely so anonymous polling cannot be used
   to load the server; `prewarm.php` has no such cache. The table is small (one
   row per scheduled patient per run), so this is a nuisance, not an outage
   vector.

**Why Low.** The data is aggregate counts with no patient identifiers, and the
query is cheap. It is reported because it is the same class as the `ready.php`
finding from the previous scan and it leaks strictly more than that endpoint
now does.

**Recommended fix.** Require the same operator token as `alerts.php` (or
restrict by source IP at the proxy), return only `ok`/`stale` plus the run's
age to anonymous callers, and cache the row for 60 seconds as `ready.php`
does. Not yet applied.

### 2.3 Raw exception messages are sent to Langfuse and stored in the pre-warm table

**Where.** `src/Ops/LangfuseTracer.php` line 61 (`statusMessage`),
`src/Ops/StepRecorder.php` line 66 (`describe()`), `src/Prewarmer.php` line 99.

**Background.** Every Co-Pilot request records a **trace** — a timeline of
steps such as "authorize and assemble facts", "call the model", "verify
sentences" — and exports it to **Langfuse**, a third-party SaaS used for the
dashboard and alerts ([DASHBOARD.md](DASHBOARD.md)). When a step fails, the
trace records why. The pre-warm sweep similarly records an `error` column per
patient in `copilot_prewarm`.

**The problem.** The "why" is the raw **exception message**: the text PHP
attaches to an error when it is thrown. `StepRecorder::describe()` concatenates
the exception's class name, its message, and the message of the exception that
caused it. Some of those messages carry internal detail:

- Database errors (`SqlQueryException`) embed the SQL statement that failed,
  including table and column names.
- HTTP client errors (Guzzle) embed the upstream URL and sometimes the response
  body.
- Filesystem errors embed server paths.

That text then leaves the server: to `cloud.langfuse.com`, where every member
of the Langfuse project can read it, and into a database column with no
retention limit. The browser is *not* affected — `ChatController` already
returns a generic "The Co-Pilot hit an internal error" to the user.

**Why this matters.** The Co-Pilot's design keeps **PHI** (protected health
information — anything that identifies a patient together with their health
data) inside the OpenEMR boundary except for the model call to OpenAI. Langfuse
is meant to receive only counts, ids, timings, and the clinician's username.
Exception messages are an unreviewed channel that can carry SQL and
infrastructure details across that boundary. No PHI *values* appear in these
messages by construction (queries are parameterised, so patient data is not
in the SQL text), which is why this is Low rather than Medium.

**Why Low.** Langfuse is operator-controlled, already receives usernames by
design, and the disclosed detail (schema names, URLs) is useful reconnaissance
rather than direct access.

**Recommended fix.** Export the exception *class name* and a stable error code
(`sql_error`, `llm_timeout`, …) to Langfuse and the `error` column; keep the
full message only in the server-side application log via the PSR-3
`exception` context key, which is the project's standard (CLAUDE.md: "never
expose `$e->getMessage()`"). Not yet applied.

### 2.4 Notes that are not findings

Recorded in the scan's coverage record because they are worth a decision, but
no security boundary is crossed:

- **`copilot_prewarm.fact_lines_json` stores clinical fact values.** Each
  pre-warm receipt keeps the fact lines it warmed (medication names, allergy
  names, problem names) keyed by patient id, so a chart open can explain a
  cache miss. `sql/install.sql` describes this as "no identifiers", which is
  true of *direct* identifiers (no names or DOBs) but the values are clinical
  content tied to a `pid`. It lives in the same database as the chart, behind
  the same access, so there is no new perimeter — but there is no retention or
  purge policy. Same consideration applies to `copilot_briefing_cache`.
- **Deploy SSH key inside the web container.** `docker/vps/docker-compose.yml`
  bind-mounts the GitLab read-only deploy key at `/root/.ssh` so the flex image
  can clone the branch. A remote-code-execution bug in the web tier would
  expose that key. It is read-only to one repository, and no such bug is
  known; recorded as a hardening opportunity.

---

## 3. What held up under review

Controls that were specifically challenged and found sound:

| Control | Where | What was checked |
|---|---|---|
| Patient chosen by the server, not the request | `ChatController` | `pid` comes from `PatientSessionUtil::getPid()` (the session), never from POST. A user cannot ask about a patient whose chart they have not opened. |
| Strict request shape | `ChatRequest` | Unknown POST fields are rejected; question capped at 500 chars, transcript at 10 turns of 1000 chars; `facts_hash` must be 64 hex characters. Limits what a hostile client can inject. |
| CSRF + authentication | `ChatController` | A missing or wrong `csrf_token_form` is refused (403) *before* any work; an unauthenticated session is refused (401). See [CSRF](#glossary). |
| ACL enforced in the tool layer | `FactAssembler` + `AclAuthorization` | Each chart section is checked against OpenEMR's ACL for the *current user*; a user without rights gets 403 and an audit event, regardless of how the request was made (Bruno requests 13–16 prove this). |
| Pre-warm respects ACL and cannot leak across users | `Prewarmer`, `BriefingPipelineFactory` | The cron job assembles facts under the *scheduled provider's* ACL, and the narration cache key is the hash of the facts. A clinician with a narrower view produces a different hash, so they never receive a narration built from data they cannot see. |
| Webhook authentication | `AlertReceiver` | Constant-time comparison (`hash_equals`) for the token; HMAC-SHA256 over `timestamp.body` with a 300-second window; 64 KiB body cap; JSON nesting depth cap. |
| Readiness cache hardened (previous finding) | `FileReadinessStore`, `ReadinessProbes` | Cache moved from the shared `/tmp` to the site's own `documents/` directory with `0700`/`0600` permissions and a symlink check; anonymous callers no longer see upstream HTTP codes that would reveal whether the OpenAI key is valid or over quota. |
| Model output cannot inject HTML | `panel.js` | Sentences are inserted with `textContent`, never `innerHTML`, so a model that emits `<script>` produces literal text. |
| CI/deploy | `.gitlab-ci.yml`, `docker-compose.yml` | Deploy runs only for `branch == audit`; images pinned by digest; SSH host key pinned via `known_hosts`; no attacker-controlled values interpolated into shell. |

---

## 4. Mitigations already made in the OpenEMR base

The Co-Pilot was designed against a security audit of the unmodified OpenEMR
base ([AUDIT.md](../AUDIT.md)), which logged 52 findings, SEC-01 to SEC-52.
Six were fixed on this branch — chosen because they were High severity or sat
directly under a path the Co-Pilot uses. Each fix was reproduced before and
after on the dev stack.

| ID | Sev | What was wrong | Fix | Why it matters to the Co-Pilot |
|---|---|---|---|---|
| **SEC-01** | High | A portal patient could read or overwrite *any* patient's document by changing `doc_id` in the request (an **IDOR**). | `library/ajax/upload.php` binds the document to the session's patient. | Portal patients share the same document engine; a cross-patient read here would bypass every ACL the Co-Pilot relies on. |
| **SEC-02** | Med | A portal patient could write payment-audit rows for any `form_pid`. | `portal/lib/paylib.php` ignores the request's `form_pid` and uses the session pid. | Same IDOR class. |
| **SEC-06** | Med | Any logged-in staff user could reset any patient's portal password and log in as them. | `create_portallogin.php` now requires `patients/demo` write permission plus the patient's squad ACL. | Closes a route to impersonating a patient. |
| **SEC-11** | High | REST search *parameter names* were pasted into SQL as column names (**SQL injection**), reachable by portal patients via `/employer`. | `SearchFieldStatementResolver` rejects any field that is not a bare `column` or `table.column` identifier, in all four `resolve*` methods; controllers use allowlists. Confirmed present in this scan. | The Co-Pilot's fact assembly uses the same service/search layer. |
| **SEC-12** | High | `form_facility` was string-interpolated into the IPPF statistics query. | Bound as a `?` parameter (`ippf_statistics.php` lines 1289 and 1455). | Any SQL injection is a full-database read of PHI. |
| **SEC-34** | High | A user with the `admin/acl` right (but not superuser) could add themselves to the Administrators group. | `adminacl_ajax.php` refuses, with an audit event, when a non-superuser touches a superuser-granting group or the `admin/super` ACO. | The Co-Pilot's whole authorization story assumes the ACL cannot be escalated by its own administrators. |

Also fixed on this branch, from the 2026-09-16 Co-Pilot scan: the
`ready.php` finding (readiness cache in a world-writable temp path; upstream
status codes revealed to anonymous callers). See §3, "Readiness cache
hardened".

---

## 5. Open issues in the base that the Co-Pilot inherits

46 of the 52 base findings remain open. These are outside the Co-Pilot's code
but affect it; the ones most relevant, in priority order:

| ID | Sev | Issue | Relevance |
|---|---|---|---|
| SEC-21 | High | No session-ID regeneration on login (**session fixation**). | The Co-Pilot trusts the session for user and patient identity. |
| SEC-17 | High | Four High composer advisories (Guzzle host-check bypass, PhpSpreadsheet DoS/SSRF). | The Co-Pilot's OpenAI and Langfuse clients are Guzzle. |
| SEC-08 | Info | `set_pid` binds the session to any patient with no ACL check. | The Co-Pilot's "pid from session" invariant is only as strong as this primitive; the ACL check in `FactAssembler` is the compensating control. |
| SEC-47 | High | PHI-bearing `email_queue` / `notification_log` tables with no ACL-gated viewer. | Same class as the retention note on `copilot_prewarm` (§2.4). |
| SEC-28 | Med | No ACL on core-user document upload. | |
| SEC-38 | Low | REST/OAuth/FHIR error responses return raw `getMessage()`. | Same root cause as finding 2.3, at the API boundary. |
| SEC-22 | Med | Session cookies ship `Secure=false`. | |

---

## 6. Limits of this scan

- **Static only.** No qualified container runtime was available, so nothing
  was reproduced by sending real requests; the `alerts.php` fix in §2.1 was
  verified separately in-container.
- **No scanners.** Gitleaks (secrets), OSV (dependency CVEs), Semgrep (pattern
  rules) were not run; the dependency picture is from the 2026-09-15 base audit.
- **Diff-scoped.** Unchanged legacy code was not re-audited.
- **Git history not assessed.** The helper could not ingest the repository's
  history, so historical secrets were not checked.
- **Model behaviour untested at runtime.** Prompt-injection resistance (chart
  text or a follow-up question trying to override instructions) is covered by
  eval cases 12–15 in [EVALS.md](EVALS.md), which are self-reported, not
  adversarial fuzzing.
- **Sequential challenge only.** No independent reviewer agent.

---

## Glossary

**ACL (access control list).** OpenEMR's permission system. Each user belongs
to groups; each group is granted *ACOs* (access control objects) such as
`patients/med` (may view medications) or `admin/super` (superuser). Code asks
`AclMain::aclCheckCore('patients', 'med')` before showing data.

**Audit log.** OpenEMR's `log` table, written by `EventAuditLogger`. Records
who did what to which patient and when. Tamper-evidence for compliance; a
polluted audit log is a security problem even when no data was touched.

**Boundary.** A line the design says data or authority must not cross without
a check: unauthenticated → authenticated, one patient → another, inside the
server → a third party. A finding needs a *crossed* boundary; a pattern alone
is not enough.

**CSRF (cross-site request forgery).** Tricking a logged-in user's browser into
sending a request they did not intend (e.g. from a hostile page). Defended by
a per-session `csrf_token_form` value that a foreign page cannot know, plus
`SameSite=Strict` cookies.

**Constant-time comparison (`hash_equals`).** Comparing two secrets in a way
that takes the same time whether they differ in the first byte or the last, so
an attacker cannot learn the secret one byte at a time from response timing.

**Exception message.** The human-readable string attached to a PHP error
(`$e->getMessage()`). Often contains internal detail; the project standard is
to log it server-side and show users a generic message.

**HMAC.** A keyed hash: `hash(secret + message)`. Proves a message was produced
by someone holding the secret and was not altered. Used by Langfuse to sign
webhook bodies.

**IDOR (insecure direct object reference).** Letting the client name the record
it wants (`doc_id=42`) and trusting that without checking it belongs to them.
SEC-01 and SEC-02 were IDORs.

**`$ignoreAuth`.** An OpenEMR convention: a PHP entry point that sets this
before including `globals.php` skips the login check. Used by
`health.php`, `ready.php`, `prewarm.php`, and `alerts.php` because machines,
not users, call them.

**PHI (protected health information).** Under HIPAA, any health information
that can be linked to an individual. Names, dates of birth, medication lists
tied to a patient id all count. The Co-Pilot sends PHI to OpenAI (under the
operator's BAA) and is designed to send none to Langfuse.

**Pre-warm.** The `copilot:prewarm` console command and cron job that
generates briefings for the day's scheduled patients before clinic opens. Its
receipts live in `copilot_prewarm`; its status endpoint is `prewarm.php`.

**Query string.** The `?key=value` part of a URL. Logged in plain text by web
servers and proxies, which is why secrets must never be placed there.

**Severity vs confidence.** Severity: how bad, given realistic attacker
prerequisites here. Confidence: how sure the evidence is. A Low/medium finding
is a real but small problem that was traced in code, not demonstrated live.

**Session fixation.** An attacker plants a session id in the victim's browser
before login; if the server keeps the same id after login, the attacker now
holds an authenticated session. Prevented by regenerating the id at login
(open as SEC-21).

**SQL injection.** Untrusted input becoming part of the SQL *statement* rather
than a bound *value*, letting the attacker rewrite the query. Prevented by
parameter binding (`?` placeholders) and, for identifiers that cannot be bound,
by allowlists or strict regexes (SEC-11, SEC-12).

**Static analysis / static audit.** Reasoning about the code without running
it. Finds what a trace can prove; cannot prove what happens only at runtime.

**Supported finding.** The `/cso` term for a finding with all four parts: an
attacker-controlled entry point, a path across a boundary, a demonstrated
impact, and a challenge of the controls that should have stopped it. Anything
short of that is a *hypothesis* and is not reported as a vulnerability.

**Trace (observability).** A record of one request as a timeline of named
steps with durations and outcomes, exported to Langfuse. Distinct from a
*code trace* (following a value from entry point to sink in source).

**Webhook.** A URL an external service calls to notify your application of an
event. Authenticated by a token or a signature, not a user session.
