# Clinical Co-Pilot, Week 2 documents

Everything written for the Week 2 build (multimodal evidence agent: lab PDF
and intake-form ingestion, hybrid guideline retrieval, supervisor + workers,
eval gate). Week 1 documents stay in [../clinical_copilot_week1/](../clinical_copilot_week1/).

| Document | What it is |
|---|---|
| [W2_ARCHITECTURE.md](W2_ARCHITECTURE.md) | The architecture: extraction-stack spike and decision, tools by step, how the agents cooperate, the review/rating loop, findings log. Required by the Week 2 brief as `./W2_ARCHITECTURE.md` (a pointer file sits at the repo root). |
| [DESIGN.md](DESIGN.md) | The approved design record and the phased TODO list, with decisions and the review history (three Claude passes, two Codex passes). Ticked as phases complete. |
| [DOCUMENT_SOURCES.md](DOCUMENT_SOURCES.md) | Candidate sources for sample lab reports and intake forms, the no-real-patient rule, vetting status, and how a chosen source becomes a fixture. |
| [STATIC_ANALYSIS.md](STATIC_ANALYSIS.md) | PHPStan level 10 for the Co-Pilot code: where the 544 errors were, the five root causes and their fixes, the decisions (typed readers, honest parser types, spike scripts excluded), and how to keep the run at zero. |
| [api-collection/](api-collection/README.md) | Runnable Bruno collection for the Week 2 endpoints: attach → extract → cited facts, a guideline-evidence question, the CSRF and permission refusals, pre-warm status; bundled fixture; `local` and `vps` environments; verified 17/17. |
| [BASELINES.md](BASELINES.md) | Performance baselines of the Week 2 build on the droplet: two recorded runs (before and after the sidecar concurrency fix), latency/error, extraction and CPU/memory tables with a sidecar column, what the numbers mean, recommendations. |
| [ALERTS.md](ALERTS.md) | The three paging alerts re-specified for the Week 2 agent (what the sidecar changed in each), an extraction-latency rule, four watched rules, the Langfuse definitions, and the on-call runbook for every Week 2 failure mode. |
| [DASHBOARD.md](DASHBOARD.md) | The Week 2 dashboard: what the module now sends for the sidecar workers, per-call model usage, extraction outcomes, retrieval and the pre-warm queue; the five Week 2 scores; every widget field-by-field; decisions and trade-offs; how to verify. |
| [experiments/](experiments/answer-length-cap.md) | Measured decisions with their data and scripts. So far: the follow-up answer-length cap (uncapped vs 3, 6, 10 sentences; five timed calls each; six chosen). |
| [ENGINEERING_REQUIREMENTS.md](ENGINEERING_REQUIREMENTS.md) | The graded engineering requirements re-audited against the Week 2 code, one by one: how each is met, the decisions and trade-offs, how to verify it, what is open. Test design (1), correlation id (2), contracts (3), dashboards (4), the API collection (5), health/ready (6), alerts (7), baselines (8) and load tests (9): all nine. |

## Seeing click-to-source on the deployed instance

Two patients on https://146-190-139-37.sslip.io already have an extracted
lab PDF, so the source highlight can be checked without uploading
anything. Both were given the same synthetic 5-page lab report
(collected 2026-09-15, 20 results). All 20 results and both dates were
found on the page and carry a bounding box.

| pid | Patient | Document id | What it shows |
|---|---|---|---|
| 28 | Vince741 Collier206 | 1560 | The normal case: the report matches the chart, and every lab fact from it has a working source link. |
| 1 | Phil Belford | 1561 | The same labs plus a **Document does not match the chart** fact: the name on the report is Collier's. That fact's link reads "open document" and opens the PDF with no highlight, by design. |

1. Log in as `admin` (the password is supplied with the submission).
2. **Patient → Finder**, search for the name above and open the chart.
   Go to **Dashboard**. The Co-Pilot card is at the top.
3. In the card's fact list, lab facts read from the PDF (for example
   Hemoglobin A1c 8.8 %, LDL 141.6 mg/dL or Potassium 3.3 mmol/L) end
   with a **source p.N** link after their id badge.
4. Click the link. The viewer opens on that page with a blue box around
   the report row and a red box around the value, and scrolls to it. The
   results are spread across all 5 pages, so try one from a later page
   too (TSH, ALT, AST and Vitamin D are on page 5).
5. The **Uploaded documents** list under the facts has an **open** link
   that shows the whole PDF with no highlight.

Neither document has an unverified value, so the dashed
**unverified, open source** link does not appear on these charts. To see
it, upload a report whose values the anchor step cannot place, such as a
low-quality scan.

The source link sits on the fact in the list, not on the sentence badges
in the AI summary. Hovering a summary badge highlights the matching fact,
which carries the link.

## Documents kept next to what they describe

| Document | Why it stays there | What it covers |
|---|---|---|
| [../tests/evals/README.md](../tests/evals/README.md) | The gate installer and graders look beside the harness | The eval gate and its rule, the seven rubrics and thresholds, the case format with one example per mode, how graders test the gate, and the recorded transcript of the hook refusing a regression |
| [../tests/evals/gate.php](../tests/evals/gate.php), [gate.sh](../tests/evals/gate.sh), [install-hooks.sh](../tests/evals/install-hooks.sh) | Executable; header comments document the rule | The push gate, its container-aware wrapper, the hook installer with self-test |
| [../tests/evals/lib.php](../tests/evals/lib.php) | Beside the scripts that use it | Typed readers for decoded JSON (`str`, `int`, `map`, `lst`, `strings`, `jsonFile`), the harness-side counterpart of the module's `Row` class; why: STATIC_ANALYSIS.md |
| [../tests/evals/baseline.json](../tests/evals/baseline.json), [baseline-live.json](../tests/evals/baseline-live.json), [results.json](../tests/evals/results.json) | Data the gate reads | Per-case rubric verdicts the gate compares against; the latest full run |
| [../tests/evals/cases/](../tests/evals/cases/) | One JSON file per case | Cases 01-15 (Week 1), 16-52 (Week 2: anchor, extract, routing, retrieval, answer, PHI-in-logs, malformed input, absent values, regressions, boundaries, persistence-to-fact) and 53-68 (expanded briefing: guideline triggers, brief evidence, brief routing, critic verdicts recorded and live, narrated guideline sentence, plan-note injection and PHI), each with its `guards` category, failure mode and rubrics |
| [../tests/evals/fixtures/docs/](../tests/evals/fixtures/docs/) | Generated fixtures beside the cases that use them | Synthetic lab PDFs, their scans, `truth.json` and recorded `model.json` (regenerate with `python -m tools.generate_fixtures`) |
| [contracts/README.md](../interface/modules/custom_modules/oe-module-clinical-copilot/contracts/README.md) | Beside the schema files it indexes | Every JSON contract, including the Week 2 ones: citation, lab-report, intake-form, handoff, run.*, documents.*, the two LLM proposal contracts and the critic's `llm.critic.output`, `loinc_map.json`, and the expanded-briefing tables `reference_ranges.json`, `guideline_triggers.json`, `vital_thresholds.json`, and the shared `examples/` both test suites run |
| [sidecar/README.md](../interface/modules/custom_modules/oe-module-clinical-copilot/sidecar/README.md) | Beside the code; module docstrings carry the detail | What each file does, logging and the correlation id, how to rebuild and test: `parse.py` (PyMuPDF + tesseract), `anchor.py` (row-level anchoring rules), `llm.py`, `extractor.py`, `graph.py` (supervisor + three workers, brief mode), `retrieve.py` (hybrid retrieval, brief-mode batch on committed trigger vectors), `app.py`, `logging_setup.py` (allowlist + correlation id), `tools/`, `tests/` |
| [../docker/vps/README.md](../docker/vps/README.md), [deploy.sh](../docker/vps/deploy.sh) | Deployment lives with the compose files | How the droplet is deployed; `deploy.sh` now builds the sidecar and applies module schema upgrades |
| [BRIEFING_SCOPE_PROPOSAL.md](BRIEFING_SCOPE_PROPOSAL.md), [../docs/superpowers/plans/2026-09-23-briefing-scope-expansion.md](../docs/superpowers/plans/2026-09-23-briefing-scope-expansion.md) | The approved scope and its task plan | What the expanded briefing adds (ranges on every lab, normal and critical results, vitals, chart-state changes, the prior visit's plan, the guideline section and the critic), the decisions taken, and the twelve tasks with their verification gates |
| [../TODOS.md](../TODOS.md) | Repo-wide debt ledger | The two open items from the Week 1 grader feedback (tasks 9.1, 9.2 in DESIGN.md) and other deferred work |
| [../KEY_METRICS.md](../KEY_METRICS.md) | Required at the repo root by the brief | Eleven metrics with baselines and alerts; 8-11 are Week 2 (anchored-field rate, retrieval hit rate, routing accuracy, gate pass rate) |
| [../COST_AND_LATENCY.md](../COST_AND_LATENCY.md) | Required at the repo root by the brief | Latency per step (briefing, question, extraction), the five bottlenecks, actual Week 2 spend; the per-tier projection is Thursday's addition |
| [../README.md](../README.md) | Repo front page | Week 1 vs Week 2 side by side, the document index, the environment-variable table, the core flow in five commands |
| [../clinical_copilot_week1/](../clinical_copilot_week1/README.md) | Week 1 documents, unchanged | Baseline behaviour the brief asks to keep separate from Week 2; its [ENGINEERING_REQUIREMENTS.md](../clinical_copilot_week1/ENGINEERING_REQUIREMENTS.md) is the Week 1 audit the Week 2 one builds on |
