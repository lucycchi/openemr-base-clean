# Submission requirements: status and tasks

The **Submission Requirements** table from the Week 2 PRD (page 6), plus the
other hard gates the PRD sets for what is handed in (pages 2-3), checked
against the repo on `pdf_reader` on 2026-09-23 at 20:30 Central. Each row
quotes the PRD, gives its status, and links the evidence. Every gap becomes a
task in the [task list](#task-list).

The early submission scored **91/100**; the breakdown, what each comment
means and the G tasks that follow from it are in
[Early submission grade](#early-submission-grade-received-2026-09-24).

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
| 1 | GitLab repository | Done | none | Early |
| 2 | W2 architecture doc (`./W2_ARCHITECTURE.md`) | Done | none | Early |
| 3 | Key metrics doc (`./KEY_METRICS.md`) | Done; metrics 6 and 7 built 2026-09-24 | S10 | Early |
| 4 | Schemas | Done; Gate 3 scored 9/15 | G2, G8 | Early |
| 5 | Eval dataset | Done | none | Early |
| 6 | CI evidence | Done: hook in the codebase, and a blocking GitLab CI merge-request job (pipeline #28015 green, MR !1 blocked by a planted regression) | S10 | Early |
| 7 | Demo video | Done for early (graded 4/5); re-record for final | G9 | Early and final |
| 8 | Cost and latency report | Done | none | Final |
| 9 | Deployed application | Done | none | Early |
| 10 | Technical interview | User | S8 | Thu/Fri |
| 11 | Social post | User | S9 | Final |
| 12 | AI interview | User | none | after each submission |
| H1 | README separates Week 1 from Week 2; grader can run Week 2 without guessing | Done | none | Early |
| H2 | Week 1 debt documented and resolved | Done | none | Final |
| H3 | HIPAA-minded: synthetic data only, no raw PHI in logs, screenshots and video treated as sensitive | Done (watch the video) | S2 | Early |

---

## 1. GitLab repository

> Week 1 fork with Week 2 changes, setup guide, deployed link, and clear
> environment-variable documentation.

| Part | Status | Evidence |
|---|---|---|
| Week 1 fork with Week 2 changes | Done | `ssh://git@labs.gauntletai.com:22022/lucychi/openemr.git`. GitLab's default branch is `pdf_reader`, so graders land on the Week 2 code. |
| Up to date on GitLab | Done (S1) | Pushed through the gate on 2026-09-23 21:00 CT (`gitlab/pdf_reader` at `b6f4c60`, GATE: PASS, 54/54). Later commits go out with the next push. |
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
| Strict typing | Done | Every contract model is `ConfigDict(extra="forbid", strict=True)` since `0667ee0` (core task T2.1): `"1"` is not an int, `"true"` is not a bool. The three date fields take ISO date strings. `test_models_refuse_values_of_the_wrong_type` pins it. (Until 2026-09-24 this row still described the old coercion gap; see G1.) |

## 5. Eval dataset

> 50 synthetic/demo cases with expected behavior, boolean rubrics, judge
> configuration, and results.

| Part | Status | Evidence |
|---|---|---|
| 50 synthetic/demo cases | Done | 72 cases in [tests/evals/cases/](../tests/evals/cases/), 56 deterministic and 16 live. All synthetic fixtures and seed patients. |
| Expected behavior per case | Done | Each case has `expect`, `failure_mode` and `guards`; the table in [tests/evals/README.md](../tests/evals/README.md#cases-and-the-failure-mode-each-guards) lists them. |
| Boolean rubrics | Done | Eight pass/fail rubrics, including the five the PRD names; thresholds in `gate.php`. |
| Judge configuration | Done (S5) | [EVAL_DATASET.md](EVAL_DATASET.md) §3: no LLM judge; the code check and pass rule behind each rubric; the models under test. |
| Results | Done (S5) | [EVAL_DATASET.md](EVAL_DATASET.md) §4: deterministic 56/56, live 16/16, per rubric; files [results.json](../tests/evals/results.json) and `baseline-live.json`. |

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
| Video recorded | Done for early | Submitted with the early submission 2026-09-23; graded 4/5 (Gate 8). Re-record for the final. → **G9** |
| Shot list | Done | [DEMO_SCRIPT.md](DEMO_SCRIPT.md): a timed script covering the six things the PRD names, on synthetic patients, against the deployed app. → **S2** |
| HIPAA | Watch | Record only seed/synthetic patients; no real names, no `.env`, no API keys on screen, no Langfuse page showing keys. |

## 8. Cost and latency report

> Actual dev spend, projected production cost, p50/p95 latency, and
> bottleneck analysis.

| Part | Status | Evidence |
|---|---|---|
| Actual dev spend | Done | [COST_AND_LATENCY.md](../COST_AND_LATENCY.md) §3. |
| Projected production cost | Done (S6) | §4: usage model, three tiers (1 clinic, 50, 500 physicians), the architectural change each tier forces. Finding: Cohere reranks are 86 % of the per-encounter cost as built; precomputing brief-mode reranks cuts the model line by three quarters. |
| p50/p95 latency | Done (S6) | Per step for briefing and follow-up; extraction p50/p95 under load from [BASELINES.md](BASELINES.md); the expanded briefing's cold and cache-hit p50/p95 (dev stack; droplet after the deploy). |
| Bottleneck analysis | Done | §2, five bottlenecks. |

## 9. Deployed application

> Publicly accessible deployed app with the Week 2 core flow working.

| Part | Status | Evidence |
|---|---|---|
| Publicly accessible | Done | https://146-190-139-37.sslip.io, `/health` returns `ok` (checked 2026-09-23 20:26 CT). |
| Week 2 core flow working | Done for the 2026-09-22 build | Both Bruno collections passed against the droplet (Week 2 17/17, Week 1 19/19, `api-collection/results-deployed.json`). |
| Running the current code | Done (S1) | Redeployed `3f9cc74` on 2026-09-23 22:15 CT (all core agent tasks; `/ready` ok; Week 2 collection 17/17, 41/41). First deployed `b6f4c60` on 2026-09-23 21:00 CT with the sidecar rebuilt: `/ready` reports all five dependencies ok, `Prompt::VERSION 2026-09-23.1`. Week 2 collection 17/17 requests, 41/41 assertions ([results-deployed.json](api-collection/results-deployed.json)). Week 1 collection 18/19: request 17 (alert webhook) returned 401 because the run had no `alertToken`, not a regression; re-run it with the droplet's `ALERT_WEBHOOK_SECRET`. |

## 10. Technical interview (early submission only)

> Schedule an interview with a Gauntlet staff member. Be prepared to talk about
> your project, tradeoffs, architecture, etc.

| Part | Status | Evidence |
|---|---|---|
| Scheduled | User | → **S8** |
| Prepared | Done (S8) | [INTERVIEW_BRIEF.md](INTERVIEW_BRIEF.md): decisions and what each beat, weak points, numbers, coding workflow. Scheduling is the user's. |

## 11. Social post (final submission only)

> Share on X or LinkedIn: describe the project, show the agent, tag @GauntletAI.

| Part | Status | Evidence |
|---|---|---|
| Draft | Done (S9) | [SOCIAL_POST.md](SOCIAL_POST.md): an X version (252 characters) and a LinkedIn version. |
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
| Counts current | Done (S3) | README says 72 cases (16 live); rechecked in the 2026-09-25 checkpoint. Re-check at S10. |

### H2. Week 1 debt documented and resolved

> Technical debt from Week 1 should be documented and resolved before adding
> new surface area.

| Part | Status | Evidence |
|---|---|---|
| Documented | Done | [TODOS.md](../TODOS.md) "Week 1 grader feedback". |
| Cost analysis per tier | Done (S6) | [COST_AND_LATENCY.md](../COST_AND_LATENCY.md) §4. |
| USERS.md personas and use cases | Done (S7) | [USERS.md](../USERS.md): the PCP's day, the front-desk uploader persona, UC4 and UC5 with click paths. |

### H3. HIPAA-minded development

| Part | Status | Evidence |
|---|---|---|
| Synthetic data only | Done | Seed patients and generated fixtures ([DOCUMENT_SOURCES.md](DOCUMENT_SOURCES.md)). |
| No raw PHI in logs | Done | Runtime log-field allowlists in both the sidecar and PHP (`Ops/LogFields.php` via `CorrelatedLogger`, core task T7.3, `e3b7dd6`); the PHI eval cases check the same list. |
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

- [x] **S1 Deploy the current `pdf_reader` and re-verify.** Done 2026-09-23 21:00 CT: `b6f4c60` deployed, `/ready` ok, Week 2 collection 17/17; Week 1 18/19 (alert webhook needs its token in the run). Redeployed 22:15 CT at `3f9cc74` with every core agent task (T1-T7) included: `/ready` ok, Week 2 collection 17/17, 41/41. Push to GitLab (through the gate), run `docker/vps/deploy.sh` (rebuilds the sidecar image), confirm `/ready`, the prompt version and both Bruno collections against the droplet; update `results-deployed.json`. Note: `deploy.sh` rsyncs the sidecar from the **working tree**, so uncommitted sidecar edits in progress would ship too.
- [x] **S2 Demo video.** Recorded and submitted 2026-09-23 (graded 4/5; the final re-record is G9). Shot list done: [DEMO_SCRIPT.md](DEMO_SCRIPT.md). Recording is the user's. Write a timed shot list (upload, extraction with an unverified value, click-to-source overlay, guideline evidence with citations, routing drawer, eval gate refusing a regression, Langfuse trace). The user records it.
- [x] **S3 README and `.env.example`.** Done 2026-09-23: README names the branch, counts 70 cases, links EVAL_DATASET.md, notes the `LANGFUSE_BASE_URL` fallback; `.env.example` lists every variable the code reads, grouped, one comment each, and marks the tooling-only keys. Name the branch; make `.env.example` list every variable the code reads, with one line each; align `LANGFUSE_HOST`; explain the deploy-only keys; refresh counts.
- [x] **S4 `./W2_ARCHITECTURE.md` is the document, not a pointer**, and says what is true today (rerank live). Done 2026-09-23; links rewritten and checked.
- [x] **S5 Eval dataset page**, done 2026-09-23: [EVAL_DATASET.md](EVAL_DATASET.md).: judge configuration (no LLM judge; which code check decides each rubric; thresholds; live vs recorded), results per rubric, where every piece lives.

**Before the technical interview (Thu/Fri)**

- [x] **S8 Interview brief**, done 2026-09-23 ([INTERVIEW_BRIEF.md](INTERVIEW_BRIEF.md)); scheduling is the user's: decisions, trade-offs, weaknesses, what you would change, numbers to quote.

**Before the final (Sun 12:00)**

- [x] **S6 Cost and latency report**, done 2026-09-23: projected production cost per tier for the Week 2 agent (extraction, embeddings, rerank, critic), extraction p50/p95 from the load runs, the expanded briefing's cost and latency.
- [x] **S7 USERS.md**, done 2026-09-23: full PCP persona, front-desk uploader persona, UC4 and UC5 with click paths.
- [x] **S9 Social post draft**, done 2026-09-23 ([SOCIAL_POST.md](SOCIAL_POST.md)). Posting is the user's.
- [ ] **S10 Final pass**: final deploy, both collections and the gate self-test again, refresh numbers in KEY_METRICS and README, check every link in the submission docs resolves.

---

## Early submission grade (received 2026-09-24)

Submitted Wed 2026-09-23 21:24, graded the same day by the Gauntlet
reviewer. Screenshot: [early_submission_grade.png](early_submission_grade.png).

**Score: 91 / 100.**

| Rubric gate | Score | Lost | What the reviewer said about it |
|---|---|---|---|
| 1 Deployed application and Week 2 setup | 10/10 | 0 | nothing |
| 2 Document ingestion and extraction | 15/15 | 0 | praised: strict document contracts, anchored extraction |
| 3 Strict schemas and citation contract | 9/15 | **6** | no comment (see interpretation) |
| 4 Eval framework and PR-blocking CI gate | 25/25 | 0 | praised: 70 cases, planted-regression evidence, the pre-push gate refuses a regression |
| 5 Supervisor, workers and evidence retrieval | 12/12 | 0 | praised: supervisor routing, hybrid retrieval |
| 6 Observability | 8/10 | **2** | "continue building out the production adoption and physician feedback metrics that are currently documented as planned" |
| 7 W2_ARCHITECTURE.md, Week 1 debt and ... | 8/8 | 0 | nothing |
| 8 Demo video | 4/5 | **1** | no comment |

### The reviewer's comment

> Lucy, this is a strong Week 2 submission. You have 70 eval cases, strict
> document contracts, anchored extraction, citation verification, supervisor
> routing, hybrid retrieval, PHI safe tracing, cost and latency
> instrumentation, and extensive automated testing. The planted regression
> evidence is also good and demonstrates that your local pre push gate
> actually refuses a regression.
>
> The main area to address going forward is Gate 5. Your GitLab pipeline
> currently performs linting and deployment, but the evaluation gate itself
> runs through a local pre push hook rather than as a blocking merge request
> CI job. Move the golden set and regression gate into GitLab CI so a failed
> evaluation automatically prevents a merge. I would also continue building
> out the production adoption and physician feedback metrics that are
> currently documented as planned.

### What it means

**1. The CI comment cost nothing now, but it is the reviewer's top ask for
the final.** The eval gate scored 25/25. The comment says "Gate 5", but in
the rubric Gate 5 is the supervisor (12/12, full marks) and the CI gate is
Gate 4. Read "Gate 5" as a numbering slip meaning the CI gate. The reviewer
accepted the hook for the early submission and is asking for a real merge
request job before the final. Expect the final to be graded harder on this.

**2. The reviewer believes our GitLab pipeline runs. It never has.** The
reviewer read [.gitlab-ci.yml](../.gitlab-ci.yml) (`php-lint`, `compose-lint`,
`deploy-vps`) and concluded it "currently performs linting and deployment".
In fact GitLab has refused every pipeline for this project: `POST
/projects/1993/pipeline` returns 403 even as Owner, `shared_runners_enabled`
is false, no runners exist, zero pipelines have ever run (checked
2026-09-23; the instructors then said a hook plus proof was acceptable). The
file also still deploys from the old `audit` branch, not `pdf_reader`. So:

- Moving the gate into `.gitlab-ci.yml` is easy, but it will not *run* until
  someone with instance or group rights turns CI on for the project. That
  part is outside our control and has to be asked for (G3).
- Whatever happens, the repo should stop implying the pipeline runs. A
  grader who reads the YAML and trusts it will be surprised when a merge
  request shows no pipeline (G5).

**3. Gate 3 (-6) is the biggest loss and has no comment.** Likely causes,
most likely first:

- *Our own document admitted a strict-schema gap.* Row 4 of this file said
  "Pydantic coerces `"1"` to an int" and pointed at T2.1 as open. Strict
  typing landed in `0667ee0` (19:10 by the commit clock), after `b6f4c60`
  was pushed and deployed, and this row was never updated. A grader scoring
  "strict schemas" who read this file, or who looked at the schemas at
  `b6f4c60`, saw non-strict models. Row 4 is fixed now (G1).
- *The date fields are still loose.* `collection_date`, `reported_date` and
  `form_date` accept any string that parses as an ISO date; the JSON Schemas
  may not pin a `format` or pattern. Small, but it is exactly what "strict"
  graders poke at.
- *Citation contract coverage.* The PRD requires every clinical claim in the
  final response to carry `{source_type, source_id, page_or_section,
  field_or_chunk_id, quote_or_value}`. `a8e7f1f` made that true for guideline
  passages too, and it was in the graded build, but a grader who found one
  claim without the five fields (a Week 1 structured-record fact, a
  "no data" statement) would dock here.

Asking the reviewer which of these it was is cheaper than guessing (G2).

**4. Gate 6 (-2) is the two "planned, not yet built" metrics.**
[KEY_METRICS.md](../KEY_METRICS.md) metric 6 (physician rating of the
summary) and metric 7 (chat adoption per encounter) are both marked
"planned, not yet built". Everything else in observability (tool sequence,
per-step latency, tokens, cost, retrieval hits, extraction confidence, eval
outcome, PHI-safe logs) got credit. Building those two closes the gap
(G6, G7).

**5. Gate 8 (-1) has no comment.** The PRD asks for 3-5 minutes showing
upload, extraction, evidence retrieval, citations, eval results and
observability. The usual one-point losses are running over or under length,
or one of the six being shown only in passing (eval results and the Langfuse
trace are the easiest to rush). Re-record for the final (G9).

### Where the remaining points are

| Gate | Available | Fix | Size |
|---|---|---|---|
| 3 Strict schemas and citations | 6 | G1 (done), G2, G8 | small to medium |
| 6 Observability | 2 | G6, G7 | medium (new table, UI control, a metric query) |
| 8 Demo video | 1 | G9 | one recording session |
| 4 CI gate | 0 now, at risk at final | G3, G4, G5 | small in the repo; the blocker is GitLab permissions |

### Grade task list

Closed only after it is verified, like the S tasks.

**Before the technical interview (Thu/Fri)**

- [x] **G1 Correct the stale strict-typing row** in section 4 of this file.
  Done 2026-09-24: row 4 now describes `0667ee0` and the pinning test.
- [ ] **G2 Ask the reviewer what cost the Gate 3 and Gate 8 points.** (User.)
  One short message: "Could you tell me what I lost the six Gate 3 points
  on, strict typing, the date fields, or a claim without the full citation?
  And the one point on the video?" The answer decides whether G8 is needed.
- [x] **G3 Ask for GitLab CI to be turned on.** Asked 2026-09-24. Tom
  Tarpey's answer (verbal): use a hook inside the codebase, run locally or on a runner
  on DigitalOcean. Pipelines stay refused. Original plan: for project 1993 (or for a
  project runner to be allowed). (User.) Group Maintainers who can act:
  zacsmith, tomtarpey. Say what you need exactly: pipelines allowed on
  `lucychi/openemr`, and either shared runners or permission to register a
  project runner on the droplet. Quote the reviewer's request so it is clear
  why.
- [x] **Interview prep:** done 2026-09-24, INTERVIEW_BRIEF.md weak points. Add the CI answer to
  [INTERVIEW_BRIEF.md](INTERVIEW_BRIEF.md) under weak points: what was tried
  (pipeline API 403 as Owner, no runners, group project blocked by branch
  protection), what the hook does, the kill-matrix result (19/19 planted
  regressions refused), and the plan (G4, G5). Staff are likely to ask.

**Before the final (Sun 12:00)**

- [x] **G4 Add the eval gate to `.gitlab-ci.yml` as a blocking merge request
  job.** Done 2026-09-24 (`f0a5f4e`, pushed): `eval-gate` runs
  `tests/evals/ci-gate.sh` on runner 257 on the droplet; `pdf_reader`
  protected via the API ("Pipelines must succeed" was turned on, then off
  again once pipelines were confirmed unavailable). Fresh-clone dry
  runs: clean PASS, planted M12 FAIL. Running on GitLab since 2026-09-24:
  pipelines are started by a project bot token (personal accounts get 403);
  #28015 passed, MR !1 with planted M12 failed `eval-gate` and is blocked
  (`ci_must_pass`). Original plan: A `eval-gate` job in the `check` stage that runs the same thing the
  hook runs (`tests/evals/gate.sh`: sidecar pytest, isolated PHPUnit, the 54
  deterministic cases, `gate.php` thresholds), on `merge_request_event` and
  on pushes to `pdf_reader`. Check first what `gate.sh` needs from the host
  (PHP, Python, vendor/, docker) and pick an image that has it. Also: point
  `DEPLOY_BRANCH` at `pdf_reader`, make `deploy-vps` depend on `eval-gate`,
  and turn on "Pipelines must succeed" in the project's merge request
  settings. If G3 comes through, open a merge request with a planted
  regression and screenshot the blocked merge, like the hook proof.
- [x] **G5 Say plainly what runs and what does not.** Done 2026-09-24 in
  EVAL_GATE.md's opening and the interview brief. Original plan: At the top of
  [EVAL_GATE.md](../EVAL_GATE.md) and in the root README: the pre-push hook
  is the gate that runs today; the `.gitlab-ci.yml` jobs (including the new
  `eval-gate`) are ready but GitLab refuses pipelines for this project (403,
  no runners), with the date checked. If G3 lands, replace this with the
  pipeline link.
- [x] **G6 Build metric 6, the physician rating.** Done 2026-09-24: thumbs
  up/down and optional comment under the AI summary (`action=rate`, module
  0.1.4, `copilot_briefing_rating`), `physician_rating` Langfuse score,
  `copilot:ratings` report; the comment stays in the EHR. Deployed
  2026-09-24 (`a1c1385`) and checked in a browser on the droplet. Original plan: Thumbs up/down (and an
  optional comment) on each briefing, stored in `copilot_briefing_rating`
  keyed by briefing, `Prompt::VERSION` and model; comment text never goes to
  Langfuse or the log (PHI). Add the rating as a Langfuse score so it sits
  next to the trace. New table means the Module Manager upgrade or the
  by-hand `mariadb` step on the droplet; new log keys go in
  `LogFields::ALLOWED`. Update KEY_METRICS.md from "planned" to live, with
  the first numbers.
- [x] **G7 Build metric 7, chat adoption per encounter.** Done 2026-09-24:
  every Co-Pilot audit row, log line and trace names the open encounter
  (`encounter_id`); `php bin/console copilot:adoption --days=7 [--json]`
  reports per physician per day and per week the encounters, how many the
  chat was used on, the share and mean questions per used encounter; metric 7
  is marked built in KEY_METRICS.md. Verified: AdoptionReportTest (6),
  DbAdoptionCountsTest, ChatControllerBriefTest (the audit row names the
  encounter), gate.sh PASS, PHPStan clean. Original plan: The numerator
  already exists in the audit rows; write the query (encounters with at
  least one chat turn / encounters), expose it where the other metrics are
  read ([DASHBOARD.md](DASHBOARD.md)), and mark it live in KEY_METRICS.md.
- [x] **G8 Tighten the date fields and audit citation coverage.** Done
  2026-09-24, verified: isolated PHPUnit 429 tests green, PHPStan clean on
  the full codebase, `gate.sh` PASS (56/56, every rubric 100 %): one ISO date rule on all three validators;
  the sentence contract requires a citation; `PanelPayload` drops a claim
  whose ids do not all resolve to a citation; Pydantic's handoff `to`
  matches the contract. Details in CORE_AGENT_REQUIREMENTS.md sections 2
  and 5. Original plan: Pin the three date fields to
  a date type or an ISO pattern in both Pydantic and the JSON Schemas; add an
  eval case that rejects a malformed date. Walk one briefing and one
  follow-up answer and confirm every clinical claim, including Week 1
  structured-record facts, carries all five citation fields.
- [ ] **G9 Re-record the demo video** from [DEMO_SCRIPT.md](DEMO_SCRIPT.md),
  timed to 3-5 minutes, with each of the six PRD items on screen long
  enough to read: upload, extraction (with an unverified value), evidence
  retrieval, click-to-source citation, eval results (the gate refusing a
  regression, and the GitLab pipeline if G3 lands), observability (a
  Langfuse trace, plus the new rating from G6). Synthetic patients only.
- [ ] **S10** (above) now also covers: re-verify G4-G7 on the final deploy.
