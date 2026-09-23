# DASHBOARD.md, Week 2: what the dashboard shows for the multi-agent design

The Week 1 dashboard ([../clinical_copilot_week1/DASHBOARD.md](../clinical_copilot_week1/DASHBOARD.md))
lives in Langfuse Cloud and covers the required set for the chat path:
total requests, error rate, p50/p95 latency, tool-call counts, retry
counts and the verification pass/fail rate, plus cache-hit share,
refusals, tokens and cost. Week 2 added a second service (the Python
sidecar with a supervisor and two workers), document extraction, hybrid
retrieval with a reranker, and a scheduled pre-warm sweep. This file
records what the module now sends for those, the widgets that read it,
and the decisions behind the shape, so the dashboard can be rebuilt from
scratch by anyone with the project's Langfuse keys.

Everything below was implemented on 2026-09-22 (commit in the git log
titled "feat(copilot): the Week 2 agent in the dashboard"); the audit that
motivated it is [ENGINEERING_REQUIREMENTS.md § 4](ENGINEERING_REQUIREMENTS.md#4-dashboards-request-count-error-count-latency-queue-depth-event-retries-decision-outcomes).

## What the module sends, per request (Week 2 additions in bold)

| Event | Emitted by | Contents a widget can read |
|---|---|---|
| Trace `copilot.brief` / `copilot.ask` (id = correlation id) | `ChatController` | Week 1 fields, plus **`guideline_chunks`** (passages the retriever returned for a question), **`handoffs`** (the supervisor's routing log), **`reranked`** (whether Cohere reranked) |
| Trace **`copilot.documents.extract`** | `DocumentController` | `doc_type`, `status` (extracted / failed), `failure_reason`, `confidence`, `results_persisted`, `unverified`, `unextracted`, `handoffs`, `model_calls`, **`sidecar_retries`**, `llm_attempts`, `llm_retried`, `cost_usd`, `duration_ms` |
| Trace **`copilot.prewarm`** (user `cron`) | `PrewarmCommand`, one per sweep | `date`, `dry_run`, `scheduled` (queue size), `warmed`, `already_cached`, `skipped`, `errored`, `model_calls`, `queue_depth_after`, `duration_ms`; `status` names the errored count when non-zero |
| Span per PHP tool step | `StepRecorder` | Week 1; two Week 2 steps: `sidecar_extract_and_persist`, `retrieve_evidence` |
| **Span per sidecar worker**: `sidecar.intake_extractor`, `sidecar.evidence_retriever` | `LangfuseTracer::workerSpans()` from the handoff log | `startTime` / `endTime` (the worker's own duration, anchored inside the PHP step that called the sidecar), `level` ERROR when the worker reported `worker_failed`, metadata `routed_because` (the supervisor's reason), `outcome`, `duration_ms` |
| **Generation per sidecar model call**: `sidecar.chat` (one per page, plus the re-ask), `sidecar.embedding`, `sidecar.rerank` | `LangfuseTracer` from `RequestTrace::$sidecarUsage` | `model`, `usage.input` / `usage.output` / `usage.totalCost`; start time only (the sidecar does not time individual calls; the worker span holds the latency) |
| Generation `copilot.<action>.llm` | Week 1 | the PHP model call; **not emitted for extraction traces**, whose calls are the `sidecar.chat` generations above (no double counting) |
| Boolean scores | `LangfuseTracer::scores()` | Week 1: `request_ok`, `verification_pass`, `tool_ok`, `warm_hit`. **Week 2:** `extraction_ok`, `extraction_verified`, `retrieval_hit`, `routing_ok`, `prewarm_ok` (definitions below) |

Nothing else leaves the server: no document text, extracted values,
question text, narration or patient identifiers (the log-field allowlist in
[W2_ARCHITECTURE.md](W2_ARCHITECTURE.md) applies to trace metadata too).

### The Week 2 scores

A boolean score averaged over a time bucket is a rate, and Langfuse can
both chart and alert on it; trace metadata can be filtered but not
thresholded. Each score exists only on traces where the outcome was
decided, so a chart-open never dilutes an extraction rate.

| Score | On which traces | True when |
|---|---|---|
| `extraction_ok` | `copilot.documents.extract` | the sidecar returned `status = extracted` |
| `extraction_verified` | `copilot.documents.extract` | extracted **and** every proposed value was anchored on the page (`unverified = 0`) **and** every printed row was proposed (`unextracted = 0`): the physician saw no "unverified" fact |
| `retrieval_hit` | `copilot.ask` | the retriever returned at least one guideline passage for the question |
| `routing_ok` | any trace with handoffs | no worker the supervisor invoked reported `worker_failed` |
| `prewarm_ok` | `copilot.prewarm` | no scheduled patient errored in the sweep |

## Widgets

The two Week 1 screenshots (latency dashboard, `request_ok` share) stand.
The Week 2 widgets below are added with **Add Widget** on the same
dashboard; each names the data source, metric and filter so it can be
built without guessing. Screenshots go in `images/` as they are captured.

### The required set, now including the sidecar

| Widget | Data source | Metric | Filter / segment | What changed in Week 2 |
|---|---|---|---|---|
| Total requests | Traces | count | tag `clinical-copilot`; segment by name | three new names: `copilot.documents.extract`, `copilot.documents.upload`, `copilot.prewarm` |
| Error rate | Scores (boolean) | avg `request_ok` | | unchanged; extraction and pre-warm traces carry it too |
| p50 / p95 latency | Traces | p50, p95 latency | segment by name | extraction is the slow path (5-20 s); keep it on its own line so it does not hide the chat p95 |
| Tool call counts | Observations | count | type = SPAN, segment by name | **now includes `sidecar.intake_extractor` and `sidecar.evidence_retriever`** beside the PHP steps |
| Tool failures | Observations | count | type = SPAN, `level = ERROR`, segment by name | a `worker_failed` hop is an ERROR span named after the worker |
| Retry count | Traces | sum of metadata `llm_attempts` minus count, or count where `llm_retried = true` | segment by name | extraction traces count the sidecar's omission-driven re-ask (`sidecar_retries`) |
| Verification pass rate | Scores (boolean) | avg `verification_pass` | | unchanged (narration); extraction has its own pair below |

### Agent-specific widgets (the "metrics that matter for this design")

| Widget | Data source | Metric | Filter / segment | Why it matters |
|---|---|---|---|---|
| Extraction success rate | Scores (boolean) | avg `extraction_ok` | | a document that fails to extract is a physician re-uploading or reading the PDF by hand |
| Fully verified share | Scores (boolean) | avg `extraction_verified` | | the product promise is "every value anchored on the page"; this is the share of documents where that held with nothing left unverified |
| Unverified and unextracted per document | Traces | avg `unverified`, avg `unextracted` | name = `copilot.documents.extract`, segment by `doc_type` | the two failure shapes of anchoring: invented values (unverified) and skipped rows (unextracted); rising `unextracted` means the model is omitting rows again (the 7-of-20 regression) |
| Extraction confidence | Traces | avg `confidence` | name = `copilot.documents.extract`, segment by `doc_type` | share of fields anchored; scans (OCR) run lower than text-layer PDFs |
| Routing outcomes | Traces | count | segment by `handoffs[*].reason` is not filterable; use the `routing_ok` score share, and the worker span counts above for volume per worker | the supervisor is deterministic, so any `routing_ok = false` is a worker failure, never a misroute |
| Retrieval hit rate | Scores (boolean) | avg `retrieval_hit` | | an off-corpus question correctly scores false; a falling rate on ordinary questions means the corpus or the relevance floor needs attention |
| Rerank share | Traces | count `reranked = true` vs `false` | name = `copilot.ask` | tells you whether the Cohere key is active on a deployment; false everywhere means RRF order only |
| Sidecar model calls, tokens, cost | Observations | count / sum `usage.input` + `usage.output` / sum `totalCost` | type = GENERATION, segment by name (`sidecar.chat`, `sidecar.embedding`, `sidecar.rerank`) and by `model` | pages per document drive chat calls; rerank is billed per search, so its cost is visible separately |
| Pre-warm queue | Traces | avg `scheduled`, avg `warmed`, avg `errored`, avg `queue_depth_after` | name = `copilot.prewarm` | the queue's size each morning, what drained it, and what was left (errored patients are warmed at chart-open instead); `prewarm_ok` share as the alertable rate |
| Pre-warm throughput | Traces | avg `duration_ms` / avg `scheduled` | name = `copilot.prewarm` | seconds per patient; the number that says whether the sweep still fits before clinic hours as the schedule grows |

### Alerts

The three Week 1 alerts (p95 latency, error rate, tool-failure rate) keep
working and now include the sidecar workers in the tool-failure rate. Two
Week 2 rates are worth a rule when the pre-warm sweep is enabled on a site:
`prewarm_ok` share below 1.0 (a patient will open cold) and
`extraction_ok` share below 0.9 over an hour (the sidecar or the model
provider is failing). They are defined the same way as the Week 1 rules in
[../clinical_copilot_week1/ALERTS.md](../clinical_copilot_week1/ALERTS.md)
("Configuring the rules in Langfuse"); not yet created in the UI.

## Decisions and trade-offs

1. **Workers become spans, derived from the handoff log, not a second
   instrumentation.** The sidecar already returns every hop with its
   duration and reason (contract `handoff`); the tracer turns each hop into
   a span rather than having the sidecar talk to Langfuse itself. One
   emitter, one set of keys, one PHI allowlist. *Trade-off:* the worker
   spans' clock is reconstructed (anchored on the PHP step that called the
   sidecar, then advanced hop by hop), accurate to the hop durations the
   sidecar measured but not to wall-clock offsets inside the sidecar.
2. **One generation per sidecar model call, and no aggregate for
   extraction.** Before, an extraction trace carried a single generation
   with summed tokens; per-model call counts, the embedding and the rerank
   were invisible and cost was under-counted. Now each call is its own
   generation with its own cost, and the extraction trace has no aggregate,
   so token and cost sums do not double count. The chat path keeps its
   aggregate (the PHP model call) and adds the sidecar's embedding/rerank
   as separate generations. *Trade-off:* sidecar generations carry a start
   time but no end time (the sidecar does not time individual calls); the
   worker span is the latency.
3. **Retries are counted where they are visible.** `sidecar_retries` counts
   the omission-driven re-ask (the one retry the sidecar's own code makes).
   Retries inside the OpenAI client library (network, 429, 5xx) are not
   observable from the sidecar's code and are not counted; the contract
   says so. *Trade-off:* the retry widget undercounts transport retries on
   the sidecar side; PHP-side transport retries (`llm_attempts`) are exact.
4. **Rates as scores, not metadata.** Five Week 2 booleans mirror the Week
   1 pattern because Langfuse alerts and rate widgets read scores. Each is
   emitted only where decided. *Trade-off:* five more score events per
   relevant trace, on an ingestion API that is billed per event on paid
   plans.
5. **The pre-warm sweep is the queue.** Week 1 called queue depth "not
   applicable" (synchronous PHP). Week 2's sweep is a real queue: scheduled
   patients in, receipts out, errored patients left over. One trace per
   sweep carries the queue numbers; the rows keep their own receipts in the
   database. *Trade-off:* per-patient warm latency is in the receipt table,
   not in Langfuse; the trace has the sweep total and the count, from which
   the per-patient average follows.
6. **Retries added to the contract, not inferred.** `run.response` gained a
   required `retries` field on each extraction (contract, Pydantic model,
   shared examples, PHP parser). It could have been inferred as
   `model_calls − pages`, but pages are not in the response and inference
   would silently break the day the sidecar changes its call pattern.
7. **Ingestion path unchanged for now.** The tracer still writes through the
   v3 batch endpoint, which Langfuse Cloud delays by minutes and shuts down
   for non-score events on 2026-11-16. Migrating to the OpenTelemetry
   endpoint is in [../TODOS.md](../TODOS.md) for Phase 9. Until then
   "real time" means "within about ten minutes", and the read APIs used to
   verify ingestion are the v2/v3 ones (the legacy `GET /api/public/traces`
   is closed to this organisation).

## Verifying the events

```bash
# unit level: every worker hop, generation and score the tracer emits
openemr-cmd e 'php vendor/bin/phpunit -c phpunit-isolated.xml --filter "LangfuseTracerTest|PrewarmCommandTest"'
# end to end: drive the UI, then read back what arrived (needs the project's keys in .env)
openemr-cmd e 'php tests/evals/smoke.php http://openemr 2'
```

Read-back after the smoke run of 2026-09-22 (v2 observations API, the
window covering one upload + extract of the five-page synthetic lab report
and one guideline question):

| Observation | Count | Note |
|---|---|---|
| SPAN `copilot.documents.extract` → `sidecar_extract_and_persist` → **`sidecar.intake_extractor`** | 1 each | the worker span reports 11.2 s, inside the PHP step |
| GENERATION **`sidecar.chat`** | 5 | one per page; no re-ask this run (`sidecar_retries` 0) |
| SPAN `copilot.ask` → `retrieve_evidence` → **`sidecar.evidence_retriever`** | 1 each | the worker span reports 1.0 s |
| GENERATION **`sidecar.embedding`**, **`sidecar.rerank`**, `copilot.ask.llm` | 1 each | the query embedding, the Cohere rerank (a key was configured), the PHP answer call |
| Scores `extraction_ok`, `extraction_verified`, `routing_ok` ×2, `retrieval_hit` | all `true` | visible within a minute; the spans and generations took about 25 minutes to appear (v3 ingestion delay) |

The list projection of the v2 API omits model, usage and metadata; those
are on the observation detail and in the UI.
