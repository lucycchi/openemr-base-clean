# Clinical Co-Pilot — documentation

Everything a reviewer needs for the AgentForge Week 1 Clinical Co-Pilot,
in one folder. The feature itself lives in
[`interface/modules/custom_modules/oe-module-clinical-copilot/`](../interface/modules/custom_modules/oe-module-clinical-copilot/).

**Deployed:** https://146-190-139-37.sslip.io (login `admin`; synthetic
demo data) · [/health](https://146-190-139-37.sslip.io/interface/modules/custom_modules/oe-module-clinical-copilot/public/health.php)
· [/ready](https://146-190-139-37.sslip.io/interface/modules/custom_modules/oe-module-clinical-copilot/public/ready.php)

## Suggested reading order

| # | Document | Read it for |
|---|---|---|
| 1 | [USING_CLINICAL_COPILOT.md](USING_CLINICAL_COPILOT.md) | How to open the panel, read it, ask follow-ups, what every message means, which patients to demo. Start here if you want to click through the deployed app. |
| 2 | [api-collection/](api-collection/README.md) | Runnable Bruno collection: 16 requests covering health, readiness, login, briefing, cited/withheld/out-of-window follow-ups, chart-changed, cache hit, CSRF and ACL refusals. Run it from the app or `npx @usebruno/cli`. |
| 3 | [USERS.md](../USERS.md) | Who it is for (a PCP with a 20-patient day), the workflow it enters, three use cases, capability→use-case traceability, who is refused by design. |
| 4 | [ARCHITECTURE.md](../ARCHITECTURE.md) | Facts-first design, data flow, the verifier and omission guard precisely, authorization and PHI boundaries, failure modes, observability, deployment, testing, tradeoffs made knowingly. |
| 5 | [KEY_METRICS.md](../KEY_METRICS.md) | The five metrics that define "working", their baselines from the deployed eval run, alert thresholds, and cost per briefing. |
| 5a | [ENGINEERING_REQUIREMENTS.md](ENGINEERING_REQUIREMENTS.md) | The nine graded engineering requirements: what was done for each and where the evidence is. |
| 5b | [DASHBOARD.md](DASHBOARD.md) · [ALERTS.md](ALERTS.md) · [BASELINES.md](BASELINES.md) | The Langfuse dashboard (with screenshots), the three paging alerts with runbooks and the proven webhook delivery, and the 10/50-user load baselines with CPU/memory. |
| 6 | [DESIGN.md](DESIGN.md) | The design record: problem, premises, approaches considered, the engineering review's 22 decisions, failure-mode table, test plan, implementation tasks. Where a decision came from. |
| 7 | [AUDIT.md](../AUDIT.md) | The security / performance / architecture / data-quality / HIPAA audit of the OpenEMR base that the design was built against. |

## Elsewhere in the repo

| Path | What |
|---|---|
| [AI_COST_ANALYSIS.md](AI_COST_ANALYSIS.md) | Actual development spend (OpenAI from the audit log, Claude Code from session transcripts, infrastructure) and projected production cost at 100 / 1K / 10K / 100K users with the architectural change each tier needs. |
| [EVALS.md](EVALS.md) | The test suite with results: scope, five layers, the dataset shape, pass/fail rules, all 15 cases, latest local and deployed numbers, what the suite found, and the design decisions behind it. |
| [tests/evals/README.md](../tests/evals/README.md) | The eval suite: 15 cases, the failure mode each guards, how to run, how to read results. |
| [tests/evals/results-deployed.json](../tests/evals/results-deployed.json) | Latest run against the deployed instance: 11/11, 1 of 58 sentences stripped, 0 omissions, p50 2.3 s. |
| [tests/evals/spike-results.md](../tests/evals/spike-results.md) | Pre-build validation spike: timing, encounter data quality, ACL refusal by user. |
| [tests/Tests/Isolated/Modules/ClinicalCopilot/](../tests/Tests/Isolated/Modules/ClinicalCopilot/) | 95 isolated PHPUnit tests over the module. |
| [docker/vps/README.md](../docker/vps/README.md) | How the droplet is deployed and redeployed. |
| [TODOS.md](../TODOS.md) | Deferred items with rationale (conversation persistence, custom image, drug-interaction source, nurse intake in the briefing, due-today checklist, fixed briefing schema, physician thumbs-up/down rating, chat adoption per encounter, Langfuse v4). |
| [project-tasks.md](../project-tasks.md) | PRD task list with status against each deadline. |
| [AI_INTEGRATION_PLAN.md](../AI_INTEGRATION_PLAN.md) | Superseded earlier plan, kept for the record; its `ChartFacts` idea survived into the tool layer. |

## The one-paragraph version

The language model never generates clinical facts. Deterministic PHP
assembles a typed, cited fact set from the chart (what changed since the
prior visit, plus active meds and allergies); that table is rendered
first, with no model involved. The model then narrates by fact id only,
under a strict JSON schema. A pure verifier strips any sentence that
cites nothing, cites an unknown id, or states a number or date not in a
cited fact; an omission guard appends any must-surface fact the model
skipped. Authorization is enforced in the tool layer, not the menu; no
identifiers leave the server; every request carries a correlation id
into the logs and Langfuse. See ARCHITECTURE.md for the rest.
