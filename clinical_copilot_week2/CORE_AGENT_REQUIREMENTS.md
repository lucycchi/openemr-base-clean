# Core agent requirements: status and tasks

The seven **Core Agent Requirements** from the Week 2 PRD (pages 4-5), checked
against the code on `pdf_reader` on 2026-09-23. Each requirement quotes the PRD,
gives its status, and links the evidence. Every gap becomes a task.

Status key: **Met**, **Partly met** (works, with a gap a grader could point to),
**Not met**.

## Summary

| # | Requirement | Status | Open tasks |
|---|---|---|---|
| 1 | Document ingestion and extraction | Met | ~~T1.1~~, ~~T1.2~~ |
| 2 | Structured schemas | Met | ~~T2.1~~ |
| 3 | Basic hybrid RAG plus rerank | Met | ~~T3.1~~, T3.2 |
| 4 | Supervisor plus two workers | Partly met | ~~T4.1~~, T4.2, T4.3 |
| 5 | Citation contract | Met | ~~T5.1~~, ~~T5.2~~ |
| 6 | Eval-driven CI gate | Partly met | ~~T6.1~~, ~~T6.2~~, T6.3 |
| 7 | Observability and cost tracking | Partly met | ~~T7.1~~, T7.2, T7.3 |

The tasks are worked in the order in the [task list](#task-list), riskiest to
grading first.

---

## 1. Document ingestion and extraction

> Implement attach_and_extract(patient_id, file_path, doc_type) or an equivalent
> tool. It must support lab_pdf and intake_form. It must store the source
> document in OpenEMR, return strict-schema JSON, and persist derived facts as
> appropriate FHIR resources or OpenEMR records.

| Part | Status | Evidence |
|---|---|---|
| Equivalent tool | Met | `copilot:attach <pid> <file> <doc_type>` ([AttachCommand.php](../interface/modules/custom_modules/oe-module-clinical-copilot/src/Command/AttachCommand.php)); the UI uses upload + extract in `public/documents.php`. Both share `ExtractionRunner`. |
| lab_pdf and intake_form | Met | `DocType` enum (`src/Documents/DocType.php`), matching Pydantic literal in the sidecar. |
| Source stored in OpenEMR | Met | `Document::createDocument` into the standard `documents` table ("Lab Report" / "Patient Information" categories), `DocumentStore.php`. |
| Strict-schema JSON | Met (T1.2 done) | The sidecar reply is validated against the contract before anything is saved (`SidecarClient.php`, `Contracts.php`). `copilot:attach <pid> <file> <doc_type> --json` returns the summary plus the validated extraction itself under `extraction` (for example an intake form's demographics, chief concern, medications, allergies and family history, each with its five-field citation and boxes). Checked with a real intake form. |
| Lab values as OpenEMR/FHIR records | Met | Anchored results become `procedure_order` / `procedure_report` / `procedure_result` rows with UUIDs, so they appear as FHIR Observations (`DocumentIngestService.php`). Dedup by file hash and by (LOINC, date, value). |
| Intake items as OpenEMR/FHIR records | Met by decision (T1.1) | Medications, allergies, family history and chief concern are OpenEMR database records in the module's `copilot_intake` table, each with its page and bounding box, linked to the stored document. They are deliberately **not** filed into OpenEMR's medication and allergy lists; see [the decision below](#decision-intake-items-stay-patient-reported). |

### Decision: intake items stay patient-reported

The PRD asks for derived facts to be persisted as "appropriate" FHIR resources
or OpenEMR records. For an intake form, the appropriate record is a
patient-reported one, not an entry in the chart's medication or allergy list:

- **They are unreviewed.** An intake form is what the patient wrote at the
  front desk. Filing "penicillin allergy" or "metformin 500 mg" straight into
  the chart's lists would put unverified entries in front of every clinician
  who opens the chart, pharmacy checks included, before anyone has confirmed
  them.
- **They would duplicate.** Most intake medications are already on the list,
  under a different spelling or dose. Automatic filing needs matching and
  reconciliation, which is a clinician's job (medication reconciliation), not
  an extractor's.
- **They are still records, with provenance.** Each item is a row in
  `copilot_intake` tied to the stored OpenEMR document, with its page and
  bounding box. The briefing shows each one as a cited fact marked as coming
  from the intake form: medications and allergies must be surfaced, and a
  name or date of birth that disagrees with the chart is flagged, never stored.
- **Lab values are different.** A lab report is a clinical source document,
  so its anchored results are filed as real OpenEMR lab results
  (`procedure_result`, visible as FHIR Observations).

The right next step is a clinician-confirmed "add to chart" action per item,
recorded as future work rather than attempted before the deadline.

## 2. Structured schemas

> Use Pydantic, Zod, or equivalent strict schemas. Required lab fields include
> at least test name, value, unit, reference range, collection date, abnormal
> flag, and source citation. Required intake fields include demographics fields,
> chief concern, current medications, allergies, family history, and source
> citation.

| Part | Status | Evidence |
|---|---|---|
| All required lab fields | Met | `LabResult` / `LabReport` in `sidecar/copilot_sidecar/schemas.py`; `contracts/lab-report.schema.json`. |
| All required intake fields | Met | `IntakeForm` (demographics, chief_concern, medications, allergies, family_history, a citation on every item); `contracts/intake-form.schema.json`. |
| Strict | Met (T2.1 done) | Every model forbids unknown fields and uses strict typing (`ConfigDict(extra="forbid", strict=True)`): `"1"` is not an int and `"true"` is not a bool. The three date fields (`collection_date`, `reported_date`, `form_date`) still accept ISO date strings, since JSON has no date type. `test_models_refuse_values_of_the_wrong_type` pins it; the full gate passes with it. |
| Validation tests | Met | `sidecar/tests/test_contracts.py` (JSON Schema and Pydantic accept/reject the same examples), `ContractExamplesTest.php`, `SidecarClientTest.php`, `DocumentIngestServiceTest.php`. |

## 3. Basic hybrid RAG plus rerank

> Index a small clinical-guideline corpus. Retrieve with sparse+dense search,
> rerank candidate chunks with Cohere Rerank or an equivalent reranker, and
> feed only the top grounded evidence to the answer model.

| Part | Status | Evidence |
|---|---|---|
| Small corpus | Met | 6 guideline summaries, 30 chunks (`sidecar/corpus/`). |
| Sparse + dense | Met | BM25 plus `text-embedding-3-small` with committed vectors, fused by reciprocal rank (`retrieve.py`). |
| Rerank | Met | Cohere `rerank-v3.5`. `COHERE_API_KEY` is set on the droplet and reranking shows in its sidecar log (checked 2026-09-23). `W2_ARCHITECTURE.md` (now at the repo root) was corrected in `a2520f6`. With no key, or on a Cohere error, it quietly falls back to fused order; only the trace's `reranked` flag shows it. |
| Only top evidence to the model | Met, one caveat | Follow-up answers get the top 5 chunks. The briefing gets 2 per triggered guideline rule, up to 12 chunks, filtered by the critic. → **T3.2** |

## 4. Supervisor plus two workers

> Use LangGraph, the OpenAI Agents SDK, or another inspectable orchestration
> framework. Required workers are intake-extractor and evidence-retriever.
>
> (Stage 3) The supervisor should decide when extraction is needed, when
> evidence retrieval is needed, and when the final answer is ready. Keep
> handoffs explicit.

| Part | Status | Evidence |
|---|---|---|
| Inspectable framework | Met | LangGraph `StateGraph` (`sidecar/copilot_sidecar/graph.py`). |
| intake-extractor, evidence-retriever | Met | Nodes `intake_extractor`, `evidence_retriever` (plus an optional critic). |
| Supervisor decides extraction / retrieval | Met | Deterministic rules in `supervisor_node`; PHP chooses the request mode first. |
| Supervisor decides "final answer is ready" | Met (T4.1 done) | The graph still ends when the evidence is ready; answering stays in PHP, which owns the chart, access checks and the Verifier. PHP now appends the answer stage to the same handoff log (`src/AnswerRoute.php`): `supervisor → answer_writer → verifier → done` with a fixed outcome (`answer_verified`, `answer_refused`, `all_stripped`, `no_claims`), `model_failed`, or `cached_draft` for a cached briefing. It is in the log line and the trace. |
| Handoffs explicit and logged | Met | `Handoff{from,to,reason,state_keys_changed,ms}` with fixed reasons; one log line per hop; returned in the response; Langfuse worker spans. |
| Handoffs visible | Partly met | The "Why this result" drawer shows routing only after a document extraction, not for briefings or questions. → **T4.2** |
| Known bug | Open | The hop back from the extractor is labelled `no_question` (TODOS.md). → **T4.3** |

## 5. Citation contract

> Every clinical claim in the final response must include machine-readable
> citation metadata. Minimum citation shape: {source_type, source_id,
> page_or_section, field_or_chunk_id, quote_or_value}. A visual PDF
> bounding-box overlay is required.

| Part | Status | Evidence |
|---|---|---|
| Five-field shape defined | Met | `contracts/citation.schema.json` has exactly the five fields, plus `bbox`, `row_bbox`, `anchored`. |
| Chart and document facts cited | Met | `Fact::citationOrChart()`; lab/intake facts carry document citations with boxes. |
| Guideline evidence cited in the same shape | Met (T5.1 done) | Every guideline passage (briefing cards and answer guidelines) now carries a `citation` in the five-field shape (`source_type: guideline`), next to its existing fields (`EvidenceChunk::citation()`). |
| Every sentence carries its citation | Met (T5.1 done) | Each sentence carries `citations`: one full five-field citation per id it cites, chart, document or guideline (`PanelPayload::sentences`). Required by `contracts/sentence.schema.json`; `ContractsTest` checks both shapes. |
| Unsupported sentences removed | Met | `Verifier` strips uncited, unknown-id, mixed and ungrounded-number sentences. |
| PDF bounding-box overlay | Met | PDF.js viewer draws the row box and the value box, for lab PDFs and intake forms, including scanned pages (`public/source-viewer.js`). |
| From a claim to the PDF in one click | Met (T5.2 done) | A citation chip in the summary that cites a lab PDF or intake form is a button: one click (or Enter) opens the page with the row and cell boxed. Checked in the browser on a real briefing (Vitamin D 27.1, document page 5, two boxes drawn). Chart and guideline chips keep the hover highlight. |

## 6. Eval-driven CI gate

> Build a 50-case golden set and a PR-blocking Git Hook. Boolean rubric
> categories must include schema_valid, citation_present, factually_consistent,
> safe_refusal, and no_phi_in_logs. The build must fail if any category
> regresses by more than 5% or drops below the pass threshold.
>
> (Hard gate) During grading, we will introduce a small regression and confirm
> your CI gate fails.

| Part | Status | Evidence |
|---|---|---|
| 50-case golden set | Met | 70 cases: 54 run by the hook, 16 live. They cover extraction, retrieval, citations, refusals and missing data ([EVAL_GATE.md](../EVAL_GATE.md)). |
| Five rubric categories | Met (T6.2 done) | All five are scored by the hook. `no_phi_in_logs` comes from cases 69 and 70: the real upload and extract controllers with a recorded model reply (sidecar `/eval/run-recorded`), scanning PHP logs, traces and sidecar logs. A planted leak fails both cases and the gate. Cases 19, 20 and 22 no longer claim the rubric they never scanned. |
| Fails on >5% regression or below threshold | Met (T6.1 done) | `gate.php` fails below threshold, and for the deterministic cases the hook runs, on **any** case that flipped from pass to fail. Before T6.1 a single broken case in a 90% rubric (a 3.3-point drop) passed; the self-test now flips exactly that and requires a refusal. Live cases keep the 5-point allowance for model variance. |
| Git hook blocks pushes | Met | Pre-push hook, proven from a fresh clone ([EVAL_GATE.md](../EVAL_GATE.md) §5). Client-side only; GitLab refuses student pipelines. |
| Judge configuration and results | Partly met | No LLM judge (every rubric is a code check), but no document says so. `tests/evals/results.json` is from 2026-09-22 and covers cases 1-52 only; README counts are stale. → **T6.3** |

## 7. Observability and cost tracking

> Each encounter must log tool sequence, latency by step, token usage, cost
> estimate, retrieval hits, extraction confidence, and eval outcome. Logs must
> not contain raw PHI.

| Item | Status | Evidence |
|---|---|---|
| Tool sequence | Met | PHP step spans plus sidecar handoff spans in Langfuse; `handoff` log lines. |
| Latency by step | Met | `duration_ms` per step span; sidecar `ms` per hop. |
| Token usage | Met | Per-call generations and totals on every trace. |
| Cost estimate | Met | `Pricing.php` list-price table; `cost_usd` on each trace and audit line. |
| Retrieval hits | Met (T7.1 done) | Every briefing and answer where retrieval ran records `retrieved_chunks` (passages retrieval returned) and `guideline_chunks` (passages the response cites) on its log line and trace, and a `retrieval_hit` score (retrieved > 0). Checked on real briefings: patient 30 retrieved 6, cited 0. |
| Extraction confidence | Met | Share of anchored citations, logged and traced per extraction. |
| Eval outcome | Partly met | Each encounter records the verifier's outcome (`verification_pass`, `stripped`, `routing_ok`, `extraction_ok`), but nothing calls this the per-encounter eval outcome. → **T7.2** |
| No raw PHI in logs | Partly met | The sidecar enforces a log-field allowlist at runtime. **PHP does not**: the allowlist exists only in the eval test. No raw text was found in logs. → **T7.3** |

---

## Task list

Ordered by risk to grading. Each task is closed only after it is verified.

- [x] **T6.1 Make the gate catch a single regressed case.** Done: any flip fails for deterministic cases; the self-test flips one `factually_consistent` verdict (about 97%, above threshold) and the gate refuses it, where the old rule said `ok`.
- [x] **T6.2 Score `no_phi_in_logs` in the hook.** Done: cases 69 and 70 (no key needed) score it at 100%; a planted leak in the controller's log line takes it to 0% and the gate refuses. Also fixed: a case with a failed rubric now prints FAIL (it printed PASS). Add deterministic cases that run the real logging path with recorded model output, and stop the three live extraction cases passing it vacuously.
- [x] **T5.1 Guideline citations in the five-field shape.** Done: `citation` on every guideline passage, `citations` on every sentence, contracts updated, `ContractsTest` covers chart and guideline citations in answers and briefings.
- [x] **T4.1 Supervisor decides "final answer is ready".** Done: the answer stage is logged as handoffs with a fixed ready/refused/stripped/failed outcome (`AnswerRoute`), checked on a real briefing through the controller. Either bring answer verification into the graph's decision or document the split.
- [x] **T1.1 Intake items as OpenEMR records.** Decided: they stay patient-reported in `copilot_intake`, with the clinical reasons written up under requirement 1; a clinician-confirmed "add to chart" action is future work.
- [x] **T5.2 One click from a claim to the PDF overlay.** Done: document citation chips open the source viewer with the boxes (mouse or keyboard); checked in Selenium Chrome.
- [x] **T7.1 Retrieval hits on every encounter** Done: `retrieved_chunks` and `guideline_chunks` on briefings and answers, `retrieval_hit` from the retrieved count., including briefings; record retrieved as well as cited.
- [ ] **T7.3 PHP runtime log allowlist**, matching the sidecar's. Deferred on 2026-09-23 (after the early submission). Known gap until then: PHP log fields are checked against the allowlist only at push time, by the PHI eval cases (69 and 70 in the hook, 36-38 and 68 live), not at runtime. No raw text was found in any PHP log line.
- [x] **T2.1 Pydantic strict typing.** Done: strict everywhere, ISO strings for the three dates; probed in the container before committing.
- [x] **T1.2 Return the validated extraction JSON** from `copilot:attach`. Done: `--json` prints the contract-validated extraction under `extraction`.
- [ ] **T4.2 Show routing for briefings and questions** in the "Why this result" drawer.
- [ ] **T7.2 Name the per-encounter eval outcome** in the docs and trace.
- [ ] **T3.2 Cap briefing evidence**, or document why the cap is per rule.
- [ ] **T4.3 Fix the `no_question` handoff label.**
- [ ] **T6.3 Judge configuration and fresh results:** state there is no LLM judge, commit current `results.json`, fix README counts.
- [x] **T3.1 Update `W2_ARCHITECTURE.md`** Done in `a2520f6` (by the submission-docs session; the file moved to the repo root): rerank shown as live, observability covers briefing and follow-up traces.
