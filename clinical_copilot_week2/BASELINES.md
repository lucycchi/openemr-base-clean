# BASELINES.md, Week 2: performance baselines of the multi-agent build

Week 1's baselines ([../clinical_copilot_week1/BASELINES.md](../clinical_copilot_week1/BASELINES.md))
measured the chat path on the Week 1 build. Week 2 changed the ask path
(every follow-up goes through the sidecar's evidence retriever first, and
the answer is capped at six sentences), added the document path (upload →
sidecar extraction → persist) and a second container. This file records
the Week 2 build's baselines on the same droplet, with the same tooling
([../tests/load/](../tests/load/README.md)) plus a Week 2 `extract`
scenario and a sidecar column in the resource tables.

It records **two runs on purpose**: the first run of the day found a
concurrency defect in the sidecar; the second run measures the fix. The
pair is what "measure future changes against a baseline" looks like in
practice.

## Setup

| | |
|---|---|
| **Target** | `https://146-190-139-37.sslip.io`, the same droplet as Week 1: DigitalOcean 2 vCPU @ 2.0 GHz, 3 915 MiB RAM, `openemr/openemr:flex` + MariaDB + the Co-Pilot sidecar (`python:3.12-slim`, PyMuPDF + tesseract, 768 MiB memory limit, one uvicorn worker) on the same box, no reverse proxy |
| **Code** | run 1: commit `fc7aa13` (Phase 8 deploy); run 2: commit `96a0947` (the graph on the thread pool). `Prompt::VERSION 2026-09-22.5` |
| **Models** | OpenAI `gpt-4o-mini` (narration, extraction), `text-embedding-3-small` (query embedding), Cohere `rerank-v3.5` (retrieval rerank; the key was added to the droplet the same day, so every follow-up reranked) |
| **Data** | the 30 seed patients rotated per iteration for `brief` / `mixed` / `ask`; patient 30 for `extract`, cleaned with `tests/load/cleanup-documents.php` after each matrix |
| **Load generator** | k6 v2.2.0 on a WSL2 laptop over the public internet; 1 s think time; each VU = one logged-in physician session |
| **Runs** | run 1 `20260923T1522Z`: 8 × 2 minutes, 30 s gap, {10, 50} VUs × {brief, mixed, ask, extract}; run 2 `20260923T1558Z`: {10, 50} × {ask, extract}, the two scenarios the fix touches |
| **Resource sampling** | `docker stats` + `/proc/loadavg` every 2 s on the droplet over ssh, now with the sidecar as its own bucket |

Scenario meanings: **brief** = open chart → briefing (deterministic path,
cache-hit after the first pass); **ask** = + one follow-up per iteration
(retrieval leg through the sidecar, then one model call); **mixed** =
follow-up on 30 % of iterations; **extract** = open the load patient →
upload the five-page synthetic lab report with a unique trailer → extract
(one real sidecar run per iteration: parse, five model calls, anchoring,
persist).

## Run 1 (`fc7aa13`): the sidecar serialised every request

### Latency and errors

| VUs | Scenario | Requests | req/s | Briefs (cache hit %) | Asks (guideline hit %) | Model calls | Brief p50/p95/p99 (ms) | Cache-hit brief p50/p95 | Cold brief p50/p95 | Ask p50/p95/p99 (ms) | Chart open p50/p95 | HTTP errors | Co-Pilot errors | Summary unavailable | Verification fail |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| 10 | ask | 167 | 1.21 | 49 (100) | 49 (0) | 49 | 356 / 784 / 795 | 356 / 784 | – / – | 19096 / 38960 / 39591 | 3721 / 9030 | 0 % | 0 % | 0 % | 0 % |
| 10 | brief | 230 | 1.83 | 105 (80) | 0 (–) | 21 | 790 / 4650 / 5559 | 746 / 1130 | 3606 / 5563 | – / – / – | 8664 / 13022 | 0 % | 0 % | 0 % | 0 % |
| 10 | mixed | 257 | 1.95 | 106 (99.06) | 25 (0) | 26 | 676 / 955 / 1066 | 676 / 946 | 4547 / 4547 | 3523 / 10288 / 16129 | 8512 / 11060 | 0 % | 0 % | 0 % | 0 % |
| 50 | ask | 535 | 3.56 | 107 (100) | 35 (0) | 21 | 1939 / 6416 / 12699 | 2595 / 9600 | – / – | 4868 / 12823 / 17209 | 861 / 59857 | 46.17 % | 71.04 % | 0 % | 0 % |
| 50 | brief | 406 | 2.7 | 115 (87) | 0 (–) | 13 | 2937 / 8210 / 14975 | 2723 / 4110 | 6815 / 16495 | – / – / – | 35530 / 60117 | 25.37 % | 47.64 % | 0 % | 0 % |
| 50 | mixed | 449 | 2.99 | 116 (100) | 25 (0) | 25 | 2676 / 4810 / 9238 | 2724 / 4679 | – / – | 6994 / 12307 / 13388 | 34249 / 58633 | 25.39 % | 46.35 % | 0 % | 0 % |

### Document extraction (Week 2)

| VUs | Requests | req/s | Extractions | Extracted | Fully verified | Confidence p50 | Upload p50/p95 (ms) | Extract p50/p95/p99/max (ms) | Chart open p50/p95 | HTTP errors | Co-Pilot errors |
|---|---|---|---|---|---|---|---|---|---|---|---|
| 10 | 82 | 0.55 | 20 | 5 % | 100 % | 1 | 562 / 803 | 60383 / 60652 / 60701 / 60714 | 6592 / 10103 | 23.17 % | 46.34 % |
| 50 | 3669 | 24.42 | 20 | 0 % | – % | – | 541 / 1468 | 60554 / 61005 / 61080 / 61098 | 38 / 294 | 95.56 % | 98.98 % |

### CPU and memory on the target host

| VUs | Scenario | App CPU avg / peak (% of one core) | App memory avg / peak (MiB) | Sidecar CPU avg / peak | Sidecar memory avg / peak (MiB) | DB CPU avg / peak | DB memory avg / peak (MiB) | Host load1 peak |
|---|---|---|---|---|---|---|---|---|
| 10 | ask | 40.6 / 142.7 | 528 / 550 | 4.5 / 49.5 | 127 / 140 | 34.3 / 99.8 | 394 / 396 | 5.05 |
| 10 | brief | 85.7 / 120.3 | 543 / 559 | 0.9 / 20.9 | 114 / 114 | 91.6 / 133.5 | 377 / 381 | 9.44 |
| 10 | extract | 16.9 / 110.0 | 528 / 551 | 2.4 / 18.7 | 131 / 142 | 11.9 / 111.5 | 397 / 397 | 3.14 |
| 10 | mixed | 87.4 / 165.6 | 542 / 555 | 2.7 / 24.4 | 120 / 132 | 90.3 / 123.5 | 387 / 391 | 8.78 |
| 50 | ask | 104.2 / 186.5 | 751 / 802 | 1.8 / 18.6 | 133 / 137 | 90.7 / 116.5 | 438 / 443 | 46.56 |
| 50 | brief | 102.0 / 173.4 | 763 / 825 | 3.3 / 24.7 | 133 / 142 | 93.1 / 110.3 | 411 / 418 | 43.14 |
| 50 | extract | 53.6 / 181.2 | 672 / 851 | 4.5 / 38.1 | 139 / 150 | 30.5 / 87.3 | 446 / 447 | 26.18 |
| 50 | mixed | 103.0 / 185.6 | 759 / 831 | 1.2 / 12.0 | 132 / 142 | 91.6 / 114.3 | 425 / 432 | 46.48 |


### Reading run 1

- **Extraction did not work under concurrency.** At 10 users, 20 uploads
  in two minutes, 1 extracted (5 %); the extract p50 is 60.4 s, which is
  PHP's `SidecarClient` giving up at its 60 s budget, so the panel showed
  "stored, retry" for 19 of 20 documents. At 50 users, none.
- **Follow-ups became slow.** `ask` p50 at 10 users: 19.1 s (Week 1: 1.4 s),
  p95 39 s, while the model call itself takes 1–4 s.
- **The sidecar was idle while this happened**: 2–5 % CPU average, under
  150 MiB. It was not overloaded; it was waiting.
- **Why.** `POST /run` was an `async` handler that called the synchronous
  graph (model calls, OCR, retrieval) directly, so the single event loop
  processed one request at a time. Ten concurrent extractions of ~12 s
  each queue into two minutes; ten concurrent retrievals of ~2.7 s (with
  rerank on the droplet) queue into a 27 s line, hence the 19 s ask p50.
  The eval suite and the API collections never saw it because they run
  one request at a time.
- **The 50-user rows repeat Week 1's known error mode**, not a Week 2 one:
  the login burst saturates MariaDB's connections (46 % HTTP errors on
  `ask`, chart-open p95 ≈ 60 s), documented in the Week 1 file with the
  deployment settings that remove it; they remain unapplied. The `extract`
  row at 50 users (3 669 requests at 24 req/s, 96 % errors) is 50 VUs
  looping on failed chart opens after failed logins.
- **Guideline hit 0 %** on every `ask` row is the questions, not the
  retriever: the load script's follow-ups are Week 1 chart questions
  ("which lab result was out of range"), for which the relevance floor
  correctly returns nothing and the answer cites facts only. The Week 2
  collection's treatment question is the retrieval proof; the load
  script's question list is extended for run 2 so `guideline_hit_pct`
  measures something.
- `brief` is unchanged in shape from Week 1: cache-hit p50 0.75 s at 10
  users (0.59 s in Week 1; the droplet is also running the sidecar now).

## Run 2 (`96a0947`): the graph on the thread pool

One change between the runs: `POST /run` hands the graph to Starlette's
thread pool (`run_in_threadpool`) instead of calling it on the event loop,
so concurrent runs overlap. Same droplet, same models, same data, 33
minutes later; `brief` and `mixed` were not re-run (the fix does not touch
them).

### Latency and errors

| VUs | Scenario | Requests | req/s | Briefs (cache hit %) | Asks (guideline hit %) | Model calls | Brief p50/p95/p99 (ms) | Cache-hit brief p50/p95 | Cold brief p50/p95 | Ask p50/p95/p99 (ms) | Chart open p50/p95 | HTTP errors | Co-Pilot errors | Summary unavailable | Verification fail |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| 10 | ask | 284 | 2.17 | 88 (100) | 88 (3.41) | 88 | 657 / 906 / 1131 | 657 / 906 | – / – | 5163 / 7342 / 8997 | 7790 / 9614 | 0 % | 0 % | 0 % | 0 % |
| 50 | ask | 428 | 2.85 | 90 (98.67) | 67 (3.45) | 59 | 3477 / 16215 / 17899 | 3304 / 10590 | 17784 / 17784 | 8027 / 9971 / 11611 | 38912 / 60097 | 29.44 % | 43.64 % | 0 % | 0 % |

### Document extraction (Week 2)

| VUs | Requests | req/s | Extractions | Extracted | Fully verified | Confidence p50 | Upload p50/p95 (ms) | Extract p50/p95/p99/max (ms) | Chart open p50/p95 | HTTP errors | Co-Pilot errors |
|---|---|---|---|---|---|---|---|---|---|---|---|
| 10 | 194 | 1.41 | 58 | 100 % | 100 % | 1 | 270 / 728 | 16730 / 19576 / 20924 / 21944 | 2400 / 9450 | 0 % | 0 % |
| 50 | 373 | 2.48 | 70 | 100 % | 100 % | 1 | 2778 / 4069 | 22775 / 29705 / 35024 / 35761 | 37957 / 53855 | 1.34 % | 2.89 % |

### CPU and memory on the target host

| VUs | Scenario | App CPU avg / peak (% of one core) | App memory avg / peak (MiB) | Sidecar CPU avg / peak | Sidecar memory avg / peak (MiB) | DB CPU avg / peak | DB memory avg / peak (MiB) | Host load1 peak |
|---|---|---|---|---|---|---|---|---|
| 10 | ask | 77.6 / 190.5 | 609 / 624 | 9.1 / 41.9 | 158 / 181 | 77.2 / 120.4 | 440 / 443 | 6.68 |
| 10 | extract | 41.0 / 127.8 | 604 / 622 | 15.3 / 39.3 | 212 / 224 | 34.2 / 102.3 | 445 / 447 | 5.19 |
| 50 | ask | 101.1 / 172.6 | 832 / 878 | 6.0 / 50.4 | 228 / 233 | 90.6 / 113.3 | 457 / 463 | 44.45 |
| 50 | extract | 92.9 / 178.2 | 819 / 883 | 21.0 / 60.3 | 278 / 330 | 81.4 / 109.7 | 469 / 472 | 40.59 |


### Reading run 2

- **Extraction works under concurrency now.** 10 users: 58 documents in two
  minutes, 100 % extracted, 100 % fully verified (every proposed value
  anchored, no row missed), p50 16.7 s, p95 19.6 s, max 21.9 s, all inside
  the 60 s budget. 50 users: 70 documents, 100 % / 100 %, p50 22.8 s, p95
  29.7 s, max 35.8 s. The sidecar itself reports p50 15.9 s / p95 19.9 s
  per run; the difference to the client's number is the PHP persist and
  the trip.
- **The sidecar's throughput ceiling on this droplet is about 30–35
  documents per minute**, and it is set by the provider's per-call latency
  (five sequential model calls per document, ~2–3 s each), not by CPU or
  memory: 21 % CPU average, 278 MiB average / 330 MiB peak against the
  768 MiB limit at 50 users. The next step for throughput is calling the
  five pages concurrently (COST_AND_LATENCY.md, bottleneck 1), which would
  roughly halve the per-document time before any hardware change.
- **Follow-ups are 3.7× faster than run 1 at 10 users** (p50 5.2 s, p95
  7.3 s; run 1: 19.1 s / 39 s) and hold at 50 users (p50 8.0 s, p95 10.0 s).
  They are still slower than Week 1's 1.4 s because the ask path now
  includes the retrieval leg: the sidecar's `retrieved` events for the run
  window show p50 1.1 s and p95 5.5 s under load (query embedding + BM25 +
  cosine + Cohere rerank, every one of them a hit and reranked), and the
  sidecar-side answer run p50 is 3.6 s. Retrieval latency under load, not
  the model, is the ask path's new floor; the query embedding and the rerank
  are two provider round trips per question that could be overlapped.
- **`guideline_hit_pct` is 3.4 %, and that is the narrator, not the
  retriever.** Every retrieval returned passages, but the metric counts
  passages *cited in the kept answer*, and for random seed charts the
  treatment questions usually cannot be answered about that patient (no
  LDL on the chart, say), so the model declines or answers from facts and
  cites no passage. The Week 2 collection's request 11 on a chart with the
  relevant value is the citation proof; for the load test the number is
  reported as what it is.
- **The 50-user error mode is still Week 1's**: 29–44 % errors on `ask`
  and 1–3 % on `extract` come from the login burst saturating MariaDB's
  connections (chart-open p95 ≈ 54–60 s), the deployment setting recorded
  in the Week 1 file. Once a VU has a session, its requests succeed:
  `summary_unavailable` and `verification_fail` are 0 % everywhere.
- **Memory headroom.** App container 830–880 MiB at 50 users (Week 1:
  800–880), sidecar under 330 MiB, database ~470 MiB: about 2.2 GiB in
  use of 3.9 GiB with the sidecar added.

## Recommendations recorded, not applied

1. Week 1's two (Apache `MaxRequestWorkers` / MariaDB `max_connections`; a
   reverse proxy with a connection queue) still stand and are still the
   only thing between 10 and 50 users.
2. Call the five page proposals concurrently inside the sidecar
   (`extractor._propose_per_page`): the per-document time would fall from
   ~16 s to ~4–5 s and the throughput ceiling would rise accordingly, at
   the cost of five simultaneous provider calls per document against the
   token-per-minute limit.
3. Overlap the query embedding and the rerank with the answer model's
   prompt build, or cache query embeddings for repeated questions; the ask
   floor is two provider round trips.
4. Add a load test to the release checklist: the eval gate and the API
   collections run one request at a time and cannot see a concurrency
   defect; this run found one that had shipped.

## Re-running

```bash
BASE_URL=https://146-190-139-37.sslip.io LOGIN_PASS=<admin password> STATS=ssh SSH_HOST=do-openemr tests/load/run-baselines.sh
python3 tests/load/summarise.py <stamp>
ssh do-openemr 'cd ~/openemr && docker compose exec -T openemr sh -c "cd /var/www/localhost/htdocs/openemr && su -s /bin/sh apache -c \"php tests/load/cleanup-documents.php 30\""'
```

Compare against run 2's tables. The numbers to watch first: extract p95
at 10 users, extract success at 10 users, ask p50 at 10 users, and the
sidecar's memory peak at 50 users (its 768 MiB limit).
