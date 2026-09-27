# Demo video script (about 4:40)

Script for the final Week 2 video (task G9 in
[SUBMISSION_REQUIREMENTS.md](SUBMISSION_REQUIREMENTS.md)). The PRD wants
3-5 minutes showing **document upload, extraction, evidence retrieval,
citations, eval results and observability**. Shot 9 adds the patient
dashboard port (the surprise challenge). Each "Say" is written to be read
aloud at a normal pace within its time slot.

## Before you record

- **Wait for the droplet deploy to finish**, then check
  [/ready](https://146-190-139-37.sslip.io/interface/modules/custom_modules/oe-module-clinical-copilot/public/ready.php)
  says ready.
- **Synthetic data only.** No `.env`, API keys, Langfuse settings or real
  names on screen. Clear the terminal history you will show.
- **Tabs, in order:**
  1. Co-Pilot: https://146-190-139-37.sslip.io (log in as `admin`), on a
     seed patient that has no uploaded lab PDF yet. Patients 1 and 28
     already have `lab-layout1.pdf`, and re-uploading answers "already
     uploaded".
  2. Langfuse, Traces view, last hour.
  3. The old PHP dashboard for **Tessa Typical** (droplet pid 31).
  4. New dashboard: https://dashboard.146-190-139-37.sslip.io, signed out.
- **Files on the desktop:** `tests/evals/fixtures/docs/lab-layout1-scan.pdf`
  and `intake-full.pdf`.
- **Terminal** in the repo root, large font.
- **Warm Langfuse 30 minutes early.** Its ingestion shows traces 10-30
  minutes late, so do one upload and one question beforehand and show those
  traces in shot 8.
- **The scan takes 15-20 s to extract.** Talk over it, or cut the wait.

## Shots

### 1. The problem (0:00-0:15)

**Screen:** the patient's dashboard with the Co-Pilot card.

**Say:** "A primary care doctor's newest results often arrive as a scanned
lab PDF and a paper intake form. Week 2 teaches the Co-Pilot to read them,
cite every value, and add guideline evidence."

### 2. Upload and extraction (0:15-0:50): *upload, extraction*

**Do:** upload control, type **Lab report (PDF)**, pick
`lab-layout1-scan.pdf`, **Upload and extract**.

**Say:** "The file goes into OpenEMR's own document store first. A Python
sidecar reads each page, with OCR because this is a scan, and a model
proposes values against a strict schema. Then code has to find each value
on its row of the page. What it can't find is kept, but marked unverified."

**Show:** the status line, "Extracted N value(s), P% verified against the
page".

### 3. Click-to-source (0:50-1:10): *citations*

**Do:** click **source p.N** on the Hemoglobin A1c or LDL fact.

**Say:** "Every value is saved as a real OpenEMR lab result with a citation:
document, page, field and the exact text. Click it and the page opens with
the row boxed and the value highlighted."

### 4. Intake form (1:10-1:30): *upload, extraction, citations*

**Do:** upload `intake-full.pdf` as **Intake form (PDF)**, then scroll to
the medications and allergies.

**Say:** "Same path for the intake form. A medication only counts as
verified if its dose and frequency are printed on the same line as the
name. And if the name on the form doesn't match the chart, that's flagged,
not merged."

### 5. Briefing and guideline evidence (1:30-2:10): *evidence retrieval, citations*

**Do:** hover a sentence badge in the AI summary, scroll to **What the
guidelines say about this chart**, then ask **"Should this patient be on a
statin?"**

**Say:** "The briefing only states facts it can cite; a sentence with no
source, or a number its source doesn't contain, is removed. Guideline
evidence stays separate from the patient's record. It's found by keyword
and by meaning, reranked by Cohere, and checked against this patient, for
example their age."

**Show:** the answer's **guideline** badge next to the LDL fact badge.

### 6. Routing drawer (2:10-2:25): *observability*

**Do:** open **Why this result: routing decisions**.

**Say:** "A LangGraph supervisor sends work to two workers, the extractor
and the evidence retriever, and every handoff is logged with its reason."

### 7. Eval gate (2:25-2:55): *eval results*

**Do:** run `tests/evals/install-hooks.sh --self-test`, or if it runs too
long, show [eval-gate-proof/push-refused.png](eval-gate-proof/push-refused.png).

**Say:** "Seventy-three golden cases with pass/fail rubrics: schema valid,
citation present, factually consistent, safe refusal, no PHI in logs. They
run on every push. Here I inject a regression, and the push is refused."

### 8. Observability (2:55-3:20): *observability*

**Do:** in Langfuse, open the warmed-up `copilot.documents.extract` trace,
then the `copilot.ask` trace.

**Say:** "Each request is one trace: every step's latency, tokens and cost
per model call, retrieval hits, extraction confidence and the eval scores.
No document text or patient identifiers leave the server."

### 9. Porting the patient dashboard (3:20-4:30)

**Do, in order:**
1. (3:20) Show the old PHP dashboard for Tessa Typical for two seconds.
2. (3:25) Switch to the new dashboard tab and **sign in** through OpenEMR's
   login page.
3. (3:35) Open **Tessa Typical**. Point at the header, then the Allergies,
   Problem List, Medications, Prescriptions and Care Team cards, then scroll
   to **Encounter history**. Collapse one card.
4. (3:55) Switch to **Dora Deceased**: the header status reads
   "Deceased (date)".

**Say:** "The second challenge was to move OpenEMR's patient dashboard off
server-rendered PHP without redesigning it. This is the new one, in React
and TypeScript. Sign-in is OAuth2 and OpenID Connect. Same header, same
five cards, plus encounter history, all from OpenEMR's FHIR and REST APIs,
and a parity test checks every card against the old page.

Why React with a small Node server? OpenEMR's FHIR API rejects browser
calls from another site, so something had to sit in front of it. That
server keeps the tokens out of the browser. It also avoids a trap where
asking for the wrong patient returns an empty list, not an error. React
fits because each card is one small component.

What we gained: typed, unit-tested display rules instead of SQL inside page
code. The tradeoff: FHIR doesn't report some of this data correctly, so
three lists come from OpenEMR's older REST API, and the defence document
lists every gap."

### Close (4:30-4:40)

**Say:** "Two document types, two workers, one regression gate, and a
modern dashboard on the same API. Thanks."

## After recording

- Watch it once for anything sensitive on screen (a key in terminal history,
  a browser autofill, a real name).
- Upload it, then put the link in the root README next to "Deployed:" and in
  [SUBMISSION_REQUIREMENTS.md](SUBMISSION_REQUIREMENTS.md) row 7; tick G9.
- Send me the link and I'll move U7 and S5 in
  [PRD_COMPLIANCE.md](PRD_COMPLIANCE.md) to Passing.
