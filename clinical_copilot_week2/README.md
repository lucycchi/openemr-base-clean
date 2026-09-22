# Clinical Co-Pilot, Week 2 documents

Everything written for the Week 2 build (multimodal evidence agent: lab PDF
and intake-form ingestion, hybrid guideline retrieval, supervisor + workers,
eval gate). Week 1 documents stay in [../clinical_copilot/](../clinical_copilot/).

| Document | What it is |
|---|---|
| [W2_ARCHITECTURE.md](W2_ARCHITECTURE.md) | The architecture: extraction-stack spike and decision, tools by step, how the agents cooperate, the review/rating loop, findings log. Required by the Week 2 brief as `./W2_ARCHITECTURE.md` (a pointer file sits at the repo root). |
| [DESIGN.md](DESIGN.md) | The approved design record and the phased TODO list, with decisions and the review history (three Claude passes, two Codex passes). Ticked as phases complete. |
| [DOCUMENT_SOURCES.md](DOCUMENT_SOURCES.md) | Candidate sources for sample lab reports and intake forms, the no-real-patient rule, vetting status, and how a chosen source becomes a fixture. |

Related, kept next to what they describe:

- [../tests/evals/README.md](../tests/evals/README.md): the eval gate, rubrics, case format, and the recorded proof that the hook refuses a regression.
- [../interface/modules/custom_modules/oe-module-clinical-copilot/contracts/README.md](../interface/modules/custom_modules/oe-module-clinical-copilot/contracts/README.md): every JSON contract, including the Week 2 ones.
- [../TODOS.md](../TODOS.md): the two open items from the Week 1 grader feedback and other deferred work.
- [../KEY_METRICS.md](../KEY_METRICS.md), [../COST_AND_LATENCY.md](../COST_AND_LATENCY.md) (Phase 8): required at the repo root by the brief.
