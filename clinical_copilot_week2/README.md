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

## Documents kept next to what they describe

| Document | Why it stays there | What it covers |
|---|---|---|
| [../tests/evals/README.md](../tests/evals/README.md) | The gate installer and graders look beside the harness | The eval gate and its rule, the seven rubrics and thresholds, the case format with one example per mode, how graders test the gate, and the recorded transcript of the hook refusing a regression |
| [../tests/evals/gate.php](../tests/evals/gate.php), [gate.sh](../tests/evals/gate.sh), [install-hooks.sh](../tests/evals/install-hooks.sh) | Executable; header comments document the rule | The push gate, its container-aware wrapper, the hook installer with self-test |
| [../tests/evals/lib.php](../tests/evals/lib.php) | Beside the scripts that use it | Typed readers for decoded JSON (`str`, `int`, `map`, `lst`, `strings`, `jsonFile`), the harness-side counterpart of the module's `Row` class; why: STATIC_ANALYSIS.md |
| [../tests/evals/baseline.json](../tests/evals/baseline.json), [baseline-live.json](../tests/evals/baseline-live.json), [results.json](../tests/evals/results.json) | Data the gate reads | Per-case rubric verdicts the gate compares against; the latest full run |
| [../tests/evals/cases/](../tests/evals/cases/) | One JSON file per case | Cases 01-15 (Week 1) and 16-52 (Week 2: anchor, extract, routing, retrieval, answer, PHI-in-logs, malformed input, absent values, regressions, boundaries, persistence-to-fact), each with its `guards` category, failure mode and rubrics |
| [../tests/evals/fixtures/docs/](../tests/evals/fixtures/docs/) | Generated fixtures beside the cases that use them | Synthetic lab PDFs, their scans, `truth.json` and recorded `model.json` (regenerate with `python -m tools.generate_fixtures`) |
| [contracts/README.md](../interface/modules/custom_modules/oe-module-clinical-copilot/contracts/README.md) | Beside the schema files it indexes | Every JSON contract, including the Week 2 ones: citation, lab-report, intake-form, handoff, run.*, documents.*, the two LLM proposal contracts, `loinc_map.json`, and the shared `examples/` both test suites run |
| [sidecar/README.md](../interface/modules/custom_modules/oe-module-clinical-copilot/sidecar/README.md) | Beside the code; module docstrings carry the detail | What each file does, logging and the correlation id, how to rebuild and test: `parse.py` (PyMuPDF + tesseract), `anchor.py` (row-level anchoring rules), `llm.py`, `extractor.py`, `graph.py` (supervisor + workers), `retrieve.py` (hybrid retrieval), `app.py`, `logging_setup.py` (allowlist + correlation id), `tools/`, `tests/` |
| [../docker/vps/README.md](../docker/vps/README.md), [deploy.sh](../docker/vps/deploy.sh) | Deployment lives with the compose files | How the droplet is deployed; `deploy.sh` now builds the sidecar and applies module schema upgrades |
| [../TODOS.md](../TODOS.md) | Repo-wide debt ledger | The two open items from the Week 1 grader feedback (tasks 9.1, 9.2 in DESIGN.md) and other deferred work |
| [../KEY_METRICS.md](../KEY_METRICS.md) | Required at the repo root by the brief | Eleven metrics with baselines and alerts; 8-11 are Week 2 (anchored-field rate, retrieval hit rate, routing accuracy, gate pass rate) |
| [../COST_AND_LATENCY.md](../COST_AND_LATENCY.md) | Required at the repo root by the brief | Latency per step (briefing, question, extraction), the five bottlenecks, actual Week 2 spend; the per-tier projection is Thursday's addition |
| [../README.md](../README.md) | Repo front page | Week 1 vs Week 2 side by side, the document index, the environment-variable table, the core flow in five commands |
| [../clinical_copilot_week1/](../clinical_copilot_week1/README.md) | Week 1 documents, unchanged | Baseline behaviour the brief asks to keep separate from Week 2; its [ENGINEERING_REQUIREMENTS.md](../clinical_copilot_week1/ENGINEERING_REQUIREMENTS.md) is the Week 1 audit the Week 2 one builds on |
