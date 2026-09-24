# Demo video script (3-5 minutes)

Shot list for the Week 2 walkthrough video (task S2 in
[SUBMISSION_REQUIREMENTS.md](SUBMISSION_REQUIREMENTS.md)). The PRD asks the
video to show **document upload, extraction, evidence retrieval, citations,
eval results, and observability**. Each shot below names which of the six it
covers. Target length: 4 minutes; the timings add up to 4:10.

## Before you record

- **Synthetic data only.** Use the seed patients and the fixtures in
  `tests/evals/fixtures/docs/`. Nothing on screen may show `.env`, an API
  key, the Langfuse settings page, or a real person.
- **Which instance.** Record against the deployed app,
  https://146-190-139-37.sslip.io, so the video also proves the deploy. If
  The droplet runs build `3f9cc74` (deployed 2026-09-23 22:15 CT), which has
  everything shown here.
- **Pick a quiet patient for the upload.** Patient 1 (Phil Belford) and 28
  (Vince741 Collier206) already have `lab-layout1.pdf`; re-uploading the
  same file answers "already uploaded". Use another seed patient, for example
  one with a prior visit so the briefing has something to compare against.
  The upload stays on that chart afterwards, which is harmless on demo data.
- **Files ready on your desktop:** `tests/evals/fixtures/docs/lab-layout1-scan.pdf`
  (the scanned lab report, 5 pages) and `intake-full.pdf` (the intake form).
- **Terminal ready** in the repo root, font large, for shots 7 and 8.
- **Langfuse** open in a second tab on the Traces view, filtered to the last
  hour, before you start. Langfuse's v3 ingestion shows spans and
  generations 10-30 minutes late, so the traces from this take may not be
  there by shot 8. Do one upload and one question on the deployed app half
  an hour before recording and show those traces instead.
- **Timing risk.** A 5-page scan takes 15-20 s to extract on the droplet.
  Keep talking over it (shot 2 has the words for it), or cut the wait in the
  edit.

## Shots

### 1. The problem (0:00-0:20)

**Screen:** the patient's Dashboard with the Co-Pilot card at the top.

**Say:** "A primary care physician has fifteen minutes per patient. The chart
has structured data, but the newest results arrive as a scanned lab PDF and
an intake form from the front desk. The Week 1 Co-Pilot briefed from the
structured chart only. Week 2 makes it read those documents, cite every value
back to the page, and bring in guideline evidence."

### 2. Upload and extraction (0:20-1:05) — *document upload, extraction*

**Do:** in the card's upload control, choose type **Lab report (PDF)**, pick
`lab-layout1-scan.pdf`, press **Upload and extract**.

**Say, while it runs:** "The file is stored in OpenEMR's own documents table
first, so nothing is lost if extraction fails. A Python sidecar reads each
page, with OCR because this is a scan, and a model proposes values against a
strict Pydantic schema. The model does not get the last word: code finds each
value on its row of the page. A value it cannot find is kept but marked
unverified, never shown as fact."

**Show:** the status line, "Extracted N value(s), P% verified against the
page". If it reports unverified values, point at them in the fact list under
**Unverified values from uploaded documents** with their dashed red
"unverified, open source" link.

### 3. Click-to-source (1:05-1:40) — *citations*

**Do:** in the fact list, click **source p.N** on a lab fact from the report
(Hemoglobin A1c or LDL). Then click one on page 5 (TSH or Vitamin D).

**Say:** "Every extracted value is saved as an OpenEMR lab result and carries
a citation: document, page, field and the exact value read. Clicking it opens
the page with the row boxed in blue and the value in red. This is the
bounding-box overlay the brief requires."

### 4. Intake form (1:40-2:00) — *upload, extraction, citations*

**Do:** upload `intake-full.pdf` as **Intake form (PDF)**. When it finishes,
scroll to the intake facts (medications, allergies, chief concern).

**Say:** "Same path for the intake form: demographics, chief concern,
medications, allergies, family history, each with its source. If the name or
date of birth on the form doesn't match the chart, that becomes a flagged
fact rather than being merged in silently."

### 5. Briefing with guideline evidence (2:00-2:45) — *evidence retrieval, citations*

**Do:** scroll to the AI summary. Hover a sentence badge to show which fact
it cites. Then scroll to **What the guidelines say about this chart**. Then
ask in the chat box: **"Should this patient be on a statin?"**

**Say:** "The briefing narrates only verified facts; any sentence that cites
nothing, or states a number not in its cited fact, is removed before it
renders. Guideline evidence is kept separate from the patient's record: it
comes from a small corpus searched by keyword and by meaning, then reranked
by Cohere, and a critic checks that each passage applies to this patient,
for example by age. The answer cites the guideline passage and the lab value
separately."

**Show:** the answer's **guideline** badge (its own colour) next to the fact
badge for the LDL value.

### 6. Routing drawer (2:45-3:05) — *observability*

**Do:** open **Why this result: routing decisions** under the upload status.
It shows the route of the last run: right after shot 5's question, the
question's route (supervisor to evidence-retriever, then the answer writer
and the verifier); right after an upload, the extraction's route.

**Say:** "A LangGraph supervisor decides what runs: extraction only when a
document hasn't been extracted, retrieval only when there is a question or a
guideline trigger. Every hop is logged with its reason, including the answer
being written and verified, and the physician can see it here."

### 7. Eval gate refusing a regression (3:05-3:45) — *eval results*

**Do:** in the terminal, show the last results and then the refusal.
Either run it live:

```bash
tests/evals/install-hooks.sh --self-test
```

or, if the run is too long for the video, open
[eval-gate-proof/push-refused.png](eval-gate-proof/push-refused.png) and the
summary table in [EVAL_DATASET.md](EVAL_DATASET.md).

**Say:** "There are more than fifty golden cases with pass/fail rubrics:
schema valid, citation present, factually consistent, safe refusal, no PHI in
logs, and three more. They run on every git push. A single case flipping from
pass to fail refuses the push. Here I inject a regression and the push is
blocked."

### 8. Observability (3:45-4:10) — *observability*

**Do:** in Langfuse, open the `copilot.documents.extract` trace from shot 2,
then the `copilot.ask` trace from shot 5.

**Say:** "Every request is one trace with the same correlation id the logs
use: the worker spans with their latency, one generation per model call with
tokens and cost, how many guideline passages were retrieved, the extraction
confidence, and the pass/fail scores. No document text or patient
identifiers go to Langfuse; only ids, counts and timings."

**Close:** "Two document types, two workers, one regression gate. Thanks."

## After recording

- Watch it once for anything sensitive on screen (a key in a terminal
  history, a browser autofill, a real name).
- Upload it where the submission form asks, and add the link to the root
  README next to "Deployed:".
- Tick S2 in [SUBMISSION_REQUIREMENTS.md](SUBMISSION_REQUIREMENTS.md).
