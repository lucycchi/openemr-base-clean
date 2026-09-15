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

## Already Completed

- [x] **Local run** — OpenEMR running via `docker/development-easy`, documented
  in `README.md` (setup steps, login, phpMyAdmin).
- [x] **Security audit** — `AUDIT.md` + `audit-long.md` + `AUDIT_TASKS.md`.
  87 findings (10 High / 37 Medium / 25 Low / 15 Info) across security,
  performance, architecture, data quality, HIPAA/compliance. Executive summary
  is present and appears to be within the ~500-word gate.
- [x] **Vulnerability remediation pass** — commit `859ad84 fixes
  vulnerabilities`, applied before the second audit pass (per `AUDIT.md`'s
  own note that later manual review ran against post-fix commits).
- [x] **First draft of an AI integration plan** — `AI_INTEGRATION_PLAN.md`
  (chart/visit-summary generation, `ChartFacts` assembly, e-sign workflow,
  narrative validator). **However:** this was written *before* `AUDIT.md`
  existed (the file says so explicitly) and it does not describe a
  conversational, multi-turn, tool-calling agent — it describes a
  batch narrative-summary generator. The PRD explicitly rules this shape out
  as the core interface ("not a report generator"). See Gap #1 below — this
  doc needs rework, not just a rename, before it can satisfy the
  `ARCHITECTURE.md` hard gate.
- [x] `graphify-out/` knowledge graph generated for codebase navigation.

## Gaps Found (things the PRD requires that don't exist yet)

1. **`ARCHITECTURE.md` does not exist.** `AI_INTEGRATION_PLAN.md` covers
   adjacent ground but (a) predates the audit and needs to actually
   incorporate its findings (e.g. the menu-gated-not-ACL-gated auth pattern,
   no caching layer, no "encounter finalized" event, audit log PHI/integrity
   gap), and (b) describes summary generation, not the required conversational
   agent with tool use. Needs a decision: extend/rewrite this doc, or treat
   it as a parallel feature and write a new, separate `ARCHITECTURE.md` for
   the chatbot. **Blocks the Wednesday hard gate.**
2. **`USERS.md` does not exist.** No target user, workflow, or use cases are
   defined anywhere in the repo yet. This is upstream of `ARCHITECTURE.md`
   per the PRD ("every agent capability must point to a use case here") —
   do this before/alongside architecture. **Blocks the Wednesday hard gate.**
3. **`KEY_METRICS.md` does not exist.** **Blocks the Wednesday hard gate.**
4. **No public deployment.** No deploy config found (`docker/production/`
   exists but nothing indicates it's live anywhere; no fly.toml/render.yaml/
   railway config, no recorded URL). Stage 2 is a hard gate for *every*
   submission (early and final), and the PRD says to deploy the final agent
   to the same infra — worth locking in the target platform now.
5. **No agent code exists yet.** Everything so far is audit/planning docs;
   Stage 5 (agent build: chatbot, verification layer, observability, eval)
   hasn't started.

---

## Due Wednesday 2026-09-16, 11:59 PM — Early Submission (hard gates)

Per the PRD, Early Submission requires: a deployed agent, eval framework,
observability wired in, and a demo video, and unlocks the required AI
interview (due 24h after submission).

### Docs (hard gates, PRD Submission Requirements table)
- [ ] `USERS.md` — target user, their concrete workflow, and specific use
  cases, each with an explicit "why an agent" justification. **Do this
  first** — everything else traces back to it.
- [ ] `ARCHITECTURE.md` — 1-page (~500 word) summary up top, then the full
  plan: where the agent lives, how it accesses patient data, authorization
  boundaries, verification strategy, framework/LLM choice, known tradeoffs.
  Must synthesize `AUDIT.md` findings and trace every capability to a
  `USERS.md` use case. Decide fate of `AI_INTEGRATION_PLAN.md` first (see
  Gap #1).
- [ ] `KEY_METRICS.md` — the metrics that prove the product works, with
  rationale, defensible to a hospital CTO.

### Deployment
- [ ] Choose and stand up hosting for the OpenEMR fork + agent (same infra
  for both, per PRD Stage 2).
- [ ] Confirm publicly reachable URL; this URL is required in *every*
  submission from here forward.

### Agent build (minimum for a working Early Submission)
- [ ] Conversational agent interface embedded in OpenEMR (multi-turn, tool
  calls into patient data) — scope strictly to what `USERS.md` use cases
  require.
- [ ] Tool layer with typed input/output schemas (Pydantic/Zod/equivalent)
  for each tool — contracts as source of truth.
- [ ] Verification layer: source attribution (claims traceable to patient
  record fields) + domain constraint checks, sitting between tool output and
  what reaches the user.
- [ ] Authorization enforcement — who's asking, what patients/data they can
  see (ties to the audit's ACL findings).
- [ ] Correlation ID on every agent invocation, propagated through logs,
  tool calls, and LLM calls.
- [ ] Observability wired in from the start: per-request step trace, timing
  per step, tool failure capture, token/cost tracking. Pick a backend
  (LangSmith/Langfuse/Braintrust/equivalent).
- [ ] `/health` and `/ready` endpoints, with `/ready` actually checking
  OpenEMR DB, LLM provider, and observability backend reachability.
- [ ] Eval framework stood up with an initial dataset: boundary cases
  (missing data, malformed input, empty record), invariants (claims cite a
  source), and at least one authz/regression case. Document the failure mode
  each case guards against.
- [ ] Failure-mode handling: graceful degradation when a tool fails or a
  record is incomplete — no silent failure, no crash.

### Other hard gates
- [ ] Demo video (3–5 min) covering current state.
- [ ] Schedule the required Technical Interview (within 24h of submission —
  Thu/Fri per PRD).

---

## Due Sunday 2026-09-20, Noon — Final Submission (hard gates)

Everything above, hardened and completed, plus:

### Agent completeness
- [ ] Full eval suite with recorded results (not just cases — actual run
  output), covering boundaries/invariants/regression per Engineering
  Requirements, including adversarial/unauthorized-access queries.
- [ ] Verification layer's known limitations documented (what it catches,
  what it doesn't).
- [ ] Failure modes for every tool documented and handled.

### Engineering Requirements (PRD, distinct from agent features — graded
separately, not optional)
- [ ] Dashboard: request count, error count, p50/p95 latency, tool call
  counts, retry counts, verification pass/fail rate, live.
- [ ] At least 3 alerts defined (p95 latency, error rate, tool failure rate)
  with documented meaning + on-call response for each.
- [ ] Runnable API collection (Postman/Bruno/equivalent) covering core agent
  endpoints — graders must be able to exercise the agent without reading
  source.
- [ ] Baseline CPU/memory/latency/throughput profile captured under load.
- [ ] Load tests at 10 and 50 concurrent users against the *deployed* agent,
  with p50/p95/p99 latency and error rate recorded at each level.

### Docs
- [ ] AI cost analysis: actual dev spend + projected cost at 100 / 1K / 10K /
  100K users, including architecture changes needed at each tier (not just
  linear cost-per-token scaling).
- [ ] Update `README.md` / repo docs to reflect final architecture and setup.
- [ ] Refresh `ARCHITECTURE.md` / `KEY_METRICS.md` if the plan changed
  between Wednesday and Sunday.

### Other hard gates
- [ ] Final demo video (3–5 min), production-ready framing.
- [ ] Social post (X or LinkedIn), tagging `@GauntletAI`.
- [ ] AI interview (scheduled after submission, required within 24h).

---

## Future (beyond this Week 1 deliverable — Weeks 2–3 of the case study)

Not required for this submission, but flagged since the PRD says Week 1
architecture choices compound:

- [ ] Production-hardening pass: revisit anything deferred under Wednesday's
  time pressure (e.g. thinner verification, mocked tools, minimal eval
  coverage).
- [ ] Scale plan execution: what changes to go from the 30-patient dev seed
  toward "500-bed hospital, 300 concurrent users" (interview question the
  PRD flags explicitly).
- [ ] Expand eval dataset from an eval-driven improvement loop using
  production/demo usage data.
- [ ] Address remaining Medium/Low findings from `AUDIT.md` that aren't
  blocking but matter for a real deployment (full list in `audit-long.md`).
- [ ] Revisit `AI_INTEGRATION_PLAN.md`'s chart/visit-summary feature as a
  possible *additional* capability once the core conversational agent from
  `USERS.md` is live — it may still be valuable, just not as the primary
  interface.
- [ ] Decide on open-source release scope/licensing/docs if pursuing that
  track (PRD Appendix §14).

---

## Clarifying Questions

1. **Sprint calendar** — is 2026-09-15 actually the Tuesday of Week 1 (making
   Wed = 09-16, Sun = 09-20), or are we further into (or before) the week?
   This changes every date above.
2. **`AI_INTEGRATION_PLAN.md`'s fate** — rework it into `ARCHITECTURE.md`
   (folding in a conversational-agent layer on top of its chart/visit-summary
   design), replace it outright with an agent-first architecture doc, or
   keep it as a *secondary* planned feature referenced from a new
   `ARCHITECTURE.md`? This is the single biggest open decision blocking
   Wednesday's docs.
3. **Target user** — do you already have someone in mind (PCP with a
   20-patient day, ED resident on overnight intake, hospitalist rounding),
   or should I propose options for `USERS.md` and we pick together?
4. **Deployment target** — any existing account/preference (Fly.io, Railway,
   a VPS, AWS/GCP, etc.)? `docker/production/` exists but nothing in the repo
   points at a live target yet.
5. **LLM provider** — Claude, OpenAI, or provider-agnostic? Affects tool-use
   API shape and the cost-analysis doc.
6. **Observability backend** — any preference among LangSmith/Langfuse/
   Braintrust, or should I pick based on framework choice?
7. **Team size** — solo, or is anyone else contributing? Affects how
   aggressively to parallelize the Wednesday task list.
