# Submission requirements: status and tasks

The **Submission Requirements** table from the Week 2 PRD (page 6), plus the
other hard gates the PRD sets for what is handed in (pages 2-3), checked
against the repo on `pdf_reader` on 2026-09-23 at 20:30 Central. Each row
quotes the PRD, gives its status, and links the evidence. Every gap becomes a
task in the [task list](#task-list).

The seven **Core Agent Requirements** (what the agent must do) are tracked
separately in [CORE_AGENT_REQUIREMENTS.md](CORE_AGENT_REQUIREMENTS.md). This
file covers what has to be **handed in**: documents, evidence, the deployed
app, the video, the interviews.

Status key: **Done**, **Partly done** (exists, with a gap a grader could point
to), **Not done**, **User** (only the user can do it: record, post, schedule).

## Deadlines (Central time)

| Checkpoint | When | What the PRD asks for |
|---|---|---|
| Early submission | **Wed 2026-09-23, 23:59** | Deployed agent, eval framework in place, observability wired in, demo video. AI interview within 24 h. |
| Technical interview | Thu 2026-09-24 or Fri 2026-09-25 | Staff ask about the project, key decisions, coding workflow. |
| Final | **Sun 2026-09-27, 12:00** | Production-ready agent, demo video, social post. AI interview within 24 h. |

## Summary

| # | Deliverable | Status | Open tasks | Due |
|---|---|---|---|---|
| 1 | GitLab repository | Partly done | S1 | Early |
| 2 | W2 architecture doc (`./W2_ARCHITECTURE.md`) | Done | none | Early |
| 3 | Key metrics doc (`./KEY_METRICS.md`) | Done | S10 (refresh at final) | Early |
| 4 | Schemas | Done | (T2.1 in core list) | Early |
| 5 | Eval dataset | Done | none | Early |
| 6 | CI evidence | Done | S10 (re-run proof at final) | Early |
| 7 | Demo video | User | S2 | Early and final |
| 8 | Cost and latency report | Partly done | S6 | Final |
| 9 | Deployed application | Partly done | S1 | Early |
| 10 | Technical interview | User | S8 | Thu/Fri |
| 11 | Social post | User | S9 | Final |
| 12 | AI interview | User | none | after each submission |
| H1 | README separates Week 1 from Week 2; grader can run Week 2 without guessing | Done | none | Early |
| H2 | Week 1 debt documented and resolved | Partly done | S6, S7 | Final |
| H3 | HIPAA-minded: synthetic data only, no raw PHI in logs, screenshots and video treated as sensitive | Done (watch the video) | S2 | Early |

---

## 1. GitLab repository

> Week 1 fork with Week 2 changes, setup guide, deployed link, and clear
> environment-variable documentation.

| Part | Status | Evidence |
|---|---|---|
| Week 1 fork with Week 2 changes | Done | `ssh://git@labs.gauntletai.com:22022/lucychi/openemr.git`. GitLab's default branch is `pdf_reader`, so graders land on the Week 2 code. |
| Up to date on GitLab | Partly done | Local `pdf_reader` is 1 commit ahead of `gitlab/pdf_reader` (`e8737cb`, the gate's pass-to-fail rule). → **S1** |
| Setup guide | Done | Root [README.md](../README.md) "The core flow in five commands" and "Running the App Locally"; [docker/vps/README.md](../docker/vps/README.md) for deployment. |
| Deployed link | Done | Root README, "Deployed:" line, with /health and /ready links. |
| Environment variables documented | Done (S3) | Fixed 2026-09-23. Before:  The README table is close, but [.env.example](../.env.example) is missing `OPENAI_MODEL`, `COPILOT_SIDECAR_URL`, `COPILOT_PREWARM_ENABLED`, `COPILOT_EVAL_ENDPOINTS` and the two price overrides; it lists `LANGFUSE_BASE_URL` where the README lists `LANGFUSE_HOST` (the code reads both, `LANGFUSE_HOST` first, `Config.php:51`); the deploy-only keys (`DIGITALOCEAN_*`, `GITLAB_*`) have no explanation. → **S3** |

## 2. W2 architecture doc

> A ./W2_ARCHITECTURE.md file explaining the document ingestion flow, worker
> graph, RAG design, eval gate, risks, and tradeoffs.

| Part | Status | Evidence |
|---|---|---|
| File at `./W2_ARCHITECTURE.md` | Done (S4) | [../W2_ARCHITECTURE.md](../W2_ARCHITECTURE.md) is the full document since 2026-09-23 (it used to be a pointer to this folder); the folder copy is now the pointer. |
| Ingestion flow | Done | "Ingestion: from an upload to a cited fact". |
| Worker graph | Done | "Agents: how many, and how they cooperate". |
| RAG design | Done | "Retrieval and guideline evidence", "Reranker". |
| Eval gate | Done | "The gate". |
| Risks and tradeoffs | Done | "Risks and trade-offs". |
| Accurate today | Done (S4) | Rerank shown as live, observability as covering every request type, the status line names the undeployed Phase 11 (core task T3.1). |

## 3. Key metrics doc

> A document listing your metrics and justifying them. Explain how these
> metrics prove your product is successful.

| Part | Status | Evidence |
|---|---|---|
| At `./KEY_METRICS.md` | Done | [KEY_METRICS.md](../KEY_METRICS.md), eleven metrics; 8-11 are Week 2 (anchored-field rate, retrieval hit rate, routing accuracy, gate pass rate). |
| Rationale per metric | Done | Each has a definition, "why", source, baseline and threshold. |
| How the metrics prove success | Done | The opening ties them to the physician's promise (trustworthy, fast enough, used); "Why not other metrics" closes it. |
| Numbers current | Refresh at final | Week 2 baselines came from 2026-09-22/23 runs; re-check after the final deploy. → **S10** |

## 4. Schemas

> Pydantic/Zod schemas for lab_pdf and intake_form, including source citation
> fields and validation tests.

| Part | Status | Evidence |
|---|---|---|
| Pydantic schemas for both types | Done | `LabReport` / `LabResult` and `IntakeForm` in [schemas.py](../interface/modules/custom_modules/oe-module-clinical-copilot/sidecar/copilot_sidecar/schemas.py); matching JSON Schemas in [contracts/](../interface/modules/custom_modules/oe-module-clinical-copilot/contracts/README.md). |
| Source citation fields | Done | Every lab result and intake item carries a citation (`source_type, source_id, page_or_section, field_or_chunk_id, quote_or_value`, plus `bbox`, `row_bbox`, `anchored`). |
| Validation tests | Done | `sidecar/tests/test_contracts.py` (Pydantic and JSON Schema accept and reject the same shared examples), `ContractExamplesTest.php`, `SidecarClientTest.php`; eval rubric `schema_valid`. |
| Strict typing | Tracked elsewhere | Pydantic coerces `"1"` to an int; the PHP-side JSON Schema check catches it end to end. Core task T2.1. |

## 5. Eval dataset

> 50 synthetic/demo cases with expected behavior, boolean rubrics, judge
> configuration, and results.

| Part | Status | Evidence |
|---|---|---|
| 50 synthetic/demo cases | Done | 70 cases in [tests/evals/cases/](../tests/evals/cases/), 54 deterministic and 16 live. All synthetic fixtures and seed patients. |
| Expected behavior per case | Done | Each case has `expect`, `failure_mode` and `guards`; the table in [tests/evals/README.md](../tests/evals/README.md#cases-and-the-failure-mode-each-guards) lists them. |
| Boolean rubrics | Done | Eight pass/fail rubrics, including the five the PRD names; thresholds in `gate.php`. |
| Judge configuration | Done (S5) | [EVAL_DATASET.md](EVAL_DATASET.md) §3: no LLM judge; the code check and pass rule behind each rubric; the models under test. |
| Results | Done (S5) | [EVAL_DATASET.md](EVAL_DATASET.md) §4: deterministic 54/54, live 16/16, per rubric; files [results.json](../tests/evals/results.json) and `baseline-live.json`. |

## 6. CI evidence

> Git Hook or equivalent that runs the eval suite and blocks regressions.

| Part | Status | Evidence |
|---|---|---|
| Hook runs the eval suite | Done | Pre-push hook installed by `tests/evals/install-hooks.sh`; runs `gate.sh` (sidecar pytest, isolated PHPUnit, the deterministic cases, `gate.php`). |
| Blocks regressions | Done | Any pass-to-fail flip on a deterministic case fails the push (`e8737cb`); below-threshold fails too. `install-hooks.sh --self-test` proves it. |
| Proof | Done | [EVAL_GATE.md](../EVAL_GATE.md) §5, [eval-gate-proof/push-refused.png](eval-gate-proof/push-refused.png) and `.log`; branch `gate-proof` on GitLab holds the regression and its revert. GitLab CI is refused for student projects (403); the instructors accept a hook plus proof. |
| Hard gate: graders inject a regression | Done | T6.1 closed the gap where a single broken case passed. Re-run the self-test after the final deploy. → **S10** |

## 7. Demo video

> 3-5 minutes showing document upload, extraction, evidence retrieval,
> citations, eval results, and observability.

| Part | Status | Evidence |
|---|---|---|
| Video recorded | User | Not recorded (DESIGN.md 8.4). Due with the early submission tonight. → **S2** |
| Shot list | Done | [DEMO_SCRIPT.md](DEMO_SCRIPT.md): a timed script covering the six things the PRD names, on synthetic patients, against the deployed app. → **S2** |
| HIPAA | Watch | Record only seed/synthetic patients; no real names, no `.env`, no API keys on screen, no Langfuse page showing keys. |

## 8. Cost and latency report

> Actual dev spend, projected production cost, p50/p95 latency, and
> bottleneck analysis.

| Part | Status | Evidence |
|---|---|---|
| Actual dev spend | Done | [COST_AND_LATENCY.md](../COST_AND_LATENCY.md) §3. |
| Projected production cost | Not done | The file says the per-tier projection is "Thursday's addition" (DESIGN.md 9.2). This is also the open Week 1 grader note (TODOS.md). → **S6** |
| p50/p95 latency | Partly done | Briefing and follow-up have p50/p95 per step. Extraction has single timings only; the load runs in [BASELINES.md](BASELINES.md) have extraction p50/p95 that are not carried over. The expanded briefing (guideline section, critic) is not measured in it. → **S6** |
| Bottleneck analysis | Done | §2, five bottlenecks. |

## 9. Deployed application

> Publicly accessible deployed app with the Week 2 core flow working.

| Part | Status | Evidence |
|---|---|---|
| Publicly accessible | Done | https://146-190-139-37.sslip.io, `/health` returns `ok` (checked 2026-09-23 20:26 CT). |
| Week 2 core flow working | Done for the 2026-09-22 build | Both Bruno collections passed against the droplet (Week 2 17/17, Week 1 19/19, `api-collection/results-deployed.json`). |
| Running the current code | Not done | The droplet runs `Prompt::VERSION 2026-09-22.5`. The expanded briefing (guideline section, critic, vitals, ranges), the click-to-source demo docs and the gate fixes are not deployed. The sidecar image must be rebuilt. → **S1** |

## 10. Technical interview (early submission only)

> Schedule an interview with a Gauntlet staff member. Be prepared to talk about
> your project, tradeoffs, architecture, etc.

| Part | Status | Evidence |
|---|---|---|
| Scheduled | User | → **S8** |
| Prepared | Not done | A one-page brief: the decisions and why, the trade-offs, the known weaknesses and what you would change. → **S8** |

## 11. Social post (final submission only)

> Share on X or LinkedIn: describe the project, show the agent, tag @GauntletAI.

| Part | Status | Evidence |
|---|---|---|
| Draft | Not done | → **S9** |
| Posted | User | With a screenshot or clip of synthetic data only. |

## 12. AI interview (final submission only)

> You will receive an email after submission to record your interview.

Nothing to prepare in the repo. The PRD schedule says an AI interview follows
**each** submission within 24 hours, so watch for the email after tonight's
submission too. The S8 brief doubles as preparation.

---

## Hard gates from the rest of the PRD

### H1. README separates Week 1 and Week 2; graders run Week 2 without guessing

> Your README must clearly separate Week 1 baseline behavior from Week 2
> multimodal behavior. Graders should be able to run the core Week 2 flow
> without guessing which branch, environment variable, or service is required.

| Part | Status | Evidence |
|---|---|---|
| Week 1 vs Week 2 separated | Done | Root README side-by-side table and separate doc folders. |
| Which branch | Done (S3) | README "Branch:" line names `pdf_reader`. |
| Which env vars | Done (S3) | See row 1 above. |
| Which service | Done | The README names the sidecar and the five-command flow brings it up. |
| Counts current | Done (S3) | README says 70 cases (16 live). Re-check at S10. |

### H2. Week 1 debt documented and resolved

> Technical debt from Week 1 should be documented and resolved before adding
> new surface area.

| Part | Status | Evidence |
|---|---|---|
| Documented | Done | [TODOS.md](../TODOS.md) "Week 1 grader feedback". |
| Cost analysis per tier | Not done | → **S6** |
| USERS.md personas and use cases | Not done | Week 2 added a front-desk uploader and two new use cases (lab PDF before the visit, intake contradicts the chart) that USERS.md does not describe. → **S7** |

### H3. HIPAA-minded development

| Part | Status | Evidence |
|---|---|---|
| Synthetic data only | Done | Seed patients and generated fixtures ([DOCUMENT_SOURCES.md](DOCUMENT_SOURCES.md)). |
| No raw PHI in logs | Done | Sidecar runtime allowlist; PHI eval cases; PHP-side runtime allowlist is core task T7.3. |
| Screenshots and video treated as sensitive | Watch | Applies to S2 and S9. |

### Optional extensions the PRD lists under Core Deliverables

Critic agent: built (applicability critic, cases 58-64). Click-to-source with
document preview: built. Third document type, lab trend chart, contextual
retrieval improvements: not built and not planned before the final; say so in
the interview rather than start them.

---

## Task list

Ordered by deadline, then by risk to grading. Each task is closed only after
it is verified.

**Tonight (early submission, 23:59 Central)**

- [ ] **S1 Deploy the current `pdf_reader` and re-verify.** Push to GitLab (through the gate), run `docker/vps/deploy.sh` (rebuilds the sidecar image), confirm `/ready`, the prompt version and both Bruno collections against the droplet; update `results-deployed.json`. Note: `deploy.sh` rsyncs the sidecar from the **working tree**, so uncommitted sidecar edits in progress would ship too.
- [ ] **S2 Demo video.** Shot list done: [DEMO_SCRIPT.md](DEMO_SCRIPT.md). Recording is the user's. Write a timed shot list (upload, extraction with an unverified value, click-to-source overlay, guideline evidence with citations, routing drawer, eval gate refusing a regression, Langfuse trace). The user records it.
- [x] **S3 README and `.env.example`.** Done 2026-09-23: README names the branch, counts 70 cases, links EVAL_DATASET.md, notes the `LANGFUSE_BASE_URL` fallback; `.env.example` lists every variable the code reads, grouped, one comment each, and marks the tooling-only keys. Name the branch; make `.env.example` list every variable the code reads, with one line each; align `LANGFUSE_HOST`; explain the deploy-only keys; refresh counts.
- [x] **S4 `./W2_ARCHITECTURE.md` is the document, not a pointer**, and says what is true today (rerank live). Done 2026-09-23; links rewritten and checked.
- [x] **S5 Eval dataset page**, done 2026-09-23: [EVAL_DATASET.md](EVAL_DATASET.md).: judge configuration (no LLM judge; which code check decides each rubric; thresholds; live vs recorded), results per rubric, where every piece lives.

**Before the technical interview (Thu/Fri)**

- [ ] **S8 Interview brief**: decisions, trade-offs, weaknesses, what you would change, numbers to quote.

**Before the final (Sun 12:00)**

- [ ] **S6 Cost and latency report**: projected production cost per tier for the Week 2 agent (extraction, embeddings, rerank, critic), extraction p50/p95 from the load runs, the expanded briefing's cost and latency.
- [ ] **S7 USERS.md**: full PCP persona, front-desk uploader persona, UC4 and UC5 with click paths.
- [ ] **S9 Social post draft.**
- [ ] **S10 Final pass**: final deploy, both collections and the gate self-test again, refresh numbers in KEY_METRICS and README, check every link in the submission docs resolves.
