# ALERTS.md, Week 2: the alerts re-specified for the multi-agent design

Week 1 defined three paging alerts (p95 latency, error rate, tool failure
rate), three watched rules, the Langfuse configuration recipe and the
signed webhook that records every firing next to the requests that caused
it ([../clinical_copilot_week1/ALERTS.md](../clinical_copilot_week1/ALERTS.md)).
All of that stands. Week 2 added a second service (the Python sidecar with
a supervisor and two workers), document extraction, hybrid retrieval and
the pre-warm queue; each of the three alerts had a blind spot for them,
and none of the new failure modes had a runbook. This file closes both,
and it is the one to read on call for a Week 2 symptom.

The scores and trace fields referenced are written by
[`LangfuseTracer`](../interface/modules/custom_modules/oe-module-clinical-copilot/src/Ops/LangfuseTracer.php)
and described widget by widget in [DASHBOARD.md](DASHBOARD.md).

## The three paging alerts, with what Week 2 changed

### 1. p95 latency

| | |
|---|---|
| **Metric, unchanged** | Observations, p95 latency, filter name = `copilot.brief` OR `copilot.ask` |
| **Threshold, unchanged** | > 15 000 ms over 15 minutes, at least 10 traces; critical |
| **What Week 2 changed** | `copilot.ask` now includes the sidecar's retrieval leg (`retrieve_evidence`: embedding + BM25 + rerank, about 1 s) before the model call, and the answer is capped at six sentences, which halved the ask p95 on dense charts ([experiments/answer-length-cap.md](experiments/answer-length-cap.md)). Document extraction is **deliberately outside this rule**: 5–20 s is its design budget, so it has its own rule below (1b). |
| **Baseline (dev stack, 2026-09-22)** | brief p50 2.9 s, p95 10.2 s over the ten busiest charts; ask 0.7–3.6 s |

**On-call additions.** Before the Week 1 steps:

0. Split the window by trace name. If only `copilot.ask` is slow, look at
   the `retrieve_evidence` span: above 3 s means the sidecar is slow (see
   runbook S1–S3 below), not the model. If `sidecar.evidence_retriever` is
   missing from the trace altogether, the sidecar was unreachable and the
   answer was facts-only (the log says `evidence retrieval unavailable`);
   that is a readiness problem, runbook S1.

### 1b. Extraction latency (new, paging)

| | |
|---|---|
| **Metric** | Observations, p95 latency, filter name = `copilot.documents.extract` |
| **Window** | 60 minutes, at least 5 traces (extraction is rarer than chat) |
| **Threshold** | > 30 000 ms; warning > 20 000 ms |
| **Severity** | warning |
| **Baseline** | five-page text-layer report: 8–12 s (one model call per page); the same report as a scan: 15–20 s (tesseract at 200 dpi); the sidecar client gives up at 60 s and the panel says "stored, retry" |

**What it means.** Physicians are waiting more than 30 s for the "extracted
N values, verified" line after an upload. The file is safe (stored before
extraction starts) and the fact table is unchanged, so this is a degraded
experience.

**On-call response.**
1. Split by `doc_type` and look at `model_calls` on the traces: a rise in
   calls per document means longer documents or more re-asks
   (`sidecar_retries`); a rise in `sidecar.intake_extractor` span duration
   with the same calls means the model is slower (runbook M1) or OCR is
   running where it should not (S4).
2. `docker stats copilot-sidecar` on the droplet: memory near the 768 MB
   cap means tesseract on large scans is swapping; the fix is the page cap
   (`MAX_PAGES`, 5) or more memory, not a code change during the incident.
3. If `sidecar.chat` generations are slow across the board, it is the
   provider: same as Week 1 alert 1, step 1.

### 2. Error rate

| | |
|---|---|
| **Metric, unchanged** | Scores (boolean), `request_ok`, share of `true` |
| **Threshold, unchanged** | < 0.95 over 15 minutes, at least 10 requests; critical |
| **What Week 2 changed** | `request_ok` is now also written on `copilot.documents.extract` (false when the extraction failed for a **service** reason: `model_error`, `timeout`, `schema_mismatch`, or a 5xx) and on `copilot.prewarm` (false when any scheduled patient errored). A document that **cannot be read** (`unreadable`, `encrypted`, `too_many_pages`) is the physician's file, not the service, and does not count: `request_ok` stays true while `extraction_ok` is false, so a batch of bad uploads never pages anyone. `LangfuseTracerTest` pins both cases. |

**On-call additions.** Split the window by trace name first:

- `copilot.documents.extract` failing → read `failure_reason`:
  `model_error` / `timeout` on every document is the provider (Week 1
  alert 2, step 1, but the sidecar's own key: `OPENAI_API_KEY` is read
  by both containers from the same `.env`); `schema_mismatch` means the
  sidecar's reply no longer conforms to `run.response` — a deploy that
  moved the contracts and the sidecar out of step (runbook S5); a 502 with
  `reason: unavailable | timeout` means PHP could not reach the sidecar at
  all (runbook S1).
- `copilot.prewarm` failing → the sweep's `status` names how many
  patients errored; each has a receipt row with the exception class. Not
  an outage: those charts warm at open. Investigate in working hours
  unless every row errored (then it is the provider or the database).

### 3. Tool failure rate

| | |
|---|---|
| **Metric, unchanged** | Scores (boolean), `tool_ok`, share of `true` |
| **Threshold, unchanged** | < 0.98 over 15 minutes, at least 20 requests; warning (critical for the safety-layer spans) |
| **What Week 2 changed** | **`tool_ok` now counts the sidecar's workers as tools.** It is false when any PHP step span errored (as before) **or** when a worker reported `worker_failed` in the handoff log, so the existing rule covers `sidecar.intake_extractor` and `sidecar.evidence_retriever` with no new rule. The worker's span is ERROR-level with the outcome as its status message, which is how "which one" is answered below. |

**Which tool matters more than the rate**, extended:

| Failing span | Meaning | Response |
|---|---|---|
| `sidecar_extract_and_persist` with `SidecarException` | PHP could not get a valid reply: `unavailable` / `timeout` (container down or overloaded), `schema_mismatch` (contract drift), `internal` (the sidecar threw) | S1, S5, S6 |
| `retrieve_evidence` | same codes on the answer path; the question was answered from facts only, which is the designed degradation | S1; not urgent unless sustained |
| `sidecar.intake_extractor` = `worker_failed` | the sidecar ran but extraction failed: the model call failed after its retry, or the reply did not fit the proposal contract; `failure_reason` on the trace says which | M1 for `model_error`/`timeout`; S5 for `schema_mismatch` |
| `sidecar.evidence_retriever` = `worker_failed` | retrieval threw: the embedding call failed, the committed index is missing or stale, or Cohere rejected the rerank | S2 for the index, S3 for Cohere, M1 for the embedding |
| Week 1 rows | unchanged | as in Week 1 |

## Watched, not paged (Week 2 additions)

| Rule | Metric | Threshold | Why watched |
|---|---|---|---|
| 7. Extraction success | Scores (boolean) `extraction_ok`, share of `true`, 1 hour | < 0.90 | includes user-caused failures, so a bad batch of scans lowers it without meaning an incident; a sustained drop with `failure_reason` = `model_error` is alert 2's territory and pages there |
| 8. Fully verified share | Scores (boolean) `extraction_verified`, share of `true`, 1 day | < 0.80 | the product promise; a drop with rising `unextracted` per document is the model omitting rows again (the 7-of-20 regression the eval suite pins); a drop with rising `unverified` is a new report layout the anchor step does not read. Working-hours investigation with the eval fixtures |
| 9. Retrieval hit rate | Scores (boolean) `retrieval_hit`, share of `true`, 1 day | < 0.60 | off-corpus questions correctly score false, so this measures the corpus against what physicians ask; low means extend the corpus or lower the relevance floor, both working-hours changes |
| 10. Pre-warm sweep | Scores (boolean) `prewarm_ok`, share of `true`, 1 day, and Week 1's rules A/B on `prewarm.php` | < 1.0 | only once the sweep is enabled on the site (it is not on the droplet); each errored patient warms at chart open, so this is throughput, not availability |
| 11. Readiness degraded | `GET /ready` → `degraded` containing `sidecar` (an external uptime check every minute; Langfuse cannot poll it) | any | the module is up but Week 2 features are not; runbook S1. Sustained for 10 minutes: page |

## Runbook for the Week 2 failure modes

Every step is read-only until it says otherwise. The sidecar keeps no
state: restarting it loses nothing.

| # | Symptom | Probe | Action |
|---|---|---|---|
| **S1** | `/ready` degraded with `sidecar unreachable`; 502s from `documents.php`; `retrieve_evidence` errors | `docker compose ps copilot-sidecar`; `docker compose logs --tail 100 copilot-sidecar` (JSON lines, no PHI; look for the last `run` line and any exception class) | container exited or restarting: `docker compose up -d copilot-sidecar`; repeats with `Killed` in the logs: it was OOM-killed under the 768 MB cap, see S4 |
| **S2** | `/ready` shows `corpus_index: corpus_index unavailable`; retrieval `worker_failed` | the sidecar's `/ready` from inside the network (`docker compose exec copilot-sidecar python -c "import urllib.request;print(urllib.request.urlopen('http://localhost:8000/ready').read())"`) | the committed index and the corpus disagree (a corpus edit without rebuilding): run `python -m tools.build_index` in the sidecar source, commit `corpus/index/`, redeploy. Until then answers are facts-only |
| **S3** | retrieval `worker_failed` only when `COHERE_API_KEY` is set; sidecar log `model_call failed` with `kind: rerank` | `/ready` `optional.cohere_rerank` | an invalid or exhausted Cohere key: unset it in the droplet's `.env` and recreate the sidecar (RRF order without rerank is the designed fallback), then fix the key in working hours |
| **S4** | extraction slow or OOM-killed on scanned PDFs | `docker stats`; the trace's `ocr_pages` in the sidecar log | the 5-page cap (`MAX_PAGES`) and 200 dpi are the memory budget for this droplet; a larger scan is refused with `too_many_pages` by design. Raise the sidecar's memory limit in `docker/vps/docker-compose.yml` only with a bigger droplet |
| **S5** | `schema_mismatch` from PHP on every extraction after a deploy | compare the sidecar image's contracts (`/contracts` mount, `deploy.sh` rsyncs them) with the module's `contracts/`; `ContractExamplesTest` and the sidecar's `test_contracts.py` must both pass on the deployed commit | redeploy so both sides are on the same commit; never patch a contract on the droplet |
| **S6** | `internal` from the sidecar; `run failed` in its log with an `exception_class` | the log line names the class; the correlation id joins it to the PHP request | a code defect; roll back the sidecar image (`git checkout <sha>` in the deploy checkout, `deploy.sh`) and file it; the eval gate should have caught it, so add the case |
| **S7** | `/ready` `contracts` or `loinc_map` unavailable | the mount: `docker compose exec copilot-sidecar ls /contracts` | `deploy.sh` did not rsync `copilot-contracts/`; rerun it |
| **M1** | `model_error` / `timeout` from the sidecar on every document; `sidecar.chat` generations absent or slow | the same provider probe as Week 1 (`/ready` `openai`); the sidecar uses the same key | provider incident or key problem: Week 1 alert 2, step 1. The sidecar's per-page timeout is 45 s with one retry; nothing to tune during an incident |
| **M2** | follow-up answers slow again on dense charts (case 12 pattern) | the `copilot.ask.llm` generation's `completion_tokens` | the six-sentence cap regressed (a prompt edit); `Prompt::followUpSystem()` must still carry it and `Prompt::VERSION` must have been bumped; the live eval gate refuses the push otherwise |

## Which two rules are live: the Hobby plan

Langfuse Cloud's Hobby plan (the project's plan as of 2026-09-22) allows
**two alert rules per organisation**. Week 1 created two of the three
paging rules in the UI, p95 latency and error rate (the recorded first
firing was the error-rate rule), and kept tool failure rate as a dashboard
widget. The other rules in this file, including 1b and the watched ones,
are definitions until the plan allows more.

**For Week 2 the two live slots should change.** The scores behind the
rules now cover the agent: `request_ok` includes service-caused extraction
and pre-warm failures, and `tool_ok` includes the sidecar's workers. That
makes the two slots most valuable on:

| Slot | Keep / change | Rule | Why this one |
|---|---|---|---|
| 1 | **change** from p95 latency to **3 · tool failure rate** (`tool_ok` share < 0.98) | the only rule that sees a failing worker, a sidecar outage on the answer path, or a broken PHP tool step, i.e. every Week 2 failure mode that is not a provider outage |
| 2 | **keep** | **2 · error rate** (`request_ok` share < 0.95) | now also fires on a provider outage seen through the sidecar and on a failed pre-warm sweep |

Latency moves to the dashboard: the chat p95 (rule 1) and extraction p95
(rule 1b) stay as widgets that are read during working hours, and both
come back as live rules the day the plan allows four. The reasoning: a
slow summary is a degraded experience with the fact table already on
screen, while a failing tool or a rising error rate is the product not
working; with two slots, the slots go to the second kind.

To make the change in the UI (Langfuse Cloud, *Alerts*): open the rule
named `copilot p95 latency`, set data source to **Scores (boolean)**,
filter score name = `tool_ok`, metric share of `true`, alert threshold
`< 0.98`, warning `< 0.99`, window 15 minutes, no-data handling "keep
previous severity", rename it `copilot tool failure rate`, save. Leave the
error-rate rule as it is. The webhook automation is unchanged, so the next
firing still lands in `alerts.php` and the audit log; proof of delivery is
the same procedure as Week 1 ("Configuring the rules in Langfuse", step 3).
Record the date of the change and the first firing here when done.

**Change log**

| Date | Live rules | Note |
|---|---|---|
| 2026-09-17 | p95 latency, error rate | Week 1; first firing recorded in the Week 1 file |
| (pending) | tool failure rate, error rate | the Week 2 recommendation above; awaiting the change in the UI |

## Configuring the Week 2 rules in Langfuse

Same recipe as Week 1 ("Configuring the rules in Langfuse"). The rules are
defined here so they can be created without guessing; whether they are
live depends on the plan's alert allowance, which the Week 1 notes record
as two rules on the Hobby plan.

| Field | 1b · Extraction latency | 7 · Extraction success | 8 · Verified share | 9 · Retrieval hits |
|---|---|---|---|---|
| Data source | Observations | Scores (boolean) | Scores (boolean) | Scores (boolean) |
| Metric | p95 · latency | share of `true` | share of `true` | share of `true` |
| Filter | name = `copilot.documents.extract` | score name = `extraction_ok` | score name = `extraction_verified` | score name = `retrieval_hit` |
| Alert threshold | > 30000 ms | < 0.90 | < 0.80 | < 0.60 |
| Warning threshold | > 20000 ms | < 0.95 | < 0.90 | < 0.75 |
| Window | 60 minutes | 60 minutes | 24 hours | 24 hours |
| No-data handling | keep previous severity | keep previous severity | keep previous severity | keep previous severity |
| Renotify | every 60 min | every 60 min | daily | daily |
| Automation | the Week 1 webhook | same | same | same |
| Name | `copilot extraction p95` | `copilot extraction success` | `copilot verified share` | `copilot retrieval hits` |

The table above is the definition of every rule regardless of the plan; the section before it says which two are live. Rule 11 (readiness degraded) is not a Langfuse rule: it is an external
uptime check on `/ready` (any monitor that can match a JSON field), posting
to the same webhook with `X-Alert-Token` if it cannot sign.

## Decisions and trade-offs

1. **Extend the three scores rather than add three rules.** The requirement
   names three alerts; the Week 2 workers and extraction failures now flow
   into the same `tool_ok` and `request_ok` those alerts read, so the
   rules a grader was shown in Week 1 still mean what they say and cover
   the new agent. *Trade-off:* the rule name says "tool failure" and the
   tool might be a Python worker; the ERROR span names it.
2. **Extraction latency is its own rule.** Folding 5–20 s extractions into
   the chat p95 would either page constantly or force the threshold so high
   the chat rule stopped meaning anything. *Trade-off:* a fourth paging
   rule, and the plan may not allow it; it is defined either way.
3. **User-caused upload failures are not errors.** An encrypted PDF is not
   an outage. `extraction_ok` still records it, and rule 7 watches the
   rate. *Trade-off:* a real service fault that happens to be reported
   under a user-caused code would hide; the three codes are set by the
   parser before any model call, so that cannot happen by construction.
4. **Runbook steps are read-only first, restart second, rollback third**,
   and the sidecar is stateless so a restart is always safe. Nothing in the
   runbook clears the briefing cache or edits a contract in place.
5. **Readiness is watched from outside, not from Langfuse.** Langfuse
   alerts on its own data; a down sidecar produces fewer traces, not a
   metric. An uptime check on `/ready` is the honest signal.
