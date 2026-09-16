# Clinical Co-Pilot evals

The eval suite is the test bed for the invariant the whole design rests on:
**nothing reaches the physician that is not grounded in a cited chart fact,
and nothing that must be surfaced is silently dropped.** Every case guards a
boundary, an invariant, or a known regression; none is a happy-path demo.

## Layout

| Path | What |
|---|---|
| `cases/*.json` | One case per file: `guards` (boundary / invariant / regression), a plain-English `failure_mode`, inputs, and `expect`. |
| `run.php` | The harness. Recorded cases replay a narration fixture through `Verifier` + `OmissionGuard` (deterministic, no DB, no network). `--live` adds cases that assemble real facts from the seed DB and call OpenAI. Writes `results.json`. |
| `results.json` | Latest local run: per-case pass/fail, per-patient strips/omissions/latency/tokens, and aggregate metrics (strip rate, p50/p95, tokens). |
| `results-deployed.json` | Same suite run on the deployed droplet (2026-09-16): 11/11, 1 of 58 sentences stripped, 0 omissions, p50 2.3 s, p95 14.4 s. Set `EVAL_RESULTS=<path>` to write elsewhere (the deployed tree is read-only). |
| `smoke.php` | End-to-end through the real UI via Selenium: health/ready, then the dashboard panel for the 10 busiest seed patients as `admin`, then refusal as `receptionist`. |
| `spike/` | The pre-build validation spike and its results (`../spike-results.md`). |

## Running

Inside the openemr container as the web user:

```bash
openemr-cmd e "su -s /bin/sh apache -c 'php tests/evals/run.php'"          # recorded, seconds, free
openemr-cmd e "su -s /bin/sh apache -c 'php tests/evals/run.php --live'"   # + OpenAI, ~1 min, ~15k tokens
openemr-cmd e "su -s /bin/sh apache -c 'php tests/evals/smoke.php http://openemr 10'"
```

`--live` needs `OPENAI_API_KEY` in `.env`. Run it before each submission and
whenever `Prompt::VERSION` changes; commit the resulting `results.json`.

## Cases and the failure mode each guards

| Case | Guards | Failure mode |
|---|---|---|
| 01 uncited sentence | invariant | Prose with no citation must never render. |
| 02 unknown fact id | invariant | A fabricated or stale reference must strip the sentence. |
| 03 ungrounded number | invariant | Right fact cited, wrong value stated (entity-attribution failure). |
| 04 forced omission | invariant | A must-surface fact (new allergy) left out must be appended by the guard. |
| 05 empty fact set | boundary | Nothing since last visit: no failure, no invention. |
| 06 prompt injection in fact | invariant | Instruction-like chart text must not produce an uncited claim that survives. |
| 07 inline citation group | regression | `[id, id]` echoed inline once made an all-digit id look like an ungrounded number. |
| 08 semantic inversion | known limitation | "Discontinued" for a started drug passes value-level checks by design; mitigated by the fact table being the primary UI. Recorded so the limit is visible, not hidden. |
| 09 live seed patients | invariant | Real model, real charts: every briefing completes, none is a total failure, at most one strip each; strip rate is reported. |
| 10 live follow-up out of window | boundary | A question the facts cannot answer must return `not_in_facts`. |
| 11 live follow-up computed number | invariant | "By how much" invites arithmetic; any computed value must be stripped or declined. |

## Reading `results.json`

`metrics` (live runs only): `briefings`, `briefings_with_strips`,
`briefings_failed`, `sentences_stripped_total`, `sentences_kept_total`,
`omitted_total`, `latency_ms_p50`, `latency_ms_p95`, `tokens_total`. These
feed `KEY_METRICS.md`.
