# Clinical Co-Pilot API collection, Week 2 (Bruno)

Runnable requests for the Week 2 endpoints and workflows. Works in the
[Bruno](https://www.usebruno.com/) desktop app or its CLI; nothing needs a
source file or a manual edit between runs. Week 1's collection,
[../../clinical_copilot_week1/api-collection/](../../clinical_copilot_week1/api-collection/README.md),
covers the chat path in depth (briefing, follow-ups, refusals, cache, alert
webhook); this one is independent of it and covers what Week 2 added.

## Run

Desktop app: open this folder as a collection, pick the `local` or `vps`
environment, run the collection (requests execute in order).

CLI (Node 18+; nothing to install):

```bash
cd clinical_copilot_week2/api-collection
npx --yes @usebruno/cli@2 run --disable-cookies --env local     # the dev stack on http://localhost:8300
npx --yes @usebruno/cli@2 run --disable-cookies --env vps       # the droplet; set password in environments/vps.bru first
```

`--disable-cookies` matters: the collection captures OpenEMR's session
cookie itself (requests 03, 04, 13, 14) and sends it explicitly, so it
behaves the same in the app and the CLI.

## The three workflows a grader can run

1. **Attach a lab report and read it back as cited facts** (05 → 10). Upload
   the bundled synthetic five-page lab PDF, extract it through the sidecar's
   supervisor/worker graph, see it listed as extracted with its confidence,
   then brief the chart and find facts whose citation names the document,
   the page and the bounding box of the cell the value was read from.
2. **Ask a treatment question and get guideline evidence** (11). The
   supervisor routes to the evidence retriever; the answer cites patient
   facts by 8-character ids and guideline passages by 12-character ids, and
   `answer.guidelines` carries the passages.
3. **Prove the refusals** (12, 13–16). The same upload without a CSRF token
   is refused before the file is read; a valid OpenEMR user without the
   documents permission is refused on every documents action, with the
   error text the panel shows.

Request 17 reads the pre-warm sweep's status (the module's queue), which
needs no session.

## Requests

| # | Request | What it proves | Contract |
|---|---|---|---|
| 01 | Health | Liveness, no auth | `health.response` |
| 02 | Ready | Dependency probes: database, OpenAI, Langfuse (degraded only); cached 60 s. The sidecar has no probe yet (engineering requirement 6); 08 and 11 prove it is up | `ready.response` |
| 03 | Login page | Starts the OpenEMR session | |
| 04 | Login | Authenticates as `user`; 302 = success | |
| 05 | Open demo chart | Sets the session patient (`docPid`); captures the CSRF token and the documents endpoint from the panel | |
| 06 | Documents: list | The chart's Co-Pilot documents (may be empty) | `documents.request`, `documents.list.response` |
| 07 | Documents: upload lab PDF | Multipart upload of `fixtures/lab-layout1.pdf`; **201** stored, or **200** with `existing: true` on a re-run (deduplicated per patient by sha3-512), same `document_id` either way | `documents.upload.response` |
| 08 | Documents: extract | The sidecar graph runs (5–20 s the first time): `status: extracted`, `handoffs` (supervisor → intake_extractor → supervisor → done), `results_persisted`, `unverified`, `unextracted`, `confidence`; a repeat answers `already: true` | `documents.extract.response`, `handoff` |
| 09 | Documents: list again | The document now reads `extracted` with its confidence | `documents.list.response` |
| 10 | Brief | Facts cite the document: `citation.source_type = "document"`, `source_id` = the document id, `anchored: true`, `bbox` with page and coordinates; captures `facts_hash` | `chat.briefing.response`, `fact`, `citation` |
| 11 | Ask a treatment question | `answer.type` cited (or a refusal shape), `answer.guidelines[]` with `chunk_id`, `source_id`, `section`, `quote`; every citation is an 8- or 12-character id; at most six sentences | `chat.answer.response` |
| 12 | Upload without CSRF | 403, file never read | `documents.error.response` |
| 13–15 | Restricted user session | New session as `restrictedUser`, login, open the same chart | |
| 16 | Restricted documents list | 403 "You are not authorized to manage documents for this chart" | `documents.error.response` |
| 17 | Pre-warm status | `enabled` flag and the last sweep's counts, no auth | `prewarm.response` |

Every request has a `docs` block saying what it does and what to expect;
the assertions and `tests` blocks are the pass/fail criteria. The contracts
named in the last column are the JSON Schemas in
[`contracts/`](../../interface/modules/custom_modules/oe-module-clinical-copilot/contracts/README.md)
that each response conforms to.

## Environments

| File | Target | Notes |
|---|---|---|
| `environments/local.bru` | http://localhost:8300 (dev stack) | `admin`/`pass`, `receptionist`/`receptionist` as shipped with the demo data |
| `environments/vps.bru` | https://146-190-139-37.sslip.io | Set `password` to the deployed admin password before running 03+. 01, 02 and 17 need no credentials. |

`docPid` defaults to 1, a quiet demo patient. It is deliberately not one of
the busiest charts: the live eval cases measure those, and a document the
collection attaches must not change what they see. Any seeded patient
works; the upload stays attached to that chart after the run (there is no
delete endpoint; the smoke test cleans up after itself, this collection does
not, by design, so a grader can open the chart in the UI afterwards and see
the highlight).

## What this collection does not cover, and why

- **The sidecar's own HTTP surface** (`POST /run`, `GET /health`). It is
  reachable only on the docker network (no host port), and PHP is its only
  client: requests 08 and 11 exercise `/run` end to end through
  `documents.php` and `chat.php`. Its `/eval/*` endpoints exist only under
  `COPILOT_EVAL_ENDPOINTS=1` for the eval harness and are never deployed.
- **`copilot:attach`**, the CLI twin of 07+08 (`bin/console copilot:attach
  <pid> <file> lab_pdf`), because it is a console command, not HTTP.
- **Cleanup.** See above.

## Verified

Local dev stack, 2026-09-22, Bruno CLI 2.15.1: first run 17/17 requests,
39/39 assertions, 5/5 tests in 28.6 s (07 answered 201, 08 ran the graph in
~10 s); second run 17/17 in ~6 s with 07 → 200 `existing: true` and 08 →
`already: true`, proving the collection is repeatable.

Deployed droplet (`vps` environment), 2026-09-23, build `fc7aa13`: 17/17
requests, 41/41 assertions; extraction of the five-page report 14.2 s,
brief with document facts 4.4 s, guideline question 2.5 s. The run is
saved as [`results-deployed.json`](results-deployed.json) (Bruno's JSON
report; no patient data, the demo chart only). The first deploy that day
failed requests 08 and 11 with 500s, which is how a dev-only dependency
was caught; see ENGINEERING_REQUIREMENTS.md § 3.
