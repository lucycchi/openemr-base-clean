# COST_AND_LATENCY.md — Clinical Co-Pilot, Week 2

Latency per step from the traces, the eval results and the load runs
(§1), the bottlenecks they show (§2), the actual development spend (§3),
and the projected production cost per deployment tier with the
architectural change each tier forces (§4, added 2026-09-23; this closes
the Week 1 grader's note on per-tier architecture). Week 1's projection,
for the briefing alone and up to 100,000 users, is in
[clinical_copilot_week1/AI_COST_ANALYSIS.md](clinical_copilot_week1/AI_COST_ANALYSIS.md).

## 1. Latency per step

All numbers from the dev stack (Docker on a laptop, `gpt-4o-mini`) on
2026-09-22, the full live eval run of that day (`tests/evals/results.json` as
committed in `c4d03a1`: `git show c4d03a1:tests/evals/results.json`; the
latest live run is [results-live.json](tests/evals/results-live.json))
and the Langfuse traces of the smoke run. The droplet has slower CPU and
the same provider, so model-bound steps match and CPU-bound steps
(tesseract, index load) run about 1.5× longer there (Week 1 BASELINES).

### Chart briefing (Week 1 path; the expanded briefing below)

| Step | p50 | p95 | Notes |
|---|---|---|---|
| authorize + assemble facts (SQL) | 30 ms | 120 ms | the 31-fact chart is the p95 |
| cache lookup | 1 ms | 2 ms | |
| model call, cold | 2.4 s | 9 s | 22 sentences for the densest chart |
| verify + omission guard | < 1 ms | 1 ms | pure PHP |
| **whole briefing, cold** | **2.9 s** | **10.2 s** | 10 busiest seed charts; a cache hit is ~5 ms |

The expanded briefing (Phase 11: ranges, vitals, the prior plan, the
guideline section and its critic) adds, on a cold brief only, one
retrieval batch (0.5-0.6 s for six triggers, no embedding call) and one
critic model call per guideline card before the narration. Under load on
the dev stack ([BASELINES.md](clinical_copilot_week2/BASELINES.md) run 3,
10 users): **cold brief p50 4.2 s / p95 10.1 s, cache-hit brief p50 0.5 s /
p95 0.8 s**. Not yet measured on the droplet (it runs the pre-expansion
build until the next deploy).

### Follow-up question (Week 2 path)

| Step | p50 | p95 | Notes |
|---|---|---|---|
| `retrieve_evidence` (sidecar: embed the query, BM25 + cosine, RRF, rerank) | 1.0 s | 1.3 s | the embedding call is most of it; retrieval itself is 3–12 ms |
| model call | 0.7 s | 3.6 s | after the six-sentence cap; 7–10 s before it on the 28-fact chart |
| verify | < 1 ms | 1 ms | |
| **whole question** | **1.8 s** | **4.9 s** | |

### Document extraction (Week 2)

| Document | Pages | Model calls | Tokens | Time | Where the time goes |
|---|---|---|---|---|---|
| lab report, text layer | 5 | 5 | 4,701 | 12.1 s | one model call per page, sequential, ~2 s each; parse 6 ms |
| the same report as a scan | 5 | 5 | 4,703 | 14.2 s | + tesseract 2.4 s/page at 200 dpi (11.8 s on the droplet spike) |
| intake form | 1 | 1 | 998 | 3.6 s | |
| persist (four lab tables, provenance rows) | | | | 40–80 ms | one transaction |

Extraction p50 / p95 under load, from the load runs in
[BASELINES.md](clinical_copilot_week2/BASELINES.md) (5-page text-layer
report, 10 concurrent users):

| Where | Run | p50 | p95 | Extracted / fully verified |
|---|---|---|---|---|
| droplet (2 vCPU) | run 2, after the thread-pool fix | 16.7 s | 19.6 s | 100 % / 100 % |
| dev stack (8 cores) | run 3, expanded briefing | 13.1 s | 15.7 s | 100 % / 100 % |
| droplet, 50 users | run 2 | 22.8 s | 29.7 s | 100 % / 100 % |
| dev stack, 50 users | run 3 | 6.0 s | 15.7 s | **12.7 %**: the provider rate-limited ~40 model calls/s; failures are reported as failed documents, not errors |

The follow-up question under load: p50 5.2 s / p95 7.3 s at 10 users on the
droplet (run 2), 5.0 s / 5.9 s on the dev stack (run 3).

The file is stored before extraction starts, so the physician's upload
"completes" in ~150 ms; the extraction result and the refreshed briefing
follow asynchronously in the panel.

## 2. Bottlenecks

1. **Sequential per-page model calls dominate extraction** (≈ 2 s × pages).
   They are independent, so running the five calls concurrently would cut
   the text-layer case from ~12 s to ~3 s. Not done this week because the
   omission-driven re-ask needs the merged proposal first and the sidecar
   is single-worker on the droplet; it is the first optimisation for Phase
   10 or a larger deployment.
2. **OCR is CPU-bound and memory-bound**: 2.4 s/page and the reason for the
   5-page cap and the 768 MB container limit. A queue with a worker per
   core would let scans run without holding the request; the design keeps
   the synchronous form because the measured worst case fits the budget.
3. **Answer length was the follow-up bottleneck** until the six-sentence
   cap: an ambiguous question on a 28-fact chart produced 10–25 sentences
   in 4–10 s and, on a slow provider day, more than the 20 s per-attempt
   limit. Measured and decided in [experiments/answer-length-cap.md](clinical_copilot_week2/experiments/answer-length-cap.md).
4. **Langfuse ingestion lag** (10–30 min for spans and generations on the
   v3 API) is an observability bottleneck, not a product one; the OTLP
   migration is Phase 9.
5. **The chart briefing's p95** is the densest chart's 22-sentence
   narration (9 s of model time); the pre-warm sweep exists precisely so
   that call happens before clinic hours.

## 3. Actual development spend, Week 2

| Item | Basis | Cost |
|---|---|---|
| OpenAI, product calls on the dev stack (briefings, follow-ups) | the local audit log's `cost_usd` per request, 130 requests since 2026-09-21, 132k tokens | **$0.04** |
| OpenAI, extraction and live eval runs | ~4.7k tokens per five-page document, ~1k per intake form; ~15 full live eval runs this week at ~33k chat tokens each plus three extraction cases (~10k) per run; the answer-cap experiment (20 + 5 calls, ~60k tokens) | **≈ $0.15** (list price; the OpenAI dashboard is the bill) |
| OpenAI, `text-embedding-3-small` | the committed index (30 chunks, built once) and one query embedding per question/eval case | **< $0.01** |
| OpenAI, load baselines (requirements 8 and 9) | two matrices on 2026-09-23: ~370 extractions (~1.7 M tokens) and ~330 follow-ups | **≈ $0.40** |
| OpenAI, load baseline run 3 (expanded briefing, dev stack) | the local audit log for 2026-09-23: 718 follow-ups and 203 cold briefings, $0.73 at list price (that figure includes the Cohere reranks priced at list, see below); ~400 extractions at ~$0.001 | **≈ $0.90** |
| Cohere rerank | trial key, so billed $0. At list price ($2 per 1,000 searches) the week's reranks come to roughly $0.40-0.60 (estimated from the audit rows); `Pricing` already writes them into `cost_usd` at that rate, which is why the audit totals above include them | **$0** billed |
| Langfuse | Hobby plan | **$0** |
| Infrastructure | the same DigitalOcean droplet as Week 1 (≈ $24/month, one week's share) | **≈ $6** |
| Claude Code | development assistant, subscription | not metered per project; the Week 1 file estimates a list-price equivalent |

Total metered model spend for the week is about two dollars. Measured cost
per action at list price, from the audit rows since the expanded briefing
landed (dev stack, 2026-09-23 20:30 UTC onward):

| Action | n | Tokens avg (min-max) | Model cost avg | Reranks | Total avg |
|---|---|---|---|---|---|
| cold briefing (narration + one critic call per guideline card) | 148 | 1,417 (0-5,361) | ≈ $0.00043 | one per fired guideline trigger (seed average 0.56) | **$0.0015** |
| follow-up question | 703 | 1,690 (1,019-5,399) | $0.00054 | one when retrieval finds candidates (none recorded in this window) | **$0.00054**, **$0.0025** with a rerank |
| five-page lab report | | ≈ 4,700 | ≈ $0.001 | none | **≈ $0.001** |
| intake form | | ≈ 1,000 | ≈ $0.0002 | none | **≈ $0.0002** |
| cache-hit briefing | | 0 | $0 | none | **$0** |

## 4. Projected production cost

### 4.1 The usage model

Same physician as Week 1 ([USERS.md](USERS.md)): a primary-care day of 20
encounters, 22 clinic days a month, so **440 encounters per physician per
month**. The Week 2 assumptions, each replaceable by a measured value once
real clinics use it (§4.5):

| Parameter | Value | Basis |
|---|---|---|
| Cold briefings per encounter | 1.5 | Week 1 assumption: one at chart open, one when the chart changes during the visit; re-opens are cache hits |
| Guideline triggers fired per cold briefing | 2 | The seed charts average about 0.56 (inferred from the audit cost); a real primary-care panel (hypertension, diabetes, lipids, screening ages) fires more. Six topics exist |
| Follow-up questions per encounter | 0.45 | Week 1: 30 % of briefings get a follow-up, 1.5 questions each |
| Reranks per follow-up | 1 | Upper bound: one Cohere search whenever retrieval finds candidates |
| Documents per encounter | 0.3 | Assumption: a lab PDF before about a quarter of visits, an intake form for new or annual visits |
| Model cost per document | $0.0008 | Mix of five-page lab reports ($0.001) and intake forms ($0.0002) |
| Unit costs | §3 table | gpt-4o-mini list, `text-embedding-3-small`, Cohere `rerank-v3.5` at $0.002 per search |

That gives **$0.0080 per encounter** and **$3.53 per physician-month**.
Week 1's briefing alone was $0.00036 per encounter ($0.16 per
physician-month). The 22-fold increase is almost all reranking:

| Per encounter | Cost | Share |
|---|---|---|
| Reranks for the guideline section (1.5 cold briefs × 2 triggers × $0.002) | $0.0060 | 75 % |
| Reranks for follow-ups (0.45 × $0.002) | $0.0009 | 11 % |
| Chat model: narration, critic, follow-ups | $0.0009 | 11 % |
| Extraction | $0.0002 | 3 % |

### 4.2 Cost by tier, monthly

Three tiers, sized as the Week 1 grader asked: one clinic, a group
practice, a regional network. Model and rerank lines use §4.1;
infrastructure is priced from the load runs at typical cloud list rates
and is order-of-magnitude.

| | 1 clinic (5 physicians) | Group practice (50) | Network (500) |
|---|---|---|---|
| Encounters / month | 2,200 | 22,000 | 220,000 |
| Documents extracted / month | 660 | 6,600 | 66,000 |
| Cohere searches / month | 7,590 | 75,900 | 759,000 |
| Chat model (gpt-4o-mini) | $1.93 | $19.33 | $193 |
| Extraction (gpt-4o-mini) | $0.53 | $5.28 | $53 |
| Embeddings (one query embedding per follow-up) | < $0.01 | < $0.01 | ≈ $0.05 |
| **Rerank, as built today** | **$15.18** | **$152** | **$1,518** |
| **Model + rerank, as built** | **$17.64** | **$176** | **$1,764** |
| Model + rerank after the §4.3 changes | $4.44 | $44 | $444 |
| Infrastructure | one 2-vCPU droplet, $24 | one 8-vCPU host or two app nodes, managed database: $150-300 | app nodes behind a proxy, sidecar replicas, managed database with pooling, a queue: $400-700 |
| Observability | Langfuse Hobby | Langfuse paid tier | self-hosted or sampled |
| **Total, as built** | **≈ $42** | **≈ $330-480** | **≈ $2,200-2,500 + observability** |

Pre-warm on or off does not change the model line: the 06:00 sweep makes
the same cold briefings earlier, plus one extra per no-show (about +10 %
on the briefing rows). What it changes is the wait: the physician's first
open becomes a cache hit (0.5 s instead of 4.2 s p50). With the sweep on,
its narration calls can go through OpenAI's Batch API at half price
(§4.3).

### 4.3 The architectural change each tier forces

**1 clinic: nothing structural; turn two settings.** One droplet carries it:
the 10-user load runs complete with no errors, and five physicians are
well under that. Set `COHERE_API_KEY` to a production key (the trial key
is rate-limited; the load runs hit its 429s, and every 429 falls back to
unreranked order, which only the trace's `reranked` flag shows). Turn the
pre-warm on so the first chart of the morning is not a 4-10 s wait. The
rerank line ($15) is already the biggest model cost and the reason for the
next tier's first change.

**Group practice (50): stop paying for reranks that never change, and cap
extraction concurrency.**

- *Precompute brief-mode reranks.* The guideline section searches with a
  fixed list of trigger queries (`corpus/index/trigger_queries.json`,
  committed vectors) over a fixed corpus, so its candidates and its rerank
  order are the same for every patient. Rerank them once when the index is
  built and store the order next to the vectors. That removes 87 % of the
  rerank line ($152 → $20) with no change in what the physician sees. The
  alternative, a self-hosted `bge-reranker-base` (W2_ARCHITECTURE.md
  "Reranker"), needs about 1 GB of RAM per sidecar replica, which the
  current droplet does not have.
- *A concurrency cap and backoff on extraction.* Run 3 showed what happens
  when many documents arrive at once: five page calls per document at
  8 documents a second is ~40 model calls a second, the provider refused
  most of them, and 87 % of documents came back "failed, retry". A
  per-sidecar semaphore (for example four documents in flight) plus
  exponential backoff turns that into a queue that finishes a few minutes
  later instead of failing.
- *Infrastructure:* the 2-vCPU droplet produced 25-30 % errors at 50
  simultaneous users (database connections, not the model). Move to an
  8-vCPU host or two app nodes with a managed database, and apply the two
  Week 1 fixes (Apache worker cap, MariaDB `max_connections`).

**Network (500): take extraction and the morning sweep off the request
path, and budget the provider.**

- *An extraction queue with workers.* Upload already returns in ~150 ms
  and stores the file first; the extraction becomes a job a worker pool
  picks up, with a per-tenant rate budget. Parallel page calls inside one
  document (bottleneck 1, ~12 s → ~3 s) become safe once the pool bounds
  total concurrency.
- *Sidecar replicas behind the compose network's service name.* The sidecar
  is stateless apart from its read-only index, so it scales by replica; its
  idempotency cache moves to a shared store (the database or Redis) so a
  retried request can land on any replica.
- *Nightly pre-warm through the Batch API.* The facts hash is computable
  without the model, so the night before, the job narrates tomorrow's
  scheduled charts at half the chat price (the chat line falls from $193 to about $125 at this tier) and
  flattens the 8-9 a.m. peak.
- *Provider rate limits.* ~220,000 encounters a month is ~40 M model tokens a
  clinic day, over 100k tokens a minute at the morning peak; that needs an agreed OpenAI tier and a
  Cohere production contract, or the self-hosted reranker for follow-ups.
- *Observability sampling:* keep every error, strip and failed extraction
  trace, sample the rest; the audit row (tokens and `cost_usd` per request)
  stays the complete record.

Beyond 500 physicians, multi-tenant isolation, regional deployment and
per-tenant budgets are as described in the Week 1 analysis (§2.4 there);
Week 2 does not change them.

### 4.4 What moves the number more than user count

1. **Guideline triggers per cold briefing.** Every fired trigger is a
   rerank at $0.002, about five times the whole narration. Until the precompute
   (§4.3) lands, a chart that fires all six topics costs $0.012 per cold
   briefing.
2. **The cache-hit share**, exactly as in Week 1: a chart whose facts do not
   change between opens costs nothing the second time. Every
   `Prompt::VERSION` bump or model change makes every chart cold for a day.
3. **Documents per encounter.** Extraction is cheap per document but it is
   the one path with a burst problem (§4.3, group tier).
4. **Chart size.** The densest seed chart now carries 87 facts and 5,361
   tokens in one briefing (vs 1,417 on average); a real chart with years of
   labs sits at that end.

### 4.5 What to measure to replace the assumptions

| Assumption | Replace with | Source |
|---|---|---|
| 1.5 cold briefings per encounter | cold briefs ÷ encounters | audit rows `action=brief` with `llm_attempts≥1`, joined to `form_encounter` |
| 2 triggers per cold briefing | cards per briefing | the `copilot.brief` trace's guideline section count; `retrieved kind=brief` sidecar lines |
| 0.45 follow-ups, 1 rerank each | asks ÷ encounters; `reranked=true` share | audit rows `action=ask`; the trace's `reranked` flag |
| 0.3 documents per encounter | uploads ÷ encounters | audit rows `action=upload` |

## 5. What is measured where

| Number | Source |
|---|---|
| per-request latency and step spans | Langfuse traces (`duration_ms`, spans), `copilot response` log lines |
| per-document extraction time, calls, tokens | `copilot.documents.extract` trace and log line; `tests/evals/results.json` cases 19, 20, 22 |
| p50/p95 over the eval population | `metrics` in `results.json` |
| cost per request | `cost_usd` on the trace, the log line and the audit row (`Pricing`, list prices, overridable) |
| the bill | the OpenAI usage dashboard; Cohere's trial dashboard |
