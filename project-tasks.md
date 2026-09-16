# AgentForge — Project Task List

Source: `Week 1 - AgentForge.pdf` (Clinical Co-Pilot PRD), cross-checked against
this repo's current state (`git log`, existing `.md` deliverables) on
2026-09-15.

**Date assumption (please confirm — see Clarifying Questions):** treating
today, 2026-09-15, as the Tuesday of the sprint week, which implies:
- **Wed = 2026-09-16** → Early Submission (11:59 PM)
- **Thu/Fri = 2026-09-17/18** → Technical Interview
- **Sun = 2026-09-20 @ Noon** → Final Submission

If the sprint actually started on a different day, every date below shifts —
flag it and I'll re-date this file.

Legend: `[x]` done in this repo already, `[ ]` not started/incomplete,
`[~]` partially done / exists but doesn't meet the gate as written.

---

## Status as of 2026-09-16 (evening before Early Submission)

Decisions since this file was written: target user, architecture, provider
(OpenAI), deployment (VPS + flex image), observability (Langfuse Cloud) are
all decided and recorded in `clinical_copilot/DESIGN.md`
(includes the engineering review with 22 decisions).

## Completed

- [x] Local run, `README.md` setup section.
- [x] Audit: `AUDIT.md`, `audit-long.md`, `AUDIT_TASKS.md`; vulnerability fix pass.
- [x] Design record: `clinical_copilot/DESIGN.md` (office hours + eng review, APPROVED).
- [x] Validation spike and results: `tests/evals/spike-results.md`.
- [x] `USERS.md`, `ARCHITECTURE.md` (539-word summary first), `KEY_METRICS.md`.
- [x] `AI_INTEGRATION_PLAN.md` marked superseded.
- [x] Agent module `oe-module-clinical-copilot`: FactAssembler with ACL, prior-visit rule, sensitivity filter, reference-range abnormal labs, deltas, allergy-vs-medication; ID-only narration; Verifier; OmissionGuard; OpenAI client with typed failures; briefing cache; dashboard panel with chips, follow-ups, chart-changed restart.
- [x] Correlation ids on every log line, audit-log row per request, Langfuse tracer (unit-tested; live pending keys).
- [x] `/health` and `/ready` (real probes, 60 s cache, Langfuse degraded-not-down).
- [x] 73 isolated unit tests; 11 eval cases (recorded + live) with `results.json`; UI smoke.
- [x] `docker/vps/` compose, env example, README (module registration, capsule seed transfer).
- [x] `TODOS.md` (conversation persistence, custom image, interaction source).

## Due Wednesday 2026-09-16, 11:59 PM — remaining

- [ ] **VPS live** (in progress): `docker compose up`, module registered, seed capsule restored, `OPENAI_API_KEY` in `.env`. Paste the URL into `README.md` ("Deployed").
- [ ] **Langfuse Cloud project**: create, put `LANGFUSE_PUBLIC_KEY`/`LANGFUSE_SECRET_KEY` in both `.env` files; confirm `/ready` shows `langfuse: ok` and a trace appears; set up the dashboard (requests, error rate, p50/p95, tokens, `verification_pass`) and the three alerts from `KEY_METRICS.md`.
- [ ] **Runnable API collection** (Postman/Bruno): `health.php`, `ready.php`, `chat.php` brief/ask with CSRF + session cookie notes. Not started.
- [ ] **Demo video (3-5 min)**: open patient → latest encounter → Dashboard; show fact table, summary chips, a follow-up, the withheld computed-number answer, receptionist refusal, `/ready`.
- [ ] Schedule the Technical Interview (Thu/Fri).
- [ ] Re-run `tests/evals/run.php --live` against the deployed box once up and commit `results.json`.

## Due Sunday 2026-09-20, Noon

- [ ] Dashboard regression E2E (Panther) for all seeded patients with the module enabled (mandatory before the branch ships), remaining Panther flows, DB-backed adapter tests.
- [ ] Load tests at 10 and 50 concurrent users against the deployed agent; p50/p95/p99 and error rate; baseline CPU/memory.
- [ ] AI cost analysis (dev spend from Langfuse + eval token counts; 100 / 1K / 10K / 100K users with the cache-hit ratio as the main lever; architectural changes per tier).
- [ ] Three alerts documented with on-call response (drafted in `KEY_METRICS.md`; wire in Langfuse).
- [ ] Final demo video, social post tagging @GauntletAI, AI interview.
- [ ] Refresh docs for anything that changed after Wednesday.

## Future (Weeks 2-3)

- [ ] `TODOS.md` items: server-side conversation persistence with retention policy; custom production image; drug-drug interaction source (RxNorm/NLM).
- [ ] Semantic-inversion mitigation beyond the fact table (e.g. a second-model judge on kept sentences) if the eval shows it matters in practice.
- [ ] Lab-delta noise threshold (pid 15 produced 20 deltas, some tiny).
- [ ] Scale plan for 500 beds / 300 concurrent users: cache hit ratio, provider rate limits, per-tenant keys.
- [ ] Remaining Medium/Low audit findings.
