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
  attributable to a change.
- *Raw hallucination rate judged by an LLM*: the verifier makes ungrounded
  values structurally impossible to render; a judge would measure the
  semantic-inversion gap (eval case 08), which is real but is better handled
  by keeping the fact table primary than by a second model.
- *Uptime*: covered by `/health` and `/ready`, but a ready service that is
  ignored or wrong is not success.
