# USERS.md — Who the Clinical Co-Pilot is for

## The user

**A primary care physician in an outpatient clinic, seeing about 20 patients
a day in 15-minute slots.** They know most of their patients across years,
which is exactly why the question that matters between rooms is not "who is
this?" but "**what changed since I last saw them, and is anything on file
that needs attention today?**"

Concretely, in this fork's seed data, this is the physician user
(`physician`) opening charts like pid 15 (a patient whose triglycerides came
back high since the previous visit) or pid 4 (hemoglobin below range, eight
documented allergies, an epinephrine auto-injector on the medication list).

What this user is not: an ED resident seeing strangers at 2 a.m. (no prior
visit to diff against), a hospitalist rounding on inpatients (acuity and
data density are different), or a nurse or front-desk user (different
permissions; the system refuses them by ACL, see below). Choosing the PCP
fixes what data matters (deltas, not full history), the pace (seconds), and
what "useful" means (a verified diff, not a summary).

## The workflow the agent enters

```
08:50  Reviews the day's schedule.
09:00  Room 1. Front desk has checked the patient in; today's encounter
       exists. Physician opens the chart.
       ├─ 90 seconds: what changed since last visit? anything flagged?
       ├─ Visit. Mid-visit: "when was that started?" "what was the last
       │  value?" — questions that today mean clicking through Meds, Labs,
       │  Encounters tabs while the patient waits.
       └─ Closes the encounter.
09:15  Room 2. Repeat, ~20 times.
```

The thirty seconds before the agent: the physician has just left one room,
glances at the name on the schedule, and clicks the chart. The output they
need is on screen before they finish reading the demographics header, and
it is safe to act on without re-checking, or it is worthless.

## Use cases

### UC1 — Pre-room briefing: "what changed since last visit, and what is flagged"

**When:** the chart opens with today's encounter set (the normal check-in
flow). **What the agent does:** renders, with no model involved, the
deterministic fact table diffed against the previous visit: new or changed
medications, new allergies, allergy-vs-medication matches, abnormal labs and
lab changes since the prior visit, new problems, visits since. Above it, an
AI summary where every sentence cites the facts it is about, and any
must-surface fact the summary skipped is appended by the omission guard.

**Why an agent rather than a dashboard or a better chart view:** the
physician's real task is *synthesis across time and tabs*. OpenEMR already
shows every one of these records somewhere; the cost is the mental diff.
A dashboard that shows more tiles adds reading; a chart view that sorts
better still leaves the comparison to the human. The value is a
prioritized, cited narrative of *what is different*, which is language, not
layout. And because the same cited-fact machinery serves follow-up
questions (UC2), one conversational surface does both jobs; a dashboard
cannot answer "why".

**What it refuses to do:** state anything not in a cited fact (stripped and
marked), compute or infer (deltas are computed deterministically by PHP and
emitted as facts), or show a patient the user's ACL does not allow.

### UC2 — Follow-up questions during the visit

**When:** mid-visit, the physician wants one specific thing from the
briefing window: "what was the triglyceride result and its reference
range?", "when was lisinopril started?". **What the agent does:** answers
from the same fact set, cited, verified by the same rules; if the answer is
not in the facts, it says so (`not_in_facts`) and points at the chart tab
rather than guessing.

**Why this needs multi-turn conversation (PRD: only build it if a use case
requires it):** the questions are contingent on the briefing and on each
other ("when was that started?" refers to the sentence just read). A search
box cannot resolve "that"; a report cannot be interrogated. The transcript
is held in the browser and re-sent each turn; the server re-verifies every
answer, so the conversation never becomes a source of truth of its own.

**Boundary behavior that matters to this user:** "by how much does it exceed
the range?" invites arithmetic. The model may compute; the verifier strips
any number that is not in a cited fact and the panel explains why. The
physician gets the recorded values and does the arithmetic, which is the
right division of labor in a clinical setting.

### UC3 — Safety flags on file: allergy-vs-medication and out-of-range labs

**When:** part of UC1's opening, but a distinct need: the thing the
physician most fears missing. **What the agent does:** deterministic
cross-checks, surfaced as must-surface facts the guard will not let the
summary omit: a documented allergy whose substance appears in an active
medication; a numeric lab result outside a curated, versioned reference
range (16 common primary-care LOINC tests).

**Why an agent here at all, given it is deterministic:** it is not the
model that adds value here; it is the *placement*. The flag is rendered
first, bolded, in the same panel the physician is already reading for UC1,
and the summary is forced to mention it. A separate alerts module would be
one more place to look.

**Explicit non-goal:** drug-drug interaction checking. This install has no
interaction source of truth, and letting the model decide interactions from
general knowledge is precisely the ungrounded clinical claim the PRD
forbids. Tracked in [`TODOS.md`](TODOS.md).

## Capability → use case traceability

| Capability (see ARCHITECTURE.md) | Serves |
|---|---|
| FactAssembler: prior-visit selection, since-last-visit diff | UC1 |
| Deterministic fact table rendered before any model call | UC1, UC3 |
| Reference-range abnormal labs, lab deltas | UC1, UC3 |
| Allergy-vs-medication cross-check | UC3 |
| LLM narration by fact id, Verifier, OmissionGuard | UC1 |
| Follow-up mode with `not_in_facts`, client-held transcript, chart-changed restart | UC2 |
| ACL gate before any read; session-bound patient; CSRF | all (who may ask) |
| Correlation ids, audit-log entries, Langfuse traces | operations, not the physician |

Nothing else was built. Tool chaining beyond one `get_fact_set`, server-side
conversation memory, and interaction checking each lacked a use case above
and were deferred (see [`clinical_copilot_week1/DESIGN.md`](clinical_copilot_week1/DESIGN.md)).

Three extensions to UC1 are designed but not built, and are tracked in
[`TODOS.md`](TODOS.md): today's nurse intake (reason for visit and new
symptoms) as must-surface facts; a due-today checklist from OpenEMR's
Clinical Reminders and the immunization history; and a fixed briefing
section schema so every briefing has the same shape.

## Users who are refused, by design

Front-desk and accounting users lack the `patients/med` and
`encounters/notes` ACLs; the module refuses them before reading a single
row (verified in `tests/evals/smoke.php`). Users lacking a sensitivity level
do not see encounters or labs from encounters marked with it. Medications,
allergies and problems are not encounter-scoped in OpenEMR, so they are not
filtered by sensitivity; this matches OpenEMR's own tabs and is stated as a
known limitation in ARCHITECTURE.md.
