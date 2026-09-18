# ALERTS.md — Clinical Co-Pilot alert definitions

Three alerts page someone; two more are watched but do not page. All are
computed from the Langfuse traces the module writes on every request (one
trace per request, one span per tool step, one generation per model call;
see [ARCHITECTURE.md § Observability](../ARCHITECTURE.md#observability)). Every
firing is POSTed to the module's own webhook receiver so the alert is
recorded next to the requests that caused it.

## Where an alert goes when it fires

```
Langfuse alert rule ── webhook POST ──► public/alerts.php
                                          ├─ app log:   WARNING "copilot alert received" {alert, severity, value, threshold, correlation_id}
                                          ├─ audit log: event clinical-copilot-alert, comment "alert=… severity=… value=… threshold=… correlation_id=…"
                                          └─ 200 {received: true, alert, severity, correlation_id}
```

- **URL:** `https://<host>/interface/modules/custom_modules/oe-module-clinical-copilot/public/alerts.php`
  (deployed: `https://146-190-139-37.sslip.io/interface/modules/custom_modules/oe-module-clinical-copilot/public/alerts.php`).
- **Auth (preferred): Langfuse's signed webhook.** When the webhook is
  created, Langfuse shows a signing secret (`lf-whs-…`) once; it goes in
  the droplet's `.env` as `LANGFUSE_WEBHOOK_SECRET`. Every delivery carries
  `x-langfuse-signature: t=<unix seconds>,v1=<hex>` where `v1` is
  HMAC-SHA256 over `"<t>.<raw body>"`. The receiver recomputes it
  (constant-time compare), rejects a mismatch (`Invalid signature`, 401) and
  a timestamp more than 5 minutes off (`Signature expired`, 401), so a
  tampered or replayed body is refused. No custom header is needed in
  Langfuse.
- **Auth (fallback): shared token.** `X-Alert-Token: <ALERT_WEBHOOK_SECRET>`
  (or `?token=`) for senders that cannot sign. Either credential is
  sufficient; with neither configured the receiver answers 503. Other
  responses: 400 non-object body, 413 over 64 KiB, 405 non-POST.
- **Payload:** the receiver is tolerant of shape. It lifts the alert name
  from `alert.name` / `name` / `title` / `alertName`, severity from
  `alert.severity` / `severity` / `status`, the value and threshold from
  `metric.value` / `value` and `metric.threshold` / `threshold`, and records
  the remaining top-level keys by name only (no values), so an alert can
  never write anything sensitive into the logs.
- **Contract:** [`contracts/alerts.response.schema.json`](../interface/modules/custom_modules/oe-module-clinical-copilot/contracts/alerts.response.schema.json).
- **Try it:** collection requests 17 (accepted) and 18 (wrong token) in
  [`api-collection/`](api-collection/README.md); unit tests
  `AlertReceiverTest`.

## The three paging alerts

Trace fields referenced below are written by `ChatController` on every
request: `duration_ms`, `http_status`, `status`, `verification_pass`,
`llm_attempts`, `llm_retried`, `from_cache`; spans carry `level`
(`DEFAULT` / `ERROR`) and `name`.

### 1. p95 latency

| | |
|---|---|
| **Metric** | Langfuse: data source **Observations**, metric **p95 latency**, filter name = `copilot.brief` or `copilot.ask` (the trace-level observations; the `llm.*` spans are excluded so the number is the physician's wait, not the model's) |
| **Window** | 15 minutes, evaluated every 5 minutes, at least 10 traces in the window |
| **Threshold** | > 15 000 ms |
| **Severity** | critical |
| **Baseline** | p50 ≈ 2.1 s, p95 ≈ 14.4 s cold (one retried model call in ten); ≈ 1 ms on a cache hit. Load-test baselines in [BASELINES.md](BASELINES.md) once captured. |

**What it means.** Physicians are waiting more than 15 s for the summary
on one request in twenty. The fact table is already on screen (it is
rendered before the model runs), so this is a degraded experience, not a
safety problem — but it is the premise of the product (the 90-second
window) failing.

**On-call response.**
1. `GET /ready` on the deployed host. `openai: openai unreachable |
   unavailable` → provider incident; check status.openai.com. Nothing to
   fix locally; the retry and the 25 s budget already bound the damage.
2. In Langfuse, split the window by `llm_retried`. A jump in retried calls
   with 429s in the `llm.*` span `statusMessage` means rate limiting:
   confirm spend/RPM in the OpenAI dashboard, consider a temporary model
   fallback via `OPENAI_MODEL`.
3. If `llm_duration_ms` is normal but `duration_ms` is high, the time is in
   `authorize_and_assemble_facts`: check MariaDB (`docker stats`, slow
   query log) and whether one patient's chart is orders of magnitude larger
   than the seed (the per-category cap of 50 should prevent this).
4. If the `cache_lookup` `hit` rate has dropped to zero, `Prompt::VERSION`
   was bumped by a deploy and every chart is cold; expected for the first
   hour after a deploy — silence, do not act.

### 2. Error rate

| | |
|---|---|
| **Metric** | Langfuse: data source **Scores (boolean)**, score **`request_ok`**, metric **share of `true`**. `request_ok` is written on every trace by `LangfuseTracer`: false when `http_status >= 500` or when a served request ended with a non-null `status`; a 4xx refusal is `true` (correct behaviour). Alert on the share falling **below 95 %** — equivalent to error rate > 5 % |
| **Window** | 15 minutes (Langfuse "Window"), at least 10 requests |
| **Threshold** | share of `true` < 0.95 |
| **Severity** | critical |
| **Baseline** | 0 % over the eval runs and the collection; the one expected non-null `status` in normal operation is `AI summary unavailable: provider busy` during an OpenAI incident |

`status` non-null means the request completed but could not deliver a
summary (`provider busy`, `provider error`, `timed out`, `model declined`,
`malformed response`, `not configured`). `http_status >= 500` means the
request itself failed (`copilot request failed` in the log, with a stack
trace under the same correlation id). 403s (ACL, CSRF) and 400s are **not**
errors for this alert; they are counted by alert 5 below.

**What it means.** More than one request in twenty is not producing a
summary. Physicians still see the fact table; the AI half of the product
is down or degraded.

**On-call response.**
1. Read the `status` distribution for the window in Langfuse.
   - All `provider *` / `timed out` → OpenAI incident or key/quota problem.
     `GET /ready`; check the OpenAI dashboard for a revoked key or exhausted
     budget; rotate `OPENAI_API_KEY` in `.env` and
     `docker compose up -d --force-recreate openemr` if the key is the cause.
   - `malformed response` or `model declined` → the model or the schema
     changed. Confirm `Prompt::VERSION` matches the last deploy; re-run
     `tests/evals/run.php --live` locally; if OpenAI changed model behaviour,
     pin the previous model snapshot via `OPENAI_MODEL`.
   - `http_status 500` → open the app log for the correlation ids
     (`grep "copilot request failed"`); this is a code or database fault
     and needs a fix or a rollback (`git checkout <previous sha>` on the
     droplet and recreate the container).
2. Never clear the briefing cache as a remedy: it holds only verified
   narrations and the cache is what keeps the product usable during a
   provider incident.

### 3. Tool failure rate

| | |
|---|---|
| **Metric** | Langfuse: data source **Scores (boolean)**, score **`tool_ok`**, metric **share of `true`**. `tool_ok` is false when any step span of the request (`authorize_and_assemble_facts`, `warm_lookup`, `cache_lookup`, `llm.briefing`, `llm.follow_up`, `verify`, `cache_store`, `omission_guard`, `scope_check`) recorded an error. Alert on the share falling **below 98 %** — equivalent to a request-level tool failure rate > 2 % |
| **Window** | 15 minutes (Langfuse "Window"), at least 20 requests |
| **Threshold** | share of `true` < 0.98 |
| **Severity** | warning (critical if the failing span is `authorize_and_assemble_facts` or `verify`) |
| **Baseline** | 0 % outside OpenAI incidents; an OpenAI incident shows as ≈ 1 failed span per request (`llm.*`), i.e. 15–25 % |

**What it means.** One of the agent's tools is throwing. Which one matters
more than the rate:

| Failing span | Meaning | Response |
|---|---|---|
| `llm.briefing` / `llm.follow_up` | model call failed after its retry | same as alert 2, step 1 |
| `authorize_and_assemble_facts` with `AccessDeniedException` | ACL refusals; expected for `receptionist`/`accountant`, a spike from one user is probing or a misconfigured role | audit log by user; do not change ACLs during the incident |
| `authorize_and_assemble_facts` with any other exception | database or data fault (bad date, missing table) | app log stack trace; P1 because no facts means no panel |
| `cache_lookup` / `cache_store` | `copilot_briefing_cache` table unreachable or full | MariaDB health; the request still completes without the cache |
| `verify` / `omission_guard` | pure PHP threw — should be impossible | P0: a code defect in the safety layer; roll back |

## Watched, not paged

### 4. Verification pass rate

`verification_pass` is also written as a boolean score on every served
request (false when every sentence the model produced was stripped —
`total_failure` — or the request ended with a non-null `status`). Tracked
on the dashboard; a rate above 10 % over
an hour is investigated during working hours as a prompt or model drift
problem (see [KEY_METRICS.md § 1](../KEY_METRICS.md)). Not paged because the
physician is protected either way: nothing unverified is shown.

### 5. Refusal rate

403s from `chat.php` (`denied: true` on the trace, `copilot access denied`
in the log). A refused request that returns facts is impossible by
construction and would be P0; the collection's request 16 and the eval
suite check it on every run. A spike of refusals from one user is reviewed
in the audit log.

### 6. Morning pre-warm (only once it is turned on)

The pre-warm sweep (`copilot:prewarm`, [design](../docs/designs/copilot-morning-prewarm.md))
is **available but not turned on** on the droplet: no cron is installed and
`COPILOT_PREWARM_ENABLED` is unset. These rules apply from the day it is
enabled; until then `prewarm.php` reports `"enabled": false` and nothing
below fires.

| | |
|---|---|
| **Signal** | `GET /interface/modules/custom_modules/oe-module-clinical-copilot/public/prewarm.php` (no auth, counts only, [contract](../interface/modules/custom_modules/oe-module-clinical-copilot/contracts/prewarm.response.schema.json)): `enabled`, and `last_run` with `target_date`, `finished_at`, `scheduled`, `warmed`, `already_cached`, `skipped`, `errored` |
| **Rule A: did not run** | at 06:30 site-local on a clinic day, `enabled` is true and `last_run.target_date` is not today. The cron did not fire, refused to run as root, or the site was down. Page; the first opens of the day will be cold (3-6 s) rather than instant, nothing is unsafe |
| **Rule B: ran with errors** | `last_run.errored > 0`. Each errored patient has a receipt row in `copilot_prewarm` with the exception message; the command's stdout (cron log) names them too. Warning; those patients simply get a cold briefing at open |
| **Rule C: hit rate** | Langfuse: data source **Scores (boolean)**, score **`warm_hit`**, metric **share of `true`**, window the previous clinic day. `warm_hit` is written only on chart opens that had a receipt to compare against (or when the sweep is enabled), so sites without the sweep report nothing. Below 50 % for three days: ticket, and read the reason histogram (`warm_reason` on the trace: `no_row`, `prompt_version`, `model_changed`, `viewer_differs`, `hash_drift`) before changing anything |
| **Baseline** | dev container, 2026-09-18: 2 scheduled, 2 warmed, 0 errored in 3.8 s; every open of a warmed chart the same morning hit, including after check-in |

The lock file `sites/<site>/documents/copilot/prewarm.lock` is `flock()`-held
only while a sweep runs; a second invocation prints
`another pre-warm run holds the lock; exiting` and exits 0, which is expected
for a catch-up pass and is not an alert.

## Configuring the rules in Langfuse

Langfuse separates the **destination** (a webhook, configured once under
*Automations*) from the **rules** (under *Alerts*). The webhook already
exists and its signing secret is deployed as `LANGFUSE_WEBHOOK_SECRET`.
The rules are created as follows (Langfuse Cloud, September 2026 UI):

1. *Automations → New automation*: event source **Alert**, action
   **Webhook**, URL from the top of this document. Save; this is what the
   alert editor's "Automations" panel lists. (Already done.)
2. *Alerts → New Alert*, three times:

   | Field | 1 · p95 latency | 2 · Error rate | 3 · Tool failure rate |
   |---|---|---|---|
   | Data source | Observations | Scores (boolean) | Scores (boolean) |
   | Metric | p95 · latency | share of `true` | share of `true` |
   | Filter | name = `copilot.brief` OR `copilot.ask` | score name = `request_ok` | score name = `tool_ok` |
   | Operator / Alert threshold | > 15000 ms | < 0.95 | < 0.98 |
   | Warning threshold (optional) | > 10000 ms | < 0.98 | < 0.99 |
   | Window | 15 minutes | 15 minutes | 15 minutes |
   | No-data handling | keep previous severity | keep previous severity | keep previous severity |
   | Renotify | every 60 min | every 60 min | every 60 min |
   | Automation | the webhook from step 1 | same | same |
   | Name | `copilot p95 latency` | `copilot error rate` | `copilot tool failure rate` |

   Plan limit: the Hobby plan allows 2 alerts per organisation (Pro and
   above, 100). If only two can be created, keep 1 and 2 and use the
   `tool_ok` score as a dashboard widget; the definition above still
   stands for the day the plan allows it.
3. Proof of delivery: Langfuse has no "send test" for alerts. Lower rule
   1's alert threshold to `> 1` ms, run collection requests 03–07 once so
   the window has data, wait for the next evaluation, then restore the
   threshold. The firing appears as a WARNING `copilot alert received`
   in the app log and a `clinical-copilot-alert` row in the audit log
   (`SELECT FROM_BASE64(comments) FROM log WHERE event='clinical-copilot-alert'`)
   whose `alert=` is the rule's title and whose `value=`/`threshold=` are
   parsed from Langfuse's message body. Record the row's correlation id
   below.

**Delivery record.** First real firing received 2026-09-17 04:40:35 UTC
from `User-Agent: Langfuse/1.0`, signature verified, HTTP 200. Audit row
(`log.event = clinical-copilot-alert`):

```
alert=[ALERT] Avg of Scores (boolean) Value is below 0.95 severity=ALERT type=monitor-alert
correlation_id=b8f80d7c985079b2e7e7e20d283c054b
```

It was the error-rate rule (`request_ok` share) evaluating an empty
15-minute window as 0 — the no-data case — which is why that rule's
*No-data handling* is set to "keep previous severity". Two properties of
Langfuse's payload worth knowing: the title is the generic
`[SEVERITY] <aggregation> of <source> Value is below <threshold>` rather
than the rule name, and the measured value is not included, so the
receiver records `payload.monitorId` (`monitor=` in the audit row) to
identify the rule.
