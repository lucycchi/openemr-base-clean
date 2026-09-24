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

### A day, concretely

Dr. Rivera (a composite, not a real person) runs a 20-patient day in 15-minute
slots: mostly chronic-disease follow-ups (hypertension, diabetes,
hyperlipidemia), a few acute visits, one or two annual exams. Results arrive
all day in the portal and on paper; the front desk scans what comes by fax.
The visit note is written between rooms or at the end of the day. The
physician's scarcest resource is not information but attention between
rooms.

**What this physician will not read:**

- Paragraphs. A briefing longer than a screen is skipped, so the summary is
  capped (12 sentences) and the fact list is sorted pertinent-first: new,
  changed and abnormal before background.
- Normal results listed one by one. They are grouped, with the range shown,
  under their own heading below the abnormal ones.
- Things they already know: a medication unchanged for five years is
  context, not news. Only new, stopped and changed medications are
  must-surface.
- Anything that sounds like advice without a source. A guideline sentence
  without the passage behind it is ignored, or worse, trusted.

**What "safe to act on" means to them:** every number on screen is either
from the chart or read from a document page they can open in one click;
anything the system could not verify is labelled unverified in a different
style, never blended in; nothing is inferred or computed by the model; and
guideline evidence is visibly separate from the patient's own record, with
a note when it may not apply to this patient (the critic's age and sex
check). If any of those fails once, the physician goes back to clicking
through tabs, and the tool has lost its only value.

## The second user: the front-desk uploader (Week 2)

Week 2 adds a person who never reads the briefing. **The front-desk or
medical-records staff member** receives a faxed or scanned lab report, or
hands the patient a paper intake form at check-in, and attaches it to the
chart.

| | |
|---|---|
| Needs | Attach the document to the right patient in a few clicks, know it was read, and move on. They are not clinically trained to judge the values. |
| Permissions | OpenEMR ACL `patients/docs` with **write or addonly** to upload and start extraction; `patients/docs` view to see the documents list. The demo data's `receptionist` account ships without the docs ACL, so a clinic grants `addonly` to its front-desk group (Administration > ACL). |
| What they see | The Co-Pilot card's upload control: a type (lab report or intake form), the file, **Upload and extract**, then one status line: "Extracted 20 value(s), 100% verified against the page", or the reason it failed ("the file is stored; you can retry"). A duplicate upload says it was already uploaded. |
| What they must not see | The briefing, the fact list and the AI summary: the chat endpoint requires `patients/med` and refuses them before reading a row. The status line reports counts, never values. They can open the PDF they uploaded in OpenEMR's own Documents tab, as they always could. |
| What can go wrong for them | The wrong chart. The upload is bound to the patient open in the session, never to an id in the request; if the name or date of birth printed on the document disagrees with the chart, the physician sees a must-surface **Document does not match the chart** fact in the briefing. |

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

### UC4 — A lab PDF arrives before the visit (Week 2)

**When:** the morning of the visit, a lab report comes in by fax or from an
outside lab's portal as a PDF. The results are not in OpenEMR's structured
lab tables, so in Week 1 the briefing could not see them: the most
important news for this visit was invisible to the tool.

**Click path.** *Front desk:* Patient > Finder > open the chart >
**Dashboard** > Co-Pilot card > the upload form under **Uploaded documents**: type **Lab report
(PDF)**, choose the file, **Upload and extract**. The status line reports
how many values were read and what share was verified against the page
(10-20 s for a five-page report). *Physician, later:* open the chart >
**Dashboard**. The new results are in the fact list with their ranges and
flags, each ending in **source p.N**; clicking it opens the page with the
row boxed. A value the system could not find on the page is listed under
**Unverified values from uploaded documents** with a dashed
"unverified, open source" link. If a result fires a guideline topic (an LDL
above target, an A1c in the diabetic range), **What the guidelines say about
this chart** shows the passage, the facts that raised it, and whether it
applies to this patient.

**Why an agent:** the work is reading a document the chart cannot read and
proving each value against the page. A human does this today by retyping
results, or not at all. The proof step is what makes it safe: the model
proposes values, code finds each one on its printed row, and anything not
found is shown as unverified rather than as fact.

**What it refuses to do:** turn an unverified value into a lab result;
extract more than five pages or an encrypted or unreadable file (it says
which); attach the file to any patient but the one open in the session.

### UC5 — The intake form contradicts the chart (Week 2)

**When:** at check-in the patient fills in a paper intake form: current
medications, allergies, family history, the reason for the visit. What the
patient writes often disagrees with the chart: a medication stopped by a
specialist, a new allergy after an ED visit, a dose the patient changed on
their own. These discrepancies are exactly what the physician needs before
walking in, and exactly what gets lost when the form sits in a scanned
document.

**Click path.** *Front desk:* Dashboard > Co-Pilot card > the upload form under **Uploaded
documents**: type **Intake form (PDF)**, choose the scan, **Upload and
extract**. *Physician:* open the chart > **Dashboard**. **Reason for visit
(intake form)**, **Medications listed on the intake form** and **Allergies
listed on the intake form** appear in the fact list beside **Active
medications** and **Allergies on file**, each with its source link to the
form. Intake medications and allergies are must-surface: the summary cannot
leave them out. If the name, date of birth, sex or phone on the form
disagrees with the chart, a **Document does not match the chart** fact is
added, and it is must-surface too.

**Why the agent does not reconcile the lists itself:** the intake items are
what the patient wrote, not verified entries. Writing them into OpenEMR's
medication and allergy lists would make a front-desk upload look like a
clinician's entry. The agent puts the two versions side by side, cited, and
the physician reconciles in the chart's own lists during the visit.
Automatic discrepancy detection (a `medication_discrepancy` fact) is
designed but not built.

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
| Week 2: document upload stored through OpenEMR's Document API, patient-scoped dedup | UC4, UC5 (front desk) |
| Week 2: page extraction with row anchoring, unverified values kept visible | UC4 |
| Week 2: lab results written to OpenEMR's lab tables (FHIR Observations) | UC4 |
| Week 2: intake facts, must-surface intake medications and allergies, document mismatch flag | UC5 |
| Week 2: click-to-source viewer with the row and value boxed | UC4, UC5 |
| Week 2: hybrid guideline retrieval, guideline section, applicability critic | UC2, UC4 |
| Week 2: supervisor routing with a logged handoff per hop | operations, and the physician's "Why this result" drawer |

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
`encounters/notes` ACLs; the module refuses them the briefing and follow-up
questions before reading a single row (verified in `tests/evals/smoke.php`).
Week 2 lets a front-desk user with `patients/docs` write or addonly upload
and extract a document; they still see no briefing, and without the docs
ACL the upload is refused too (Week 2 API collection, request 16). Users lacking a sensitivity level
do not see encounters or labs from encounters marked with it. Medications,
allergies and problems are not encounter-scoped in OpenEMR, so they are not
filtered by sensitivity; this matches OpenEMR's own tabs and is stated as a
known limitation in ARCHITECTURE.md.
