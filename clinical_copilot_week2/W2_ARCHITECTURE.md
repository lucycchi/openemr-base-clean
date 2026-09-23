# W2_ARCHITECTURE.md — Clinical Co-Pilot, Week 2 (multimodal evidence agent)

Status: Phases 0–8 built and deployed (2026-09-23); Phase 9 (Thursday) adds the
per-tier cost section, USERS.md and the remaining fixtures. Plan and phased
TODO list: [DESIGN.md](DESIGN.md). Engineering requirements re-audited
against this code, one by one: [ENGINEERING_REQUIREMENTS.md](ENGINEERING_REQUIREMENTS.md).
Week 1 architecture (unchanged, extended here): [../ARCHITECTURE.md](../ARCHITECTURE.md).

## Stack decision: PyMuPDF + tesseract, not Docling (spike, 2026-09-21)

The first task of Week 2 was to measure the document-extraction stack on the
deployed droplet before writing any sidecar code, because every later phase
depends on it. Droplet: DigitalOcean, 2 vCPU, 3.9 GB RAM, no swap, 70 GB
disk; OpenEMR + MariaDB use about 1.1 GB, leaving about 2.8 GB.

Fixtures: a synthetic 5-page lab report with a text layer (20 analytes in a
Test / Result / Flag / Units / Reference / Previous table, synthetic name),
and the same report rasterized at 150 dpi as JPEG pages with no text layer
(a scan).

| | Docling (`ghcr.io/docling-project/docling-serve:latest`) | PyMuPDF + tesseract 5.5 (`python:3.12-slim`) |
|---|---|---|
| Image size, pull time | 15.2 GB, 4 min 24 s | about 200 MB |
| Idle RSS | 1.7 GB | 18 MB |
| Text-layer PDF, 5 pages | 74 s cold, 68 s warm | 6 ms (text layer read, no OCR) |
| Scanned PDF, 5 pages | OOM-killed at a 2.5 GB cap (11 s in); 82 s with a 3 GB cap | 11.8 s (2.4 s/page at 200 dpi, `--psm 6`), under 100 MB |
| Words recovered from the scan | 5 pages, 5 tables | 256 of 256 words, per-word confidence 86-96 |
| Row structure | table cells | tesseract `(block, par, line)` groups give the row directly: `LDL Cholesterol 36.5 H mg/dL <100 33.7` |

Docling fails both thresholds set in the plan (more than 10 s per page; needs
more RAM than is free beside OpenEMR). Tesseract meets them with room to
spare. Decision for the week: **PyMuPDF for text-layer pages and the
canonical bounding boxes, tesseract (`image_to_data`, TSV) for pages with no
text layer**, row anchoring from tesseract line groups on scans and from
PyMuPDF word boxes on text pages. Docling remains the documented alternative
for a droplet with 8 GB or a GPU; nothing in the contracts depends on the
parser, so swapping it later is confined to `sidecar/extract.py`.

Consequences carried into the plan:

- The 5-page cap and 60 s synchronous extraction budget in the design doc are
  safe on this droplet: worst case measured 11.8 s for OCR plus one model
  call, well under 30 s, so the job/poll form is not needed in v1.
- The sidecar image is small enough to build on the droplet itself (`build:`
  context in `docker/vps/docker-compose.yml`), no registry needed.
- Rasterization at 200 dpi is the anchoring coordinate space for scans;
  `anchor.py` converts tesseract pixel boxes to PDF points using the page's
  rasterization scale.

## Reranker

Decision (2026-09-21): **Cohere Rerank via API** (`rerank-v3.5`). No key
existed on the host or the droplet at the spike; a trial key is created by
the developer and set as `COHERE_API_KEY` in both `.env` files. Rationale:
the droplet has no RAM to spare for a local cross-encoder, and the PRD names
Cohere first. `bge-reranker-base` is the documented equivalent for an
offline deployment; it is not built this week.

## Where things are

Code: the module at `interface/modules/custom_modules/oe-module-clinical-copilot/`
(`sidecar/` for Python, `src/Documents/` and `src/Controller/DocumentController.php`
for PHP, `public/documents.php`, `public/assets/source-viewer.js`, `sql/0_1_1-to-0_1_2_upgrade.sql`).
Evals and the gate: `tests/evals/` ([README](../tests/evals/README.md)). CLI: `bin/console copilot:attach <pid> <file.pdf> <lab_pdf|intake_form> --site=default` (run as the web user inside the openemr container) is the brief's `attach_and_extract`.
Deploy: `docker/vps/` ([README](../docker/vps/README.md)). The full index of
Week 2 documents, including the ones kept beside code, is
[README.md](README.md) in this folder.

## Tools, by step (decided; see the design doc for the decision record)

| Step | Tool | Status |
|---|---|---|
| Read a PDF / OCR a scan | PyMuPDF (text layer, word boxes), tesseract 5.5 (scans, word boxes with confidence), 200 dpi | Decided by the spike above |
| Turn page text into fields | gpt-4o-mini, Structured Outputs, one call per page, Pydantic proposal schemas | Decided |
| Prove each field | `sidecar/copilot_sidecar/anchor.py`: row-level anchoring, header-aware result/prior columns, omission detection, one targeted retry | Decided |
| Sample data | Synthetic PDFs from `sidecar/tools/generate_fixtures.py` (fictional patient "Test Zeta") plus real blank templates filled with invented answers, vetted in `DOCUMENT_SOURCES.md` | Generator done; sourced forms pending vetting |
| Guideline evidence | No API. Six guideline summaries written for this project in our own words (ACC/AHA cholesterol 2018, ACC/AHA hypertension 2017, ADA Standards 2025, KDIGO 2024, anemia in adults, USPSTF screening), `sidecar/corpus/` with a manifest naming publisher, year and URL | Done (30 chunks) |
| Retrieval | rank_bm25 (keyword, stop words removed) + OpenAI text-embedding-3-small (dense, index committed under `corpus/index/`), reciprocal rank fusion (k=60), a relevance floor so off-corpus questions return nothing, Cohere Rerank v3.5 when `COHERE_API_KEY` is set (recorded in usage; RRF order otherwise) | Done; rerank inactive until the key exists |
| Orchestration | LangGraph StateGraph (`sidecar/copilot_sidecar/graph.py`), deterministic supervisor, workers injected, handoff log per hop | Done |
| Storage | OpenEMR `Document` class, `procedure_*` lab tables, three module tables (`copilot_document`, `copilot_document_fact`, `copilot_intake`) | Done |
| Viewer | pdf.js 4.10.38, vendored under `public/assets/vendor/pdfjs/` | Done |
| Evals / gate | `tests/evals/run.php` + `gate.php`, justinrainbow/json-schema for contract checks, `pre-push` git hook; the wrapper also runs the sidecar's pytest and the module's isolated PHPUnit suite | Done |
| Observability | Langfuse traces per request (Week 1), extended with sidecar handoffs and usage | Done for extraction |

## Agents: how many, and how they cooperate

Three roles run in the sidecar, one of which never calls a model; PHP keeps
the trust boundary and is the only component that writes physician-facing
text.

| Role | Model? | Job |
|---|---|---|
| Supervisor | No (deterministic rules) | Reads the run state: a stored document that has not been extracted goes to the intake-extractor; a question goes to the evidence-retriever; otherwise done. Every decision is one `Handoff {from, to, reason, state_keys_changed, ms}` (`contracts/handoff.schema.json`). |
| Intake-extractor (worker) | gpt-4o-mini | Parses the PDF, asks the model for values page by page, anchors every value to its row, marks what it cannot anchor as unverified, reports rows it could not extract. Never writes prose. |
| Evidence-retriever (worker) | Embeddings + rerank, no generation | Hybrid retrieval over the guideline corpus, top 5 chunks with citations. The PHP narrator may cite a chunk id; the Verifier checks the sentence's numbers against the passage (heading + text) exactly as it checks fact ids. |
| Critic (extension, Phase 10) | Rules first | Rejects uncited claims and action suggestions without guideline support. |
| Narrator + Verifier (Week 1, PHP) | gpt-4o-mini | The only physician-facing text. Cites fact ids (and, after Phase 6, chunk ids); the Verifier strips anything uncited or with a number not present verbatim in the cited source. |

```
front desk uploads a lab PDF ─▶ PHP documents.php: CSRF, session patient, ACL patients/docs,
                                  patient-scoped dedup, OpenEMR Document API, copilot_document{stored}
                                          │ action=extract (one document, 60 s budget)
                                          ▼
                    sidecar /run ─▶ supervisor ─▶ intake_extractor ─▶ supervisor ─▶ done
                                   (stored_document)   parse, propose,   (worker_finished) (no_question)
                                                       anchor, retry
                                          │ run.response: extraction + citations + handoffs + usage
                                          ▼
                    PHP DocumentIngestService: one transaction, procedure_order/order_code/report/result
                    with document_id and uuids, provenance rows, unverified rows kept visible
                                          │
physician opens the chart ─▶ FactAssembler (Week 1) now includes the new labs with citations and
                             extraction_unverified facts ─▶ narrator ─▶ Verifier ─▶ panel
                             panel: "source p.N" opens the pdf.js viewer on the row and cell
physician asks a question ─▶ supervisor ─▶ evidence_retriever ─▶ done ─▶ PHP narrator cites facts
                             and guideline chunks; Verifier checks both (Phase 6)
```

The sidecar never touches the database and never sees the patient's name;
it receives bytes, a facts hash and the question, and returns JSON.

## Ingestion: from an upload to a cited fact

The document path is the Week 2 addition to the Week 1 pipeline; nothing in
Week 1's fact assembly, narration or verification was rewritten, it was
extended with a new fact source.

| Step | Where | What happens | Why this way |
|---|---|---|---|
| 1. Upload | `public/documents.php` → `DocumentController::upload` | CSRF, session patient, ACL `patients/docs` write or addonly; PDF magic bytes and a 20 MB cap; sha3-512 dedup per patient; stored through OpenEMR's own `Document` class in the "Lab Report" / "Patient Information" category; a `copilot_document` row `stored` | The file is a normal OpenEMR document (visible in the chart's Documents tab, subject to its retention and access rules), not a private blob. Dedup makes re-uploads and the API collection idempotent |
| 2. Extract | `DocumentController::extract` → `ExtractionRunner` → `SidecarClient::extract` | one `run.request` with the bytes (base64), the facts hash and the correlation id; 60 s budget; the reply validated against `run.response` before parsing | The sidecar sees bytes and ids, never the patient's name or chart. The contract is the gate (requirement 3) |
| 3. Parse | sidecar `parse.py` | PyMuPDF words with boxes on text pages; tesseract at 200 dpi with `--psm 6` on pages with no text layer; at most 5 pages; rows grouped by line | The spike's decision; rows are the anchoring unit |
| 4. Propose | sidecar `llm.py`, one call per page | gpt-4o-mini, Structured Outputs against the proposal contract; values only, as printed | One call per page cut omissions from 7 of 20 rows to 0 of 20; the model never emits coordinates or codes |
| 5. Anchor | sidecar `anchor.py` | each proposed value must be found in a row that also carries its analyte and unit, on its page; header-aware result/prior columns; OCR folds (`Aic`→`A1c`) and guarded fuzzy units; LOINC from `loinc_map.json`; a value that cannot be proven is marked `anchored: false`; printed rows the model skipped are listed as `unextracted` and re-asked once | "Model proposes, code anchors": the only thing that reaches the chart is what the code found on the page |
| 6. Persist | `DocumentIngestService::persist`, one transaction | anchored results → `procedure_order` / `procedure_order_code` / `procedure_report` / `procedure_result` (`document_id`, uuids), so they are real OpenEMR labs; citations and unanchored values → `copilot_document_fact`; intake items → `copilot_intake`; demographics on the form are compared with the chart and **never stored**, only fixed mismatch phrases | Real lab rows mean Week 1's `labs()` query, reference ranges and deltas apply unchanged; idempotent on repeat |
| 7. Surface | `OpenEmrChartSource` + `FactAssembler` | labs read from documents carry a `Citation` (page, bounding box); unanchored values become `extraction_unverified` must-surface facts; intake items and mismatches become facts; document facts surface even for a patient with no prior visit | What the extractor could not verify is shown, not hidden (eval cases 43, 44, 51) |
| 8. Show | `panel.js`, `source-viewer.js` | "source p.N" opens pdf.js on the page and draws the row and the cell | The whoa moment: click a value, see where it came from |

## Retrieval and guideline evidence

There is no guideline API. The corpus is six summaries written for this
project in our own words (`sidecar/corpus/`, manifest with publisher, year
and URL per document), chunked by `##` section into 30 chunks. The index is
built once by `tools/build_index.py` and committed (`corpus/index/`:
chunk list plus `text-embedding-3-small` vectors), so a container start and
the offline eval cases never call the embeddings API.

A question goes supervisor → `evidence_retriever`: BM25 over tokens with
stop words removed (keyword leg) and cosine over the committed vectors
(dense leg; the query is embedded live, or supplied by the harness),
fused by reciprocal rank fusion (k = 60); a relevance floor (cosine ≥ 0.25,
or a keyword hit with cosine ≥ 0.15, or a strong keyword score) drops
chunks neither leg found relevant, so an off-corpus question returns
nothing rather than the least-bad passage (eval cases 32, 34). The top
candidates are reranked by Cohere `rerank-v3.5` when `COHERE_API_KEY` is
set (recorded in usage and on the trace as `reranked`), else RRF order
stands; the top 5 go to PHP as `Chunk {chunk_id, source_id, section, quote,
score}`.

PHP's follow-up prompt then carries the passages with their 12-character
ids after the facts. A sentence about the patient must cite 8-character
fact ids; a sentence about what a guideline says must cite the passage id
and quote its numbers exactly; the Verifier checks a passage-cited sentence
against the passage's heading and text the same way it checks a fact-cited
sentence against the fact, and strips anything else (cases 33, 35, 45, 46).
The panel renders "From the record" and "From guidelines" apart. Answers
are capped at six sentences ([experiments/answer-length-cap.md](experiments/answer-length-cap.md)).

## The gate

Every push runs `tests/evals/gate.sh` from the pre-push hook
(`tests/evals/install-hooks.sh`): the sidecar's pytest, the module's
isolated PHPUnit suite, `case-index.php --check`, then the 39 deterministic
golden cases through `gate.php`, which compares each case's rubric verdicts
with the committed baseline and refuses the push on a threshold breach or a
per-case regression of more than five points. `COPILOT_GATE_LIVE=1` adds
the 13 live cases. There is no server-side CI (GitLab pipelines are not
available to students), so the hook is the gate and `--no-verify` is the
only way round it; the recorded refusal transcript is in
[../tests/evals/README.md](../tests/evals/README.md#how-graders-test-the-gate).

## Risks and trade-offs

| Risk | Where it bites | What limits it | What is accepted |
|---|---|---|---|
| The model omits printed rows | extraction | one call per page; omission detection lists unextracted rows and re-asks once; `unextracted` is a must-surface fact and a watched rate | a row can still be missed twice; it is then visible as "unextracted", never silently absent |
| A layout the anchor step does not read | extraction on real-world reports | row-level rule is layout-agnostic (analyte + value + unit in one row); OCR folds; the `extraction_verified` rate watches it | the fixtures are synthetic and two layouts; real reports (DOCUMENT_SOURCES.md) are Thursday's work |
| OCR memory on scans | sidecar, 768 MB cap on the droplet | 5-page cap, 200 dpi, tesseract instead of Docling | a longer scan is refused (`too_many_pages`) rather than split |
| Corpus coverage and currency | guideline answers | six summaries with publisher/year; the relevance floor refuses off-corpus questions; `retrieval_hit` is watched | a question the corpus does not cover gets a facts-only answer; the summaries are ours, not the guidelines themselves |
| Reranker absent | answer quality | RRF order is the fallback; `reranked` on the trace says which ran | quality difference not yet measured (no key on the droplet as of the deploy) |
| Latency budgets | extraction 60 s, follow-up 28 s | per-page calls, the six-sentence cap, timeouts inside budgets | a slow provider day still times out one call in the eval set (case 12 history); the gate flakes rather than the product failing |
| PHI in the sidecar | trust boundary | bytes in memory only, no disk, no database, no patient name in the request, log allowlist, correlation id on every line | the model provider sees document text (as it does chart facts in Week 1); covered by the provider agreement, not by code |
| Observability transport | Langfuse v3 ingestion | delayed 10–30 min for non-score events, shut down 2026-11-16; scores are immediate | OTLP migration in Phase 9 |
| Single droplet, no CI | deploy | `deploy.sh` is the CI job by hand; the hook is the gate | a bad deploy is rolled back by checkout + redeploy; the sidecar is stateless |

## Review console and rating calibration (Phase 4b, planned)

Extraction quality is judged by a human, not only by the evals. An internal
review page (served by the sidecar under `COPILOT_EVAL_ENDPOINTS=1`, so it
never exists on the droplet and is not customer-facing, which is the
industry pattern: annotation happens in a separate tool and only scores
reach the observability platform) shows each extracted field with its page
crop and anchored flag; the reviewer marks fields correct / wrong / partial,
enters corrections, and gives the document a 1-5 score with fixed anchors:

| Score | Meaning |
|---|---|
| 5 | Every clinical field correct and anchored; nothing missed |
| 4 | All correct; one or two fields unverified or one metadata field off |
| 3 | One clinical field wrong or missing, but flagged as unverified |
| 2 | A clinical field wrong and presented as verified, or several missed |
| 1 | Not usable: wrong patient, wrong document type, or mostly wrong |

The system produces its own 1-5 self-rating from the anchored fraction,
unverified count, unit mismatches and schema validity. `calibration.php`
compares the two per document (exact agreement, mean absolute difference,
the fields driving disagreement); corrections become `truth.json` files and
new anchor-mode eval cases, never prompt changes. Lab and intake documents
are scored separately. Numeric scores are mirrored to Langfuse; document
text is not. Details: design doc Phase 4b.

## Test design: boundaries, invariants, regressions

The eval suite is designed against failure, not against the happy path. Every
case declares a `guards` category and a plain-English `failure_mode`, and
`tests/evals/case-index.php --check` (run by the gate) refuses a push if a
case is missing either, or if the generated table in
[../tests/evals/README.md](../tests/evals/README.md) is stale.

| Category | Cases | What it means here |
|---|---|---|
| invariant | 27 | Something that must hold on every run: a claim cites a source, a value is found on the page in its own row and on the right page, a must-surface fact is never dropped, an invented value never anchors, an identifier never leaves the server (the six authorization and PHI cases carry the theme `authorization`) |
| boundary | 18 | The edges: an empty fact set, a question outside the briefing window, an off-corpus question, malformed input (truncated, encrypted, over-long, blank-scan PDFs), and missing data inside a document (a blank intake form, a lab row without a unit, a report without a collection date, which must be refused) |
| regression | 7 | Things that broke once: the inline citation group (Week 1), one model call omitting 7 of 20 rows, OCR mangling a unit, a guideline passage cited inline with an empty id list, a year that lives in a passage's heading, document facts hidden for a patient with no prior visit |

The categories are the three the brief names; `case-index.php --check` refuses
any other value. A `facts` mode (cases 50-52) reaches the layer between "the
sidecar returned JSON" and "the physician sees a cited fact": it persists a
recorded extraction for a temporary patient with no encounters, assembles
facts through the real `FactAssembler`, and removes everything; no model, so
it runs in the default gate. Reverting the no-prior-visit fix makes case 50
fail `anchor_correct`, which refuses the push.

Two design rules keep the "clean" fixtures honest. First, every fixture that
can be extracted correctly has a sibling case that feeds a **wrong or absent
value** through the same path and requires it to come back unverified (cases
18, 43, 44). Second, the cases that look like a happy run are there because
they failed once: the five-page report is the case that exposed the model
omitting 7 of 20 printed rows, and the scanned copy is the case that exposed
OCR unit mangling.

## Correlation id: one id, every boundary (requirement audit, 2026-09-22)

Requirement: every request carries a unique correlation id across service
boundaries, present in every log entry, tool call and LLM interaction, so a
full trace can be reconstructed from logs alone.

| Boundary | How the id travels | Enforced by |
|---|---|---|
| Request start | `CorrelationId::generate()` (16 random bytes) once per chat request, document request, `copilot:attach` run and prewarm row | — |
| Every PHP log entry | `CorrelatedLogger` decorator adds `correlation_id` to every record; code cannot forget it | `CorrelatedLoggerTest` |
| Every response, including 400/401/403/500 | `correlation_id` in the JSON body and an `X-Correlation-Id` header | `PanelPayloadTest` |
| PHP → OpenAI (briefing, follow-up) | OpenAI's per-request `user` field and an `X-Correlation-Id` header | `OpenAiClientTest` |
| PHP → sidecar | required `correlation_id` in `run.request` (echoed in `run.response` / `run.error`) and the same `X-Correlation-Id` header | `ContractsTest`, `test_app` |
| Inside the sidecar | a middleware binds the header's id to a context variable before the body is parsed; `/run` rebinds to the body's id; the JSON formatter writes it on **every** line, so a `log.info()` without `extra=` still carries it (`logging_setup.py`) | `test_logging`, `test_app` |
| Sidecar log lines per run | `run` (mode, hops, ms), one `handoff` per supervisor hop (from, to, reason, ms), one `model_call` per proposal / embedding / rerank (model, kind, page, tokens, ms), `retrieved` (count, rrf-or-rerank, ms), `extracted` / `extract failed`; uvicorn's access line is bound too | eval cases 36-38 (`uncorrelated_log_lines` must be 0) |
| Sidecar → OpenAI (extraction per page, retry, query embedding) | `user` field + `X-Correlation-Id` header, same as the PHP client | — |
| Sidecar → Cohere rerank | `X-Correlation-Id` via `request_options.additional_headers` | — |
| Traces | the Langfuse trace id *is* the correlation id; steps, generation, scores and handoffs hang off it | `LangfuseTracerTest` |
| Persistence | `copilot_document.correlation_id` names the run that extracted the document | `DocumentIngestServiceTest` |
| Audit | OpenEMR's `EventAuditLogger` line carries `correlation_id=` | — |

Reconstruction from logs alone: `grep <id>` across the PHP log and the
sidecar log yields the request line, the route (handoffs in order), each
model call with tokens and latency, the extraction or retrieval outcome, and
the HTTP status; the same id opens the Langfuse trace and the document row.

Found during the audit (fixed the same day): the Week 2 sidecar had regressed
what Week 1's PHP client did right. Its OpenAI, embedding and rerank calls
carried no id, only two log events (`extracted` / `extract failed`) had it,
and answer-mode retrieval logged nothing at all; the id was a function
argument, so any new log line could drop it. The context-variable binding
and the per-hop / per-call lines above closed that, and the PHI cases now
fail the gate if any sidecar line lacks the request's id.

## Findings and open issues (running log)

- **2026-09-22, the sidecar in readiness.** Design task 2.3 said `/ready`
  probes the sidecar; it did not. Now the sidecar has its own `GET /ready`
  (contracts mounted and parsing, LOINC map, retrieval index consistent
  with its chunk list, tesseract on PATH, OpenAI key configured; 503 when
  any is missing; Cohere reported as optional) and PHP's `ready.php` probes
  it as a fourth dependency, degraded-only like Langfuse: briefings do not
  need the sidecar, extraction and guideline evidence do. Proven by
  stopping the container: `ready.php` answered 200 `degraded` with
  `sidecar unreachable`, then `ready` again after restart.
  [ENGINEERING_REQUIREMENTS.md § 6](ENGINEERING_REQUIREMENTS.md#6-separate-health-and-ready-endpoints).

- **2026-09-22, the Week 2 agent in the dashboard.** The sidecar's workers
  were invisible to Langfuse's tool-call widgets (handoffs travelled as a
  metadata blob), its per-page model calls, embedding and rerank were
  collapsed into one generation with under-counted cost, its retries were
  not in the contract, no Week 2 outcome was a chartable rate, and the
  pre-warm sweep (a real queue) sent nothing. Now: one span per worker hop
  (ERROR on `worker_failed`), one generation per sidecar call with cost,
  `retries` in `run.response`, five scores (`extraction_ok`,
  `extraction_verified`, `retrieval_hit`, `routing_ok`, `prewarm_ok`), and
  one `copilot.prewarm` trace per sweep with the queue numbers. Widgets and
  decisions: [DASHBOARD.md](DASHBOARD.md).

- **2026-09-22, follow-up answers capped at six sentences** (`Prompt::VERSION`
  `2026-09-22.5`). The live gate refused three pushes on case 12: for the
  28-fact seed chart, the ambiguous question "Is it higher than it was last
  time?" made the model enumerate every changed value (10-25 sentences,
  4-10 s, and past the 20 s limit on a slow provider day) with an unchanged
  prompt. Measured caps of 3, 6 and 10 sentences (five calls each): medians
  1.2 s, 3.5 s and 4.5 s against 7-8 s uncapped, all answers still cited.
  Six was chosen: room for a value, its date, the comparison, a second value
  and the required guideline sentence; the fact table beside the answer
  still lists everything and the omission guard is unchanged. Data, script
  and rejected alternatives: [experiments/answer-length-cap.md](experiments/answer-length-cap.md).

1. **Case 09 is flaky on one patient.** `09-live-seed-patients-zero-strips`
   briefs the ten busiest seed patients with the real model; pid 22 timed
   out twice on 2026-09-22 (about 24 s, the Week 1 client timeout) while
   passing in between, and a third time on pid 15 (31 facts, 22 sentences)
   in Phase 7. Cause: the Week 1 per-attempt cap of 12 s is below what
   gpt-4o-mini needs for a dense briefing. Resolved 2026-09-22 by raising
   the per-attempt cap to 20 s and the total budget to 28 s (still under
   the panel's 30 s fetch timeout); recorded here because it changes a
   Week 1 constant.
2. **Dense document-derived fact sets raise the briefing strip rate.** After
   two lab reports were attached to one patient (27 facts, 16 lab deltas)
   the model's briefing had 1-3 stripped sentences; one stripped sentence
   carried a malformed inline fragment (`[id].},{`) and quoted a number from
   an uncited fact. The Verifier removed it correctly. This is the Week 1
   omission/attribution behaviour under a larger fact set, not a Week 2
   bug; it argues for the fixed briefing sections in TODOS.md and for a
   live case that measures strip rate on a document-heavy chart.
3. **Fixture documents on seed patients.** End-to-end runs uploaded
   `lab-layout1.pdf` (patient name "Test Zeta") onto pid 28, the seed
   patient case 09 uses first. The dev database was cleaned
   (`tmp/cleanup.php <pid>`); the droplet's pid 28 still carries the
   document so the demo has something to show. The report's name does not
   match the chart, which the Phase 4 demographics check will surface as a
   fact. Use a dedicated demo patient for demo uploads.

(Sections still to come in Phase 8: RAG design, eval gate summary, risks and
tradeoffs, cost and latency pointers.)
