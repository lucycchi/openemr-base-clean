# COST_AND_LATENCY.md — Clinical Co-Pilot, Week 2

First version (2026-09-23, Phase 8). Latency per step from the traces and
the eval results, the bottlenecks they show, and the actual development
spend so far. The projected cost per deployment tier, with the
architectural changes each tier needs, is the Thursday addition
(DESIGN.md task 9.2, the Week 1 grader's note); Week 1's projection is in
[clinical_copilot_week1/AI_COST_ANALYSIS.md](clinical_copilot_week1/AI_COST_ANALYSIS.md).

## 1. Latency per step

All numbers from the dev stack (Docker on a laptop, `gpt-4o-mini`) on
2026-09-22, the last full live eval run ([tests/evals/results.json](tests/evals/results.json))
and the Langfuse traces of the smoke run. The droplet has slower CPU and
the same provider, so model-bound steps match and CPU-bound steps
(tesseract, index load) run about 1.5× longer there (Week 1 BASELINES).

### Chart briefing (Week 1 path, unchanged)

| Step | p50 | p95 | Notes |
|---|---|---|---|
| authorize + assemble facts (SQL) | 30 ms | 120 ms | the 31-fact chart is the p95 |
| cache lookup | 1 ms | 2 ms | |
| model call, cold | 2.4 s | 9 s | 22 sentences for the densest chart |
| verify + omission guard | < 1 ms | 1 ms | pure PHP |
| **whole briefing, cold** | **2.9 s** | **10.2 s** | 10 busiest seed charts; a cache hit is ~5 ms |

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
| Cohere rerank | trial key; on the droplet since 2026-09-23 (one rerank per follow-up, trial quota) | **$0** |
| Langfuse | Hobby plan | **$0** |
| Infrastructure | the same DigitalOcean droplet as Week 1 (≈ $24/month, one week's share) | **≈ $6** |
| Claude Code | development assistant, subscription | not metered per project; the Week 1 file estimates a list-price equivalent |

Total metered spend for the week is under a dollar; the product's model
cost per action at list price is ≈ $0.001 per briefing, ≈ $0.0005 per
question, ≈ $0.001 per five-page document. Which is why the per-tier
projection (Thursday) is about request volume, the pre-warm's cache-hit
share and the extraction queue, not about token price.

## 4. What is measured where

| Number | Source |
|---|---|
| per-request latency and step spans | Langfuse traces (`duration_ms`, spans), `copilot response` log lines |
| per-document extraction time, calls, tokens | `copilot.documents.extract` trace and log line; `tests/evals/results.json` cases 19, 20, 22 |
| p50/p95 over the eval population | `metrics` in `results.json` |
| cost per request | `cost_usd` on the trace, the log line and the audit row (`Pricing`, list prices, overridable) |
| the bill | the OpenAI usage dashboard; Cohere's trial dashboard |
