# AI_COST_ANALYSIS.md — What the Clinical Co-Pilot cost to build, and what it costs to run

Two questions, answered from measured numbers where they exist and from
stated assumptions where they do not: what was actually spent building
this, and what it would cost to run for 100, 1,000, 10,000 and 100,000
physicians. The second answer is not `cost per token × n`: the model bill
turns out to be the smallest line at every tier, and the thing that
changes shape between tiers is the infrastructure around the model.

Where a number is already recorded elsewhere it is linked, not repeated:

| Source | What it holds |
|---|---|
| [`KEY_METRICS.md` § Cost, tracked alongside](../KEY_METRICS.md#cost-tracked-alongside) | Tokens per briefing / follow-up, and how `cost_usd` is written to every trace, log line and audit row |
| [`ARCHITECTURE.md` § Observability, question 4](../ARCHITECTURE.md#observability) | Where `cost_usd` comes from ([`Pricing.php`](../interface/modules/custom_modules/oe-module-clinical-copilot/src/Pricing.php)), list prices used, the `cost_usd=unknown` rule, sample rows |
| [`BASELINES.md`](BASELINES.md) | Load-test results at 10 and 50 users: throughput plateau, CPU/memory, the MariaDB connection ceiling — the infrastructure side of this analysis |
| [`EVALS.md`](EVALS.md) and [`tests/evals/results.json`](../tests/evals/results.json) | Token totals for each eval run (`tokens_total`) |
| [`AI_INTEGRATION_PLAN.md` § 14.2](../AI_INTEGRATION_PLAN.md) | The superseded plan's cost estimate (Opus-class model, 10k-token charts) — useful as the "what if we had built that" comparison |

## 1. Actual development spend

Development ran from 2026-09-14 to 2026-09-17. Three things cost money:
the model the product calls (OpenAI), the model that helped write it
(Claude Code), and the box it runs on.

### 1.1 OpenAI — the product's own model calls

Every request the module serves writes `tokens=` and `cost_usd=` into the
OpenEMR audit log (`log` table, event `clinical-copilot`), so the spend is
summed from the database, not reconstructed from a bill. Query used
(comments are base64 in the `log` table):

```sql
SELECT DATE(date) AS day, COUNT(*) AS requests,
  SUM(c LIKE '%from_cache=true%')            AS cache_hits,
  SUM(c REGEXP 'llm_attempts=[12]')          AS model_calls,
  SUM(CAST(REGEXP_SUBSTR(c,'(?<=tokens=)[0-9]+') AS UNSIGNED))                  AS tokens,
  ROUND(SUM(CAST(REGEXP_SUBSTR(c,'(?<=cost_usd=)[0-9.]+') AS DECIMAL(12,6))),4) AS cost_usd
FROM (SELECT date, CONVERT(FROM_BASE64(comments) USING utf8mb4) AS c
      FROM log WHERE event='clinical-copilot') t
GROUP BY DATE(date) WITH ROLLUP;
```

Results on 2026-09-17:

| Instance | Requests | Cache hits | Model calls | Tokens | Cost (USD) | What generated it |
|---|---|---|---|---|---|---|
| Deployed droplet | 1,139 | 733 | 374 (2 retried) | 290,409 | **$0.0519** | The six k6 load runs in [`BASELINES.md`](BASELINES.md) (1,097 requests), the deployed eval run, the smoke test, manual clicking |
| Local dev stack | 174 | 64 | 24 (2 retried) | 28,979 | **$0.0058** | The 2026-09-17 local eval run (10 briefings + 12 follow-ups), smoke and manual testing |
| **Traced total** | | | **398** | **319,388** | **$0.058** | |

Rows dated 2026-09-16 (42 on the droplet, 40 locally) predate the
`tokens=` / `cost_usd=` instrumentation and sum to zero; the audit row
existed but the cost field did not yet. Untraced spend from before that
point — the pre-build spike (~10 cold briefings, 600–1,400 prompt tokens
each, [`spike-results.md`](../tests/evals/spike-results.md)), the first
deployed eval run (13,496 tokens,
[`results-deployed.json`](../tests/evals/results-deployed.json)), and
prompt iteration during 2026-09-15/16 — is estimated at 100–200k tokens,
i.e. **$0.02–0.05**. Total OpenAI spend for the build is therefore on the
order of **$0.10**. The prompt-version bumps that invalidated the cache
(`Prompt::VERSION` is part of the key) are what made most of those calls
cold.

Per-call unit costs, measured over the 374 real droplet calls (this is the
basis for every projection below):

| Call | n | Tokens avg (min–max) | Cost avg | Note |
|---|---|---|---|---|
| Cold briefing (`action=brief`, `llm_attempts≥1`) | 36 | 783 (488–1,354) | **$0.000202** | Varies with chart size; the 30 seed charts are small |
| Follow-up (`action=ask`) | 338 | 776 (604–1,297) | **$0.000132** | Longer prompt (transcript), shorter completion |
| Cache-hit briefing | 733 | 0 | $0 | No model call |

Rates are gpt-4o-mini list ($0.15 / $0.60 per million input / output
tokens), applied by `Pricing` at request time.

### 1.2 Claude Code — the model that helped build it

Claude Code keeps a transcript per session under
`~/.claude/projects/<project>/`, each assistant message carrying the
API `usage` block. Summed over the 21 sessions in this project's
directory (2026-09-14 22:33 UTC → 2026-09-17 03:50 UTC, 2,155 assistant
messages) and priced at the Anthropic first-party list rates in force
in 2026-09 (cache write 1.25× input, cache read 0.1× input; 0.025× on
Fable 5.1):

| Model | Output tokens | Cache writes | Cache reads | List-price equivalent |
|---|---|---|---|---|
| claude-opus-5 | 1,069,068 | 4,456,020 | 419,982,179 | $264.58 |
| claude-sonnet-5 | 323,834 | 1,592,331 | 77,593,843 | $22.74 |
| claude-opus-4-8 | 117,090 | 180,517 | 34,382,487 | $21.25 |
| claude-fable-5-1 | 19,437 | 120,519 | 8,994,223 | $4.75 |
| **Total** | **1,529,429** | **6,349,387** | **540,952,732** | **≈ $313** |

Three caveats. This is the API-equivalent figure, not an invoice: Claude
Code was used under a subscription, so the cash cost is the subscription
share for those four days. The project directory also covers the OpenEMR
base audit ([`AUDIT.md`](../AUDIT.md)) that preceded the copilot, so the
figure is for the whole Week 1 deliverable, not the module alone. And it
shows what prompt caching does to an agentic workload: 99% of input
tokens were cache reads; the same usage uncached would have been ≈ $2,580.

### 1.3 Infrastructure and tooling

| Item | Cost |
|---|---|
| DigitalOcean droplet (Regular, 2 vCPU, 4 GB) | $24 / month; ≈ $3 prorated for the build week |
| Langfuse Cloud | Free (Hobby) tier for this volume |
| k6, Bruno, Selenium (in the dev stack) | $0 |

### 1.4 Total

| | Cash | List-price equivalent |
|---|---|---|
| OpenAI (product model calls) | ≈ $0.10 | ≈ $0.10 |
| Claude Code (development assistant) | subscription share | ≈ $313 |
| Infrastructure | ≈ $3 | ≈ $3 |

The ratio is the point: building the agent cost roughly three thousand
times more in AI than running it did, and the running cost was itself
dominated by deliberately cold load tests. That ratio is an argument for
the facts-first design — the model does very little per request, so
there was very little to pay for while iterating on it.

## 2. Projected production cost

### 2.1 The usage model

"User" means a physician using the panel as described in
[`USERS.md`](../USERS.md): a primary-care day of about 20 patients.
Assumptions, stated so they can be replaced with measured values from
metric 5 in [`KEY_METRICS.md`](../KEY_METRICS.md) once real physicians
use it:

| Parameter | Value | Basis |
|---|---|---|
| Encounters per physician per day | 20 | `USERS.md` |
| Clinic days per month | 22 | |
| Cold briefings per encounter | 1.5 | One at chart open; a second when the chart changes during the visit (new med / lab → new facts hash). Re-opens with unchanged facts are cache hits. With the morning pre-warm turned on (built 2026-09-18, off on the droplet), the first of these moves off the physician's wait into the 06:00 sweep at the same model cost, plus one extra cold briefing per scheduled patient who does not show; the per-encounter total is unchanged to within that no-show fraction. |
| Follow-up questions per encounter | 0.45 | 30% of briefings get a follow-up (`KEY_METRICS.md` target >20%; load-test "mixed" scenario 30%), 1.5 questions when they do |
| Cold briefing cost | $0.000202 | Measured, § 1.1 |
| Follow-up cost | $0.000132 | Measured, § 1.1 |
| Tokens per encounter | ≈ 1,520 | 1.5 × 783 + 0.45 × 776 |

From these, one encounter costs **$0.00036** in model calls, and one
physician-month (440 encounters) costs **$0.16**, consuming ≈ 670k tokens.

Requests per physician are what size the infrastructure, and they are
small: a chart open produces about three module requests (facts, briefing,
sometimes a follow-up) plus the OpenEMR dashboard load the module hooks
into, about 2.5 times an hour. Averaged over a clinic day that is
≈ 0.004 requests/s per physician; a clinic-start peak factor of 4 gives
≈ 0.016 req/s. [`BASELINES.md`](BASELINES.md) measured the current
2-vCPU host plateauing at ≈ 3 req/s, so one such host serves roughly
**190 physicians at peak** — after the two configuration fixes recorded
there, because at 50 simultaneous logins it fails on MariaDB connections,
not on CPU.

### 2.2 Cost by tier

Monthly, USD. Model cost is gpt-4o-mini at list. Infrastructure is sized
from the load-test plateau and priced at typical cloud list rates for the
shapes named; treat those as order-of-magnitude.

| | 100 users | 1,000 users | 10,000 users | 100,000 users |
|---|---|---|---|---|
| Encounters / month | 44k | 440k | 4.4M | 44M |
| Model calls / month | 86k | 860k | 8.6M | 86M |
| Tokens / month | 67M | 670M | 6.7B | 67B |
| **Model (gpt-4o-mini)** | **$16** | **$160** | **$1,600** | **$16,000** |
| Application hosts | 1 × 2 vCPU (with fixes) | 6 × 2 vCPU behind a proxy | ~60 × 2 vCPU, or fewer larger | ~600 × 2 vCPU across regions |
| Database | same host | managed, dedicated, pooled | managed + read replica; partitioned `log` | per-region / per-tenant clusters |
| **Infrastructure (order of magnitude)** | **$25–50** | **$400–700** | **$4k–7k** | **$40k–70k** |
| Observability (Langfuse) | free tier | paid tier, full tracing | self-hosted or sampled | self-hosted, sampled, per-tenant |
| Briefing cache rows / month | 66k | 660k | 6.6M | 66M |
| Audit rows / month | 130k | 1.3M | 13M | 130M |
| **Model share of total** | ~30% | ~25% | ~25% | ~25% |

The model line scales linearly and stays the smallest. Everything else
scales in steps, and each step is an architectural change rather than a
bigger bill.

### 2.3 Why this is not cost-per-token × n

Four things move the real number more than user count does.

**1. The cache-hit ratio is the main model-cost lever, and it is a
product property, not a tuning knob.** The briefing is cached by
`hash(facts, Prompt::VERSION, model)`. On the load tests the hit ratio
ran 80–100% because the seed charts never changed; in a clinic it is set
by how often a chart's facts change between opens. The 1.5 cold
briefings per encounter above is a guess. If the real figure is 1.0, the
model line falls by a third; if a clinic opens each chart cold at the
morning huddle *and* at the visit (facts unchanged), those are hits, not
calls. Every `Prompt::VERSION` bump or model change invalidates the whole
cache at once — a deploy on a 100k-user system is a one-day 100% miss
rate, so prompt changes should be scheduled and, at scale, the cache
pre-warmed (§ 2.4).

**2. Chart size varies 150× and the seed charts are small.** Fact
assembly cost across the seeded patients varies more than 150×
([`AUDIT.md`](../AUDIT.md)); prompt tokens per briefing ran 600–2,500
on the seed and the superseded plan estimated 8–12k tokens for a real
chart with progress notes. The verified-fact table bounds this (only
typed facts go to the model, not free text), but a production chart
with a long medication list and a year of labs could put the cold
briefing at 3–5× the measured cost. The truncation notice the omission
guard emits is the signal to watch.

**3. The model choice is a 17× multiplier, and the verifier makes it a
cheap experiment.** Swapping gpt-4o-mini for gpt-4o at list prices
multiplies the model line by ≈ 17 ($16k → $270k a month at 100k users);
the Opus-class plan in `AI_INTEGRATION_PLAN.md` would have been ≈ 50×.
Because the model only narrates fact ids under a strict schema and the
verifier strips anything ungrounded, a *smaller* or open-weight model
is also a safe experiment: the grounding failure rate (metric 1) and
omission append rate (metric 2) say directly whether it is good enough.
At 10k+ users that experiment is worth running.

**4. The box, not the model, is the bottleneck.** The load tests showed
the module's own work is ≈ 50 ms of PHP + DB on a cache hit; the OpenEMR
dashboard page around it is 7 s at 10 users and 35–46 s at 50. Every
tier above 100 users is an OpenEMR scaling exercise first. The model
is the cheapest and most elastic component in the stack.

### 2.4 Architectural changes needed at each tier

**100 users — one host, two settings.** Apply the two fixes recorded in
[`BASELINES.md`](BASELINES.md) (cap Apache `MaxRequestWorkers` at
≈ `(max_connections − 20) / 2`, or raise MariaDB `max_connections` to
≈ 400 with a matching buffer pool) and put a reverse proxy with a
connection queue in front so a clinic-start login burst degrades to
slower logins rather than 500s. Nothing in the module changes. Langfuse
Cloud traces every request. The cache table grows ~66k rows a month,
which needs no eviction yet.

**1,000 users — separate the database, add nodes, pool connections.**
The 50-VU failure mode was MariaDB connections; at this tier the
database moves to a managed instance with connection pooling
(ProxySQL or the provider's pooler) and the app tier becomes ~6
stateless nodes behind a load balancer. The module is already
stateless (sessions in OpenEMR, cache in the DB, transcript in the
browser), so nothing in it changes; the deployment does. The `/ready`
probe becomes the load balancer's health check. Two module changes
become due: a **TTL and eviction policy on `copilot_briefing_cache`**
(it has neither today — rows accumulate until a prompt bump orphans
them) and a **retention policy on the `log` table** for copilot rows,
which the HIPAA record-keeping requirement sets, not disk. Langfuse
moves to a paid tier; full tracing is still affordable and worth it.

**10,000 users — the model path leaves the request path.** Three
changes. First, **pre-warm briefings for the day's schedule** from a
nightly job over `openemr_postcalendar_events`: the facts hash is
computable without the model, so the job can find tomorrow's cold
charts and narrate them through OpenAI's Batch API at half price. That
turns most 9 a.m. cold briefings into hits, cuts peak model concurrency
by an order of magnitude, and moves ~$800 of the $1,600 model line to
~$400. Second, **decide whether the stable prompt prefix should reach
≥ 1,024 tokens**, the threshold for OpenAI's automatic prompt caching
(50% off cached input). The rules block is ≈ 270 tokens today, so the
prefix is well under it; this only pays if rules plus schema grow to
~1k tokens naturally as the prompt matures — do not pad to qualify. Third,
**observability sampling**: 8.6M model calls and 26M requests a month
is beyond what per-request Langfuse Cloud tracing costs sensibly; trace
100% of errors and strips, sample the rest, and keep the audit row (which
already carries tokens and cost) as the complete record. Rate limits
need an explicit conversation with OpenAI at this tier — average ≈ 1.5M
tokens/min with 4× peaks — and the module's single retry needs a
queue behind it so a 429 storm degrades to "summary delayed" rather
than "summary unavailable". Database: read replica for fact assembly,
partition `log` by month, archive `copilot_briefing_cache` rows older
than the retention window.

**100,000 users — multi-tenant, regional, and the model becomes a
choice.** No single OpenEMR install has 100k physicians; this tier is
a hosted service for many health systems. The changes are: **per-tenant
isolation** (separate databases or schemas — the audit row and the
cache key are already tenant-safe because they key on the OpenEMR
instance's own ids, but the deployment must not share a `log` table);
**regional deployment** for latency and data-residency; a **model-call
queue with backpressure and per-tenant budgets**, using the `cost_usd`
already written per request for attribution and caps; and a **model
evaluation programme** — at $16k/month on gpt-4o-mini and ≈ $270k on
gpt-4o, the eval suite (`tests/evals`, 15 cases, ~$0.006 a run) plus the
verifier's grounding metric make it cheap to test whether a fine-tuned
small model or an open-weight model served in-region does the ID-only
narration job. Compliance is a cost line here too: a BAA and zero-data-
retention terms with the model provider, which the design already
prepares for by never sending identifiers. Langfuse is self-hosted.

### 2.5 What to measure to replace the assumptions

Every projection above rests on four numbers the system already records.
After one week of real physician use, re-derive the table from:

| Assumption | Replace with | Source |
|---|---|---|
| 1.5 cold briefings per encounter | cold briefs ÷ encounters | audit rows with `llm_attempts≥1`, `action=brief`, joined to `form_encounter` |
| 0.45 follow-ups per encounter | `action=ask` rows ÷ encounters | audit rows |
| 783 / 776 tokens per call | `tokens=` averages | the query in § 1.1 |
| 190 physicians per host | request rate per physician at clinic start | Apache access log or Langfuse trace timestamps |

The § 1.1 query, run weekly, gives the actual model spend without a
bill. When it disagrees with this document, this document is what
changes.
