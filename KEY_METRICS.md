# KEY_METRICS.md — What "working" means, and how we prove it

The promise to the physician ([`USERS.md`](USERS.md)) is: *in the 90 seconds between
rooms, you get what changed and what is flagged, and you can act on it
without re-checking the chart.* A hospital CTO deciding whether to put this
in front of physicians needs to know three things: is it trustworthy, is it
fast enough to be used, and is it being used. Five metrics, in that order.
Each has a source that already exists in the system, a baseline measured on
2026-09-16 against the 30-patient seed, and a threshold that pages someone.

## 1. Grounding failure rate

**Definition.** Sentences the verifier stripped, divided by sentences the
model produced, per briefing and rolling per day. A stripped sentence is one
the model wrote that cited nothing, cited an unknown fact, or stated a
number or date not present in a cited fact.

**Why it is the first metric.** It is the direct measure of the PRD's core
risk: a confidently stated claim that is not in the record. It counts
*attempts*, not just failures that reached the user, because every attempt
is a signal about prompt or model drift. A physician never sees a stripped
sentence, but a rising rate means the summary is becoming less useful and
the model is becoming less constrained.

**Source.** `stripped` in every `copilot response` log line, audit row and
Langfuse trace; `tests/evals/results.json` for the eval population.

**Baseline.** Live eval, 10 patients: 1 of 59 sentences (1.7%); 1 of 10
briefings had any strip. Recorded evals: every deliberately ungrounded
sentence stripped (cases 01, 02, 03, 06), 100%.

**Alert.** Rolling 1-hour strip rate above 10%, or any briefing with
`total_failure = true` (everything stripped). On-call: compare
`Prompt::VERSION` and model against the last known-good run; re-run
`tests/evals/run.php --live`; if the model changed, pin the prior model.

## 2. Omission-guard append rate

**Definition.** Must-surface facts (new medication, new allergy,
allergy-vs-medication match, abnormal lab, new problem, truncation notice)
that the narration did not cite and the guard appended, divided by
must-surface facts present.

**Why.** Physicians who evaluated an EHR-embedded summarizer reported
omissions roughly nine times more often than hallucinations. The guard
guarantees coverage; this metric measures how often the guarantee had to be
used. Zero means the model is doing the job; high means the physician is
reading a fact table with an ornamental summary.

**Source.** `omitted` per response (logs, audit row, trace);
`omitted_total` in eval results.

**Baseline.** 0 of 10 live briefings needed an append. Recorded case 04
proves the append fires when forced.

**Alert.** Append rate above 25% over a day. On-call: this is a prompt
quality problem, not a safety problem; review the sentences the model
produced for that population.

## 3. Time to verified facts, and time to summary

**Definition.** Two latencies, measured server-side and tagged with the
correlation id:
- *facts*: chart-open request received → fact table returned. Deterministic,
  no model.
- *summary*: request received → verified narration returned (cache hit or
  model round trip). p50 and p95.

**Why.** The 90-second window is the whole premise. The split matters: the
physician can act on the fact table even if the summary is slow; a slow fact
table means the panel is in the way.

**Source.** `ms` per response line; Langfuse trace duration and generation
latency; `latency_ms_p50` / `p95` in eval results.

**Baseline.** Fact assembly 6-55 ms for the busiest seed patients. Summary:
p50 2.2 s, p95 14.5 s cold (one retried call); 1 ms on cache hit.

**Alert.** Summary p95 above 15 s over 15 minutes, or facts p95 above 2 s.
On-call for summary: check `/ready` (OpenAI reachability), OpenAI status,
retry rate in traces. On-call for facts: database health; a patient whose
chart is orders of magnitude larger than the seed.

## 4. Unauthorized-access attempts and refusals

**Definition.** Requests refused by the module's own ACL gate (`patients/med`,
`encounters/notes`) or by CSRF/session checks, as a count and as a share of
requests. Paired invariant: *zero* facts returned to a refused request.

**Why.** Authorization in OpenEMR is often menu-gated rather than enforced
at the data layer (audit finding). The agent is a new, non-menu access path,
so it must prove, continuously, that it enforces access itself. A hospital
CTO will ask this before anything about the model.

**Source.** `copilot access denied` warnings and audit rows with
`success = 0`; HTTP 403s on `chat.php`.

**Baseline.** Seed users `receptionist` and `accountant` refused before any
row is read (smoke and eval); `physician`, `clinician`, `admin` allowed.

**Alert.** Any refused request that returns a non-empty fact set (should be
impossible; treat as P0). Separately, a spike of refusals from one user
suggests either a misconfigured role or probing; on-call reviews the audit
log by user.

## 5. Briefings used per clinic session, and follow-up rate

**Definition.** Distinct patient charts on which the briefing was rendered
per physician per day, divided by charts opened; and the share of briefings
that received at least one follow-up question.

**Why.** Trust and speed mean nothing if the panel is ignored. Briefings
used is adoption; follow-up rate is the signal that the conversational
surface, not just the fact table, is doing work (the PRD's requirement that
multi-turn be justified by a use case is tested here in production, not
just in USERS.md).

**Source.** Audit rows (`clinical-copilot`, user, patient, action = brief |
ask) joined to chart opens in the same log.

**Baseline.** Not yet measurable: no physician usage before the early
submission. Target for the first week of real use: briefing rendered on
>80% of chart opens with today's encounter set; follow-up on >20% of
briefings.

**Alert.** None; this is a weekly review metric, not a page.

## 6. Physician rating of the summary (planned, not yet built)

**Definition.** Per day and per `Prompt::VERSION` + model: briefings rated
thumbs-up, thumbs-down, and not rated, each as a share of briefings
*rendered* (the denominator from metric 5), plus the free-text comments
attached to ratings. Reported as three percentages that sum to 100, not as
an up/(up+down) ratio, so that ignored briefings stay visible.

**Why.** Metrics 1 and 2 prove the summary is grounded and complete;
metric 5 proves it is opened. None of them says whether the physician found
it useful. A one-click rating on the summary block is the cheapest
usefulness signal that is attributable to the exact narration shown (the
rating is keyed to the briefing cache key), so a prompt or model change can
be compared before and after. The comment is the qualitative channel that
tells us *why*, and it is the input to the next prompt revision and to new
eval cases.

**Source.** Planned: `copilot_briefing_rating` table (rating, comment,
cache key, prompt version, model, correlation id); `copilot rating` log
line and audit row; Langfuse `score` named `physician_rating` on the
briefing's trace, which gives the per-version breakdown as a built-in
Langfuse view. Design and build notes in [`TODOS.md`](TODOS.md).

**Baseline.** None until built and used by a physician. Target for the
first month of real use: rated on >30% of rendered briefings; thumbs-down
below 15% of rendered.

**Alert.** None that pages. Weekly review: thumbs-down share rising over
two consecutive weeks, or any comment mentioning a missed fact (which is a
candidate omission-guard or fact-category gap, and goes into
`tests/evals/` as a recorded case).

## 7. Chat adoption per patient encounter (planned, not yet built)

**Definition.** `chat bot use / patient encounters`: the number of patient
encounters during which the physician used the chat at least once, divided
by the number of patient encounters, per physician and per day, and rolled
up per week. "Used" means at least one `ask` turn (a typed question) on
that encounter. The auto-rendered briefing does not count as use, because
the physician did not choose it; a rating (metric 6) does not count as use
either. Reported alongside the mean number of `ask` turns per used
encounter.

**Why.** Metric 5 asks whether the panel is *seen* (briefing rendered per
chart open). This metric asks whether the conversational surface is
*chosen*, and normalises by the clinical unit of work, the encounter,
rather than by chart opens (one encounter can produce several chart opens;
some chart opens have no encounter). It is the direct production test of
the PRD's rule that multi-turn only exists because a use case needs it
(UC2 in [`USERS.md`](USERS.md)): a chat that is never used on a visit is a
chat that should be a report.

**Source.** Planned: numerator from the existing audit rows
(`clinical-copilot`, action = ask) which already carry user and patient,
once the encounter id is added to the audit string; denominator from
`form_encounter` rows whose date falls on that day for that provider. Both
are already in the database; no new table. Design and build notes in
[`TODOS.md`](TODOS.md).

**Baseline.** None until a physician uses it. Target for the first month of
real use: chat used on >20% of encounters (same bar as metric 5's follow-up
target, restated per encounter), and no physician below 5% after week two
without a conversation about why.

**Alert.** None that pages. Weekly review, next to metric 6: falling
adoption with rising thumbs-down means the summary is the problem; falling
adoption with flat ratings means the physician gets what they need from the
fact table and the chat is not earning its place.

## 8. Anchored-field rate (Week 2)

**Definition.** Per extracted document: fields the anchor step proved on
the page divided by fields the model proposed (`confidence` on the
extraction trace), and, as a boolean, whether the document had nothing
unverified and nothing unextracted (`extraction_verified` score). Per day:
the share of documents fully verified, and the mean `unverified` and
`unextracted` per document.

**Why.** It is the product promise of Week 2: every value the physician
sees from a document is one the code found on the page in its own row. A
falling rate means a report layout the anchor step does not read (rising
`unverified`) or the model omitting rows again (rising `unextracted`, the
7-of-20 regression the eval suite pins).

**Source.** `confidence`, `unverified`, `unextracted` on the
`copilot.documents.extract` trace and log line; `extraction_verified` in
Langfuse; `anchored` / `results` per case in `tests/evals/results.json`.

**Baseline.** Eval fixtures: 20/20 anchored on the five-page text-layer
report and on its scan, 9/9 on the intake form; every deliberately absent
value unanchored (cases 43, 44). Deployed smoke: "20 values, 100% verified".

**Alert.** Watched, not paged: fully-verified share under 0.80 over a day
(Week 2 ALERTS.md rule 8).

## 9. Retrieval hit rate (Week 2)

**Definition.** Share of follow-up questions for which the evidence
retriever returned at least one guideline passage (`retrieval_hit`), and,
in the eval set, whether the expected source is on top.

**Why.** Off-corpus questions must score a miss (that is the relevance
floor working: cases 32, 34); ordinary clinical questions should hit. A
low rate on ordinary questions means the corpus or the floor needs work,
not the model.

**Source.** `guideline_chunks` and `reranked` on the `copilot.ask` trace;
`retrieval_hit` in Langfuse; cases 29–32.

**Baseline.** Cases 29–31: 5 chunks each with the expected source on top;
case 32 (off-corpus): 0 chunks. Reranked: false on every run until a
Cohere key is configured on the target.

**Alert.** Watched: under 0.60 over a day (rule 9).

## 10. Routing accuracy (Week 2)

**Definition.** Share of sidecar runs whose supervisor handoffs match the
expected sequence for the state (eval `routing_correct`), and in
production the share of runs with no `worker_failed` hop (`routing_ok`).

**Why.** The supervisor is deterministic, so a wrong route is a code
defect and a failed worker is the only runtime outcome; keeping it a
metric is what proves the design claim.

**Source.** `handoffs` on every trace; the worker spans; cases 23–28.

**Baseline.** 6/6 routing cases; every live run `worker_finished`.

**Alert.** A failed worker fails `tool_ok`, so the tool-failure alert
pages (Week 2 ALERTS.md, alert 3).

## 11. Gate pass rate (Week 2)

**Definition.** Per push: whether `gate.php` passed; per rubric, the pass
rate over the case set against the committed baseline. Over time: how
often the gate refused a push and why.

**Why.** The brief's standard is that a working demo which cannot block a
regression has not met Week 2; the rate is the evidence that the gate is
exercised, not decorative.

**Source.** `tests/evals/baseline.json`, `baseline-live.json`, the hook's
output on each push; `results.json` for the last full run.

**Baseline.** 52 cases, 7 rubrics, 100% on every rubric at the last full
live run; three refusals recorded this week (a fact contract change, a
case-12 timeout, a log-field allowlist miss), each with the fix that
followed.

**Alert.** None: a refused push is the alert.

## Paging alerts

The three alerts that page — p95 latency, error rate, tool failure rate —
are defined with metric, window, threshold, meaning and on-call runbook in
[ALERTS.md](clinical_copilot_week1/ALERTS.md), together with the webhook receiver they fire into;
what Week 2 changed in each, the extraction-latency rule and the Week 2
runbook are in [clinical_copilot_week2/ALERTS.md](clinical_copilot_week2/ALERTS.md).
The per-metric alerts above are the product-quality signals behind them.

## Cost, tracked alongside

Tokens per briefing (cold) and per follow-up, from Langfuse generations and
`tokens_total` in eval results; `cost_usd` is computed per request from list
prices (`Pricing`) and written to the trace, the log line and the audit
row, so spend can be summed per user, per patient or per day without a
bill. Baseline: ~600-2,500 prompt tokens per
briefing depending on chart size, ~300-400 completion; ~13.5k tokens for the
10-patient live eval run; cache hits cost zero. Used for the cost analysis
in the submission, not as a health metric.

## Why not other metrics

- *Physician satisfaction surveys*: valuable, but lagging and not
  attributable to a change. The planned per-briefing rating (metric 6) is
  the attributable replacement.
- *Raw hallucination rate judged by an LLM*: the verifier makes ungrounded
  values structurally impossible to render; a judge would measure the
  semantic-inversion gap (eval case 08), which is real but is better handled
  by keeping the fact table primary than by a second model.
- *Uptime*: covered by `/health` and `/ready`, but a ready service that is
  ignored or wrong is not success.
