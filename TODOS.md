# TODOS

## Clinical Co-Pilot

### Server-side conversation persistence

**What:** Store chat threads in a table keyed by (pid, encounter_id, user_id) instead of holding the transcript in the browser.

**Why:** Threads survive page reloads and there is an audit trail of what the agent said to whom about which patient. The HIPAA audit section of the submission will ask how agent interactions are recorded.

**Context:** Cut from v1 in the eng review (decision D1, 2026-09-15) to avoid a migration before the Wednesday gate. The v1 endpoint receives the full transcript each turn and re-verifies it, so correctness does not depend on server state. Insertion point: `NarrationPipeline` already has the transcript; add a `copilot_conversation` table in the module's `sql/install.sql`, write each turn, and load by key on panel open. Needs a retention policy (PHI at rest) documented in ARCHITECTURE.md.

**Effort:** M
**Priority:** P1
**Depends on:** None

### Custom production image

**What:** A Dockerfile that bakes the fork (including the module) into an image, replacing the flex bind-mount deploy.

**Why:** The Wednesday deploy runs the dev-flavored `openemr/openemr:flex` image with the source tree bind-mounted on the VPS (decision D13). A real image is reproducible, keeps no source tree on the server, and is what a hospital would run.

**Context:** `docker/production/docker-compose.yml` pulls the upstream `openemr/openemr:latest` image, which does not contain the fork. Start from the upstream Dockerfile in openemr-devops, COPY the fork over `/var/www/localhost/htdocs/openemr`, build in CI, push to a registry, and point the production compose at it. Module enablement must be scripted (SQL) since Module Manager is a UI step.

**Effort:** M
**Priority:** P2
**Depends on:** Wednesday deploy working on flex + bind-mount first

### Drug-drug interaction source of truth

**What:** Wire an interaction source (NLM RxNorm interaction API or a curated rule table) so `FactAssembler` can emit grounded interaction facts.

**Why:** The PRD names interaction flags as a domain constraint. v1 ships allergy-vs-medication only, because this install has no interaction data and letting the LLM decide interactions from general knowledge is an ungrounded clinical claim.

**Context:** Design doc premise #4 (`docs/designs/pre-room-briefing-agent.md`). Prescriptions in OpenEMR carry a drug name and optionally an RxNorm code (`prescriptions.rxnorm_drugcode`); most seed rows will need name-to-RxCUI mapping. Emit interactions as a new fact category so the verifier and omission guard cover them with no other changes.

**Effort:** L
**Priority:** P3
**Depends on:** RxNorm mapping for seeded prescriptions; licensing review of the chosen source

## Completed
