# Technical interview brief

Preparation for the Week 2 technical interview (task S8 in
[SUBMISSION_REQUIREMENTS.md](SUBMISSION_REQUIREMENTS.md)). The PRD says staff
will ask "about your project, key decisions you made, and coding workflows".
Each decision below is one you can defend in two sentences: what was chosen,
what it beat, and the evidence. Detail is in the linked documents.

## The thirty-second version

A primary care physician gets a pre-room briefing in which every sentence
cites a fact from the chart. Week 2 adds the two inputs the chart was missing:
a scanned lab PDF and an intake form, read by a Python sidecar and proved
value by value against the page, and guideline evidence from a small hybrid
retrieval corpus, kept visibly separate from the patient's own facts. A
LangGraph supervisor routes work to an intake-extractor and an
evidence-retriever and logs every hop. A pre-push git hook runs 56
deterministic eval cases and refuses any push where one case flips from
pass to fail.

## Key decisions

| Decision | What it beat | Why (evidence) |
|---|---|---|
| **Python sidecar beside PHP**, not everything in PHP | PHP-only; a separate agent service owning the data | PDF parsing, OCR, Pydantic and LangGraph live in Python. PHP keeps what it already did well: auth, ACL, storage, the verifier and the UI. The sidecar never touches the database and never sees the patient's name ([W2_ARCHITECTURE.md](../W2_ARCHITECTURE.md), "Agents"). |
| **PyMuPDF + tesseract**, not Docling | Docling | Measured on the droplet before writing code: Docling was a 15 GB image, 1.7 GB idle, 74 s for five text pages and OOM-killed on a scan. Tesseract read the scan in 11.8 s in under 100 MB (W2_ARCHITECTURE.md, "Stack decision"). |
| **"Model proposes, code anchors"** | Trusting the VLM's values; asking the model for coordinates | The model only proposes values as printed. Code must find each one in a row that also carries its analyte and unit. A value it cannot find is kept but shown as unverified, never as a fact. This is the answer to "vision extraction without invention". |
| **One model call per page** | One call per document | One call per document omitted 7 of 20 rows; per page, 0 of 20. Omitted rows are detected and re-asked once, then shown as "not extracted". |
| **Lab values become OpenEMR lab results** (`procedure_order` / `report` / `result`, with UUIDs) | A private module table | They appear in the chart and as FHIR Observations, deduplicated by file hash and by (LOINC, date, value), so a re-upload creates nothing new. |
| **Intake medications and allergies stay in the module's table** | Writing them into OpenEMR's medication and allergy lists | They are patient-reported and unverified; writing them into the reconciled lists would make a front-desk upload look like a clinician's entry. Shown as cited intake facts instead (core task T1.1 records the trade-off). |
| **Deterministic supervisor** (rules, no model) | An LLM router | The routing question ("is there an unextracted document? a question? fired guideline triggers?") has a correct answer from state. Rules make it testable: eight routing cases check the exact handoff sequence. |
| **Hybrid retrieval: BM25 + embeddings, RRF, Cohere rerank, a relevance floor** | Dense only; no floor | Keyword catches drug and analyte names that embeddings blur; the floor makes an off-corpus question return nothing instead of the least-bad passage (cases 32, 34). |
| **A critic that checks applicability** | No critic; a critic that rewrites text | One strict boolean per guideline card: does this passage's population include this patient (age, sex)? It hides a statin recommendation for an 82-year-old when the passage covers 40-75. It never writes prose. |
| **Code judges the evals, not a model** | LLM-as-judge | Every rubric is a counter the harness fills (schema errors, uncited sentences kept, leaked identifiers, anchor errors). Same run, same verdict, and a failure names the field ([EVAL_DATASET.md](EVAL_DATASET.md) §3). |
| **Pre-push hook plus a GitLab CI job** | Hook only, or CI only | The hook stops a regression before it leaves the laptop (`install-hooks.sh --self-test`); the `eval-gate` CI job runs the same gate on a fresh stack for every pipeline and blocks the merge request (MR !1 proof). Personal accounts cannot create pipelines here, so a project bot token starts them ([EVAL_GATE.md](../EVAL_GATE.md)). |
| **Any flip on a deterministic case fails the push** | Only the "more than 5 points" rule | One broken case in a 90 % rubric with 30 cases is a 3.3-point drop and passed the old rule. Recorded cases never vary, so any flip is a real regression. Live cases keep the 5-point allowance for model variance. |
| **Recorded model replies for the PHI cases** | Live-only PHI checks | `no_phi_in_logs` used to be scored only when an API key was present. Now two deterministic cases drive the real controllers with a recorded reply, so the gate scores it on a fresh clone. |

## Weak points, and what you would do next

Say these before you are asked.

- **Reranking is most of the cost.** Brief mode calls Cohere once per fired
  guideline trigger at $0.002 a search: 86 % of the $0.008 per encounter.
  The trigger queries and corpus are fixed, so the rerank order can be
  precomputed at index build (COST_AND_LATENCY.md §4.3).
- **Extraction bursts hit the provider's rate limit.** At 50 concurrent
  users on the dev stack only 12.7 % of documents extracted: ~40 page calls
  a second, most refused. Fix: a concurrency cap and backoff in the sidecar,
  then a queue with workers.
- **The droplet is small.** 2 vCPU: fine at 10 users, 25-30 % errors at 50
  (database connections, not the model).
- **Two synthetic layouts.** The anchor rule is layout-agnostic, but it has
  only seen generated reports. Real-world layouts are the next fixtures.
- **"Final answer is ready" is decided in PHP, not inside the LangGraph
  graph.** The sidecar graph ends after retrieval; PHP writes and verifies
  the answer, and the handoff log records those stages as hops
  (`supervisor -> answer_writer -> verifier -> done`, core task T4.1), so the
  decision is inspectable even though it runs outside the graph.
- **The model provider sees document text**, as it sees chart facts in
  Week 1. Covered by the provider agreement, not by code. Identifiers never
  reach logs or Langfuse.
- **Langfuse v3 ingestion** shows spans 10-30 minutes late and shuts down on
  2026-11-16; the OpenTelemetry migration is queued.
- **The CI gate needed a workaround to run on GitLab.** The early reviewer
  asked for the eval gate as a blocking merge-request job. It is one now
  (pipeline #28015 green; merge request !1 with a planted PHI-logging
  regression failed `eval-gate` and is blocked, `ci_must_pass`). The catch:
  a student's own account cannot create pipelines on this instance (403 even
  as Owner), so pipelines are started by a project access token's bot user
  (`tests/evals/ci-pipeline.sh`), as Gauntlet suggested. The runner is on the
  production droplet with the eval stack capped at 3 of 4 CPUs; a dedicated
  runner box would be the next step at scale.
- **Built last, in a time box:** a third document type (an outside
  medication list, reusing the intake form's row anchoring) and a lab trend
  chart in the panel whose uploaded points open the PDF row. Both went in
  with gate cases (74-76) and a revert rule if either was not green by
  07:00 CT; neither needed it. Deliberately not built: automatic
  discrepancy facts between an outside list and the chart's medications.

## Numbers to have ready

| | |
|---|---|
| Eval cases | 72 (56 deterministic, 16 live); 8 boolean rubrics; 5 at a 100 % threshold |
| Extraction, 5-page lab report | p50 13-17 s, p95 16-20 s at 10 users; 100 % extracted and anchored in the load runs |
| Follow-up question | p50 ~5 s, p95 6-7 s under load; 1.8 s / 4.9 s single-user |
| Cold briefing (expanded) | p50 4.2 s, p95 10.1 s at 10 users; a cache hit 0.5 s |
| Cost | $0.008 per encounter as built, $0.002 after the rerank precompute; about $2 of model spend for the whole week |
| Corpus | 6 guideline summaries, 30 chunks |
| Stack | PHP 8.2 module + Python 3.12 sidecar (FastAPI, LangGraph, Pydantic), gpt-4o-mini, text-embedding-3-small, Cohere rerank-v3.5, Langfuse |

## Coding workflow (the PRD asks about this too)

- Plan first: a design doc with a phased task list, reviewed three times by
  Claude and twice by Codex (GPT-5.6) before any code; the second model
  found a sequencing flaw the first missed ([DESIGN.md](DESIGN.md)).
- Measure before deciding: the Docling spike, the answer-length experiment
  ([experiments/](experiments/answer-length-cap.md)), load baselines before
  and after each fix ([BASELINES.md](BASELINES.md)).
- Evals as the definition of done: a feature lands with the cases that
  guard it; the gate runs on every push; PHPStan level 10 is kept at zero.
- AI assistance: Claude Code wrote most of the code under that process;
  commits carry an `Assisted-by` trailer.
