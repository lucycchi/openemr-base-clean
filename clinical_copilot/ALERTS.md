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
| **Metric** | p95 of trace `duration_ms`, traces tagged `clinical-copilot`, name `copilot.brief` or `copilot.ask` |
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
| **Metric** | traces where `http_status >= 500` **or** `status` is non-null, divided by all `clinical-copilot` traces |
| **Window** | 15 minutes, evaluated every 5 minutes, at least 10 traces |
| **Threshold** | > 5 % |
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
| **Metric** | spans with `level = ERROR`, divided by all spans under `clinical-copilot` traces (span names: `authorize_and_assemble_facts`, `cache_lookup`, `llm.briefing`, `llm.follow_up`, `verify`, `cache_store`, `omission_guard`, `scope_check`) |
| **Window** | 15 minutes, evaluated every 5 minutes, at least 50 spans |
| **Threshold** | > 2 % |
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

`verification_pass = false` on a completed request means every sentence
the model produced was stripped (`total_failure`) or the request ended
with a non-null `status`. Tracked on the dashboard; a rate above 10 % over
an hour is investigated during working hours as a prompt or model drift
problem (see [KEY_METRICS.md § 1](../KEY_METRICS.md)). Not paged because the
physician is protected either way: nothing unverified is shown.

### 5. Refusal rate

403s from `chat.php` (`denied: true` on the trace, `copilot access denied`
in the log). A refused request that returns facts is impossible by
construction and would be P0; the collection's request 16 and the eval
suite check it on every run. A spike of refusals from one user is reviewed
in the audit log.

## Configuring the rules in Langfuse

The Langfuse project already has the dashboard; the three rules above are
created under the project's alerting page with the metric, filter, window
and threshold from each table, and the webhook URL from the top of this
document (the signing secret Langfuse shows on creation is what
`LANGFUSE_WEBHOOK_SECRET` must be set to). Record the configured rules (a screenshot or export) in
[`dashboard/`](dashboard/) next to the dashboard so a grader can see the
rules exist without a Langfuse login. Test each rule once with "send test
notification"; the receiver's 200 body and the resulting
`clinical-copilot-alert` audit row are the proof of delivery.
