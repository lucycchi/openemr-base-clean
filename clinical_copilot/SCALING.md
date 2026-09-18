# SCALING.md — Taking the Clinical Co-Pilot (and OpenEMR) from one clinic to several hundred physicians

Written for a reader who is not a software engineer. Every technical
word is explained the first time it appears, in *italics*, and again in
the [glossary](#glossary) at the end. Numbers come from measurements
already recorded in this folder ([`BASELINES.md`](BASELINES.md),
[`AI_COST_ANALYSIS.md`](AI_COST_ANALYSIS.md)); where a number is a
guess, it says so.

This document answers two questions:

1. **How can the Co-Pilot feature serve several hundred physicians**
   (300–500, all in one clinic group, sharing one OpenEMR), and what
   should change to get there?
2. **How could OpenEMR itself — the system the Co-Pilot lives inside — be
   set up differently to handle that load**, and why?

The short version: the Co-Pilot's own code is already built in a way that
scales well, so most of the work is in how the *whole system* is deployed,
not in the feature. The first thing that breaks is not the AI at all — it
is the database running out of connections when many people log in at
once. That was measured, not guessed.

---

## 1. How the Co-Pilot works today, in one picture

When a physician opens a patient's chart in OpenEMR, the Co-Pilot panel
sends a *request* to the server. A request is one message from the
physician's browser to the server, asking it to do something — here,
"give me a briefing on this patient."

The *server* is the computer that runs OpenEMR. Today it is one rented
computer at DigitalOcean (called a *droplet*, their word for a small
virtual machine) with 2 CPU cores and 4 GB of memory. Everything —
OpenEMR, the Co-Pilot, and the database — runs on that one box.

What happens on the server, step by step:

```
Physician opens chart
        │
        ▼
1. Check who they are and whether they may see this chart   (authorization)
        │
        ▼
2. Read the chart from the DATABASE and build a list of FACTS
   (new meds, allergies, abnormal labs, new problems since last visit)
        │
        ▼
3. Look in the CACHE: "have we already written a briefing
   for exactly these facts?"
        │
   ┌────┴────────────┐
   │ yes (cache hit) │ no (cache miss)
   │                 │
   │                 ▼
   │         4. Send the facts to the MODEL (OpenAI) and ask it
   │            to write a short briefing. Takes 1–3 seconds.
   │                 │
   │                 ▼
   │         5. VERIFY every sentence against the facts;
   │            remove anything the model made up.
   │                 │
   │                 ▼
   │         6. Save the result in the CACHE.
   └────┬────────────┘
        ▼
7. Send the briefing to the physician's screen.
```

Words used above:

- **Database** — where OpenEMR keeps all patient records. Here it is
  *MariaDB*, a free relative of MySQL. Think of it as one very large,
  very organized filing cabinet that many people can open at once — but
  only a limited number at the same time (this limit matters later).
- **Facts** — a list the Co-Pilot builds *without* any AI: "medication X
  started on date Y", "lab Z was abnormal." Each fact points to the exact
  row in the database it came from.
- **Cache** — a place to keep an answer you have already computed so you
  do not compute it again. The Co-Pilot's cache is a table in the same
  database. The key is a fingerprint (a *hash*) of the facts: if the chart
  has not changed, the fingerprint is the same, so the saved briefing is
  reused and OpenAI is not called at all. A *cache hit* means "found it";
  a *cache miss* means "not there, do the work."
- **The model** — the AI language model (OpenAI's `gpt-4o-mini`) that
  turns the fact list into readable sentences. It is a service on the
  internet that OpenEMR talks to. It is *not* on our server.
- **Verifier** — a plain, non-AI check that reads what the model wrote
  and throws away any sentence that does not point at a real fact. This
  is the safety mechanism that makes the feature trustworthy.

Two properties of this design matter enormously for scaling:

1. **The feature is *stateless*.** That means the server does not need to
   remember anything about a conversation between one request and the
   next. The login is remembered by OpenEMR's normal session; the cached
   briefing lives in the database; the chat history lives in the
   physician's browser and is sent back with each question. Because of
   this, *any* copy of the server can answer *any* request. That is
   exactly what you need to run several servers side by side (§ 6).
2. **The model is only called on a cache miss.** In the load tests
   80–100% of briefing requests were cache hits. The expensive, slow,
   external part of the system is used far less than the number of
   chart opens would suggest.

---

## 2. What "several hundred physicians" means in numbers

Assume 400 physicians, each seeing about 20 patients a day
([`USERS.md`](../USERS.md)).

| Quantity | Number | Where it comes from |
|---|---|---|
| Chart opens per day | ~8,000 | 400 × 20 |
| Co-Pilot requests per chart open | ~3 | facts + briefing + sometimes a follow-up question |
| Co-Pilot requests per day | ~24,000 | |
| Spread over an 8-hour day | ~0.8 per second | 24,000 ÷ 28,800 s |
| At the morning peak (4× average) | **~3.5 per second** | clinic start, everyone opens their first charts |
| Physicians active in the same minute at peak | **~50–100** | |
| Model (OpenAI) calls per day | ~12,000 | 1.5 cold briefings + 0.45 follow-ups per encounter (see [`AI_COST_ANALYSIS.md` § 2.1](AI_COST_ANALYSIS.md#21-the-usage-model)) |
| Model cost per month | **~$65** | 400 × $0.16 per physician-month |

The last line is worth pausing on: **the AI bill for 400 physicians is
about $65 a month.** The model is the cheapest part of the whole system.
Everything that follows is about the computer and the database, not the
AI.

Now compare the peak (~3.5 requests/second, 50–100 people at once) with
what was measured on the current single droplet
([`BASELINES.md`](BASELINES.md)):

- The box tops out at **about 3 requests per second** no matter how many
  users are pushing. Above that, everything just gets slower.
- With 10 users at once, opening a chart took ~7 seconds. With 50 users at
  once, it took **35–46 seconds**.
- With 50 users logging in at the same moment, **22% of requests failed
  outright** — because the database refused new connections.

So: the current setup is right at the edge for several hundred physicians
on a quiet day, and over the edge at the morning rush. The good news is
that every one of those limits has a well-understood fix.

---

## 3. Where it breaks first, and why (ranked)

### 3a. The database runs out of connections — breaks first

**What the physician sees:** an error page, or "The Co-Pilot hit an
internal error," at 8:00 a.m. when everyone logs in.

**Why:** every request from a browser needs a *connection* to the
database — think of it as a phone line between the web server and the
filing cabinet. MariaDB is set up, by default, to allow **151** lines at
once. The web server (Apache) is allowed to handle more requests at once
than that. When 50 people log in together, OpenEMR opens more lines than
the database has, and the 152nd caller gets a busy signal. This was seen
directly in the logs: `Too many connections`.

This is a *configuration* problem, not a code problem: two numbers in two
settings files disagree with each other.

### 3b. The one box is out of CPU — breaks second

**What the physician sees:** everything is slow. Chart open goes from 7
seconds to 40 seconds.

**Why:** the droplet has 2 CPU cores, and both the web server and the
database run on it. In the load test, each was using about one full core.
The *load average* (a measure of how many things are waiting their turn
for a CPU) reached 47 on a 2-core machine — meaning roughly 45 pieces of
work were queued at any moment. The Co-Pilot's own work is small (~50 ms
of computing on a cache hit); the heavy part is OpenEMR's patient
dashboard page, which the physician must load to reach the panel at all.

### 3c. OpenAI is slow, rate-limited, or down — breaks occasionally

**What the physician sees:** "AI summary unavailable" (the facts table is
still shown — the design never hides the data), or a briefing that took
10+ seconds.

**Why:** the model is an outside service. OpenAI applies a *rate limit* —
a cap on how many requests per minute one account may send. If we exceed
it, they answer with an error code (`429`, "too many requests"). Today the
Co-Pilot tries once more after a short pause and then gives up, all within
a 25-second budget. At 400 physicians the morning rush could produce a
burst of cold briefings that trips that cap. Outages on OpenAI's side
have the same effect.

### 3d. The cache table grows forever — breaks slowly

**What the physician sees:** nothing, for a long time. Then the database
gets bigger and slower, and backups take longer.

**Why:** every new briefing is saved in the cache table and never removed.
At 400 physicians that is roughly **260,000 new rows a month**. Each row
is small, but nothing ever deletes them, and each time the prompt is
improved (a new `Prompt::VERSION`) every old row becomes useless but
stays. There is also an *audit log* — OpenEMR's permanent record of who
looked at what — which gains ~500,000 rows a month from the Co-Pilot
alone. That one must be kept for legal reasons, but should be archived,
not left in the live table.

### 3e. The morning pre-warm assumes one machine — breaks only when we add servers

**What the physician sees:** nothing today. This is a future trap.

**Why:** the Co-Pilot has a built-in "pre-warm" job (turned off on the
droplet at the moment): at 6 a.m. it looks at the day's appointment
schedule and writes the briefing for every scheduled patient in advance,
so that at 9 a.m. the physician gets an instant cache hit. To stop two
copies of the job running at once, it uses a *file lock* — a marker on
the machine's disk that says "I am running." That works perfectly on one
machine. If there are three servers, each has its own disk, so each would
think it is the only one running. When we add servers (§ 6, step 3), the
lock must move into the shared database.

---

## 4. Recommended changes to the Co-Pilot, in order

Ordered from cheapest and most urgent to largest. For each: what to do,
why, what it costs, and what it does *not* require changing.

### Change 1 — Make the connection numbers agree (settings only; do this first)

**What:** either tell the web server to handle fewer requests at once
(so it can never ask for more database lines than exist), or raise the
database's limit to ~400 and give it the memory to match. The exact
numbers are recorded in [`BASELINES.md` § Recommendations](BASELINES.md#recommendations-recorded-not-applied).

**Why:** this alone removes the 22% error rate at login time. It turns
"crash" into "wait a little longer."

**Cost:** zero. Two settings.

**Code change:** none.

### Change 2 — Turn on the morning pre-warm

**What:** enable the scheduled 6 a.m. job (already built and tested, see
[`docs/designs/copilot-morning-prewarm.md`](../docs/designs/copilot-morning-prewarm.md)).
It reads the day's appointments, builds each patient's facts as the
scheduled provider would see them, and writes the briefing into the
cache.

**Why:** the slow, external, error-prone step (the OpenAI call) moves from
9 a.m. — when 100 physicians are waiting — to 6 a.m., when nobody is. At
chart open, most briefings become instant cache hits. This also flattens
the OpenAI burst (3c): instead of 100 calls in a minute at clinic start,
the job makes ~8,000 calls spread over an hour or two. Total model cost
is unchanged (same number of briefings, just earlier), plus a small
amount of waste for patients who do not show up.

**Cost:** zero in money. One scheduled task on the server (a *cron* job —
the standard way to tell a Linux computer "run this every day at 6").

**Code change:** none. Note the reservation in 3e: this is fine on one
server, and needs the lock moved to the database before there are
several.

### Change 3 — Give the cache an expiry date (a *TTL*)

**What:** delete cache rows older than, say, 90 days, and delete every
row belonging to an old prompt version whenever the prompt is updated.
TTL stands for "time to live" — how long a saved thing is allowed to
stay before it is thrown away.

**Why:** stops 3d. At 400 physicians the table would otherwise reach
~3 million rows a year, all of them dead weight once the chart changes.
Also: put a retention rule on the Co-Pilot's audit-log rows (keep them,
but move rows older than the legal retention period to cold storage).

**Cost:** small: a nightly cleanup task, a day of engineering.

**Code change:** small, inside the module (the cache class has no
expiry logic today).

### Change 4 — Put a queue in front of the model call

**What:** when OpenAI says "too many requests," instead of giving up after
one retry, place the briefing request in a *queue* — a waiting line —
and let a background worker send it when the cap allows. The panel says
"summary is on its way" and refreshes when it is ready. The physician
still sees the facts table immediately, as today.

**Why:** at 400 physicians the morning burst could exceed OpenAI's
per-minute cap even with pre-warm on (a chart that changed overnight,
walk-ins, add-ons). Today that surfaces as "summary unavailable." A queue
turns "unavailable" into "delayed by 20 seconds," which is a much better
experience and loses no data. It also means an OpenAI outage of a few
minutes is absorbed quietly.

**Cost:** moderate: a queue needs a place to live (the database is fine
at this size; *Redis*, a very fast in-memory data store, is the usual
choice later) and a small worker process. A few days of engineering.

**Code change:** moderate, in the module's LLM client and panel. This is
the largest single change on the list and can wait until the others are
done and pre-warm has been measured — if the pre-warm hit rate is high,
the burst may never trip the cap.

### Change 5 — Sample the tracing

**What:** the Co-Pilot sends a detailed *trace* (a step-by-step timing
record) of every request to Langfuse, an outside monitoring service.
At 24,000 requests a day, send 100% of errors and stripped sentences,
but only a fraction (say 10%) of the ordinary successful ones.

**Why:** cost and noise. The complete record already exists in OpenEMR's
own audit log (which carries token counts and cost), so full tracing of
every healthy request is paying twice for the same information.

**Cost:** negative — it saves money.

**Code change:** small.

### What does *not* need to change

- **The safety design.** Facts-first assembly, the verifier, the omission
  guard, and authorization enforced in the tool layer are all independent
  of how many users there are. A briefing for the 400th physician is
  checked exactly as strictly as the first.
- **The stateless request handling.** Nothing in the module stores
  per-user state on the server, so it will run unchanged on one server or
  ten.
- **The model choice.** `gpt-4o-mini` at ~$65/month for 400 physicians is
  not a cost problem. Switching to a bigger model would multiply that by
  ~17 for no verified gain; the verifier makes a *smaller* model an
  equally safe experiment if cost ever matters.

---

## 5. Summary table: what breaks, what fixes it

| Rank | What breaks | Physician sees | Fix | Effort | Code change? |
|---|---|---|---|---|---|
| 1 | Database connection cap (151) | Errors at login rush | Change 1: align the two settings | Minutes | No |
| 2 | One 2-CPU box saturates at ~3 req/s | Everything slow (40 s chart open) | § 6 steps 1–3: bigger box, then separate DB, then more app servers | Days | No |
| 3 | OpenAI rate limit / outage | "AI summary unavailable" | Change 2 (pre-warm), then Change 4 (queue) | Hours, then days | No, then moderate |
| 4 | Cache and audit tables grow forever | Slow DB, long backups, months later | Change 3: TTL + retention | A day | Small |
| 5 | Pre-warm file lock is per-machine | Duplicate 6 a.m. runs once there are several servers | Move lock into DB (with § 6 step 3) | A day | Small |
| — | Tracing volume | Monitoring bill | Change 5: sampling | Hours | Small |

---

## 6. How OpenEMR itself could be deployed differently

The Co-Pilot cannot be faster or more reliable than the OpenEMR page it
sits inside. Today's deployment is one droplet running three
*containers* (a container is a packaged, self-contained copy of a program
and everything it needs, so it runs the same anywhere): one for OpenEMR
(web server + PHP), one for MariaDB, and the Co-Pilot inside the OpenEMR
one. This is the simplest possible layout and the right one for a demo.
Here is how to grow it, one step at a time, with the reason for each
step and when it becomes worth taking.

### Today

```
┌──────────────── one droplet (2 CPU, 4 GB) ────────────────┐
│  ┌──────────────────┐        ┌──────────────────┐         │
│  │ OpenEMR + PHP    │◄──────►│ MariaDB          │         │
│  │ + Co-Pilot       │        │ (patient data,   │         │
│  │ (Apache)         │        │  cache, audit)   │         │
│  └──────────────────┘        └──────────────────┘         │
└───────────────────────────────────────────────────────────┘
          ▲                                     │
   physicians' browsers                    OpenAI (internet)
```

Measured limit: ~3 requests/second; fails at 50 simultaneous logins.
Fine for a demo and for a single small clinic (under ~50 physicians).

### Step 1 — A bigger box (vertical scaling)

*Vertical scaling* means making the one computer more powerful rather
than adding more computers.

**What:** replace the 2-CPU/4 GB droplet with 4–8 CPUs and 8–16 GB.
Apply Change 1 at the same time.

**Why:** the load test showed CPU, not memory or disk, was the wall. A
4-CPU box should roughly double the plateau to ~6 requests/second, which
covers the 3.5/second morning peak for 400 physicians with room to
spare. It is the cheapest and least risky step: nothing about the
software changes, only the size of the machine it runs on.

**When:** immediately, before the first few hundred physicians arrive.

**Cost:** roughly $50–100/month instead of ~$25.

**Limit:** you can only go so big, and it is still one machine — if it
fails, everyone is down. The next steps address that.

### Step 2 — Move the database to its own server

**What:** run MariaDB on a separate machine — ideally a *managed database*
from the cloud provider (they run it, patch it, back it up, and monitor
it; you just connect to it). Put a *connection pooler* in front of it
(ProxySQL is a common one): a middleman that keeps a fixed set of open
lines to the database and lets many web requests share them, so the
"151 connections" problem cannot recur no matter how many web servers
there are.

**Why:** in the load test the database and the web server were each
eating a full core on the same 2-core box; they were competing with each
other. Separating them means each has the whole machine. It also makes
step 3 possible: once the database is somewhere else, you can have
several web servers all pointing at it. And managed databases come with
automated backups and *point-in-time recovery* (restore to any minute in
the last week) — which for patient records is not optional.

**When:** as soon as there is a real clinic on it, for the backups alone.

**Cost:** roughly $60–200/month for a managed MariaDB/MySQL of a suitable
size.

### Step 3 — Several identical web servers behind a load balancer (horizontal scaling)

*Horizontal scaling* means adding more computers that each do the same
job.

**What:** run two or three copies of the OpenEMR container on separate
machines, all talking to the one database from step 2. Put a *load
balancer* in front — a traffic director that receives every request from
the physicians and hands it to whichever server is least busy. It also
checks each server's health (the Co-Pilot's `/ready` endpoint already
exists for exactly this) and stops sending traffic to one that is sick.

Two things must be shared, not local, for this to work:

1. **Sessions.** When a physician logs in, OpenEMR remembers "this browser
   is Dr. X" in a *session*. By default that memory is on the local disk
   of the server that handled the login. If the next request lands on a
   different server, that server does not know Dr. X and asks them to log
   in again. The fix is to keep sessions in a shared store; OpenEMR
   already ships a *Redis* session handler for this purpose
   (`src/Common/Session/Predis/`). Redis is a very fast, small,
   in-memory data store, ideal for things like "who is logged in."
2. **The pre-warm lock** (3e) moves from a file to the database.

Nothing in the Co-Pilot's request path needs to change, because it is
stateless (§ 1).

```
  physicians' browsers
          │
          ▼
   ┌─────────────┐
   │ load        │  checks /ready on each server
   │ balancer    │
   └──┬────┬────┬┘
      ▼    ▼    ▼
   ┌────┐┌────┐┌────┐      ┌────────┐
   │app ││app ││app │◄────►│ Redis  │  sessions, (later) queue
   │ 1  ││ 2  ││ 3  │      └────────┘
   └──┬─┘└──┬─┘└──┬─┘
      └─────┼─────┘
            ▼
     ┌─────────────┐
     │ connection  │
     │ pooler      │
     └──────┬──────┘
            ▼
     ┌─────────────┐
     │ managed     │  automated backups,
     │ MariaDB     │  point-in-time recovery
     └─────────────┘
            │
            ▼ (only on cache miss / pre-warm)
         OpenAI
```

**Why:** two reasons. Capacity — three 2-CPU servers give ~9
requests/second, three times today's plateau. And *resilience* — if one
server dies, the load balancer routes around it and nobody notices; you
can also update one server at a time with no downtime. For a clinical
system used all day by 400 people, "one machine, one failure, everyone
down" is the thing to eliminate.

**When:** once there are more than roughly 150–200 physicians, or as soon
as downtime becomes unacceptable (which for a clinic is the first day).

**Cost:** roughly $150–300/month for three app servers plus a small
load balancer and Redis. Total system at this step, all-in with the
managed database: about **$400–700/month**, consistent with the 1,000-user
tier in [`AI_COST_ANALYSIS.md` § 2.2](AI_COST_ANALYSIS.md#22-cost-by-tier).
For 400 physicians that is roughly **$1–2 per physician per month** for
the whole platform, and the AI part of that is ~$0.16.

### Step 4 — Housekeeping that becomes mandatory at this size

Not a new architecture, but things that a small demo can skip and a
400-physician clinic cannot:

- **A read replica.** A second, read-only copy of the database that is
  kept up to date automatically. Reports, the pre-warm job, and the
  Co-Pilot's fact assembly (all reads) can use it, leaving the main
  database free for writes. Worth it when reporting or the 6 a.m. sweep
  starts slowing down the working day.
- **Log-table hygiene.** OpenEMR's audit log is one enormous table.
  Partition it by month (split it into monthly chunks the database can
  search separately) and archive old months. This is where Change 3's
  retention rule lands.
- **Monitoring of the whole system**, not just the Co-Pilot: CPU, memory,
  database connections in use, queue depth, and the three Co-Pilot alerts
  already defined in [`ALERTS.md`](ALERTS.md). The load-test finding
  (connections exhausted) would have been a five-minute alert instead of
  a 22% error rate.
- **Deployment automation.** Today `docker/vps/deploy.sh` rebuilds the
  one droplet. With three servers, deploys need to roll one server at a
  time, run the health check, and continue — a small script now, but the
  thing that makes zero-downtime updates real.

### A note on what *not* to do yet

- **Kubernetes** (a system for running and automatically managing many
  containers across many machines) is the standard answer at thousands of
  users. At 400 it is more machinery than the problem needs; three
  servers and a load balancer are simpler to understand and run. Revisit
  at the 1,000+ tier.
- **Splitting the Co-Pilot into its own service** on its own servers.
  Tempting, but the Co-Pilot needs OpenEMR's session, authorization rules,
  and database anyway, and its own compute cost is tiny (~50 ms per
  request). Separating it would add a network hop and a second thing to
  deploy for no measured gain.
- **A bigger AI model.** See § 4, "what does not need to change."

---

## 7. What stays the same at every size

It is worth stating plainly, because it is the reason the Co-Pilot can be
scaled by changing deployment rather than by rewriting it:

- The model never sees, and can never invent, a clinical fact. The fact
  list is built by ordinary code from the database.
- Every sentence is verified against that list before it reaches a
  physician. This check runs per request, costs a few milliseconds, and
  has no shared state — so it scales linearly with no coordination.
- Authorization is enforced inside the code path that reads the chart,
  not by hiding a menu item. A physician who may not see a chart gets a
  refusal from every server, every time.
- No patient identifiers leave the server for OpenAI or Langfuse; the
  model receives fact ids and values, not names or dates of birth.
- Every request carries a correlation id — a unique tag — into the logs,
  the audit table, and the trace, so a problem on any one of several
  servers can still be followed end to end.

---

## Glossary

Plain-language definitions of every technical term used above,
alphabetical.

- **Audit log** — OpenEMR's permanent record of who looked at or changed
  what, and when. Required for medical-records compliance. Kept in a
  database table called `log`.
- **Cache** — a place to store an answer you already worked out so you can
  reuse it instead of working it out again. The Co-Pilot caches finished
  briefings.
- **Cache hit / cache miss** — hit: the answer was in the cache and was
  reused. Miss: it was not, so the work (here, an OpenAI call) had to be
  done.
- **Connection (database)** — an open line between a program and the
  database. Each has a cost, and the database allows only a fixed number
  at once (151 by default in MariaDB).
- **Connection pooler** — a middleman that holds a fixed number of open
  database lines and lets many programs share them, so the limit is never
  hit. ProxySQL is a common one for MariaDB/MySQL.
- **Container** — a packaged, self-contained copy of a program and
  everything it needs to run. Docker is the tool that builds and runs
  them. Containers make "runs on my machine" and "runs on the server"
  the same thing.
- **CPU core** — one unit of computing power. A 2-core machine can do two
  things at literally the same time; everything else waits its turn.
- **Cron** — the built-in Linux scheduler: "run this program every day at
  6:00 a.m."
- **Droplet** — DigitalOcean's name for a rented virtual machine.
- **Facts** — in this feature, the typed list of clinical items (new
  medication, abnormal lab, new allergy…) built from the database by
  ordinary code, each pointing to its source row. The model only ever
  refers to facts by id.
- **File lock** — a marker on a machine's disk that a program sets to say
  "I am running; do not start another copy." Works on one machine only.
- **Hash** — a short fingerprint computed from some data. The same data
  always gives the same fingerprint; different data gives a different
  one. The Co-Pilot uses a hash of the facts as the cache key.
- **Horizontal scaling** — adding more machines that each do the same job.
- **Langfuse** — an outside service the Co-Pilot sends timing and cost
  records (traces) to, so engineers can see what each request did.
- **Load average** — a Linux number meaning roughly "how many pieces of
  work are running or waiting for a CPU right now." Above the number of
  cores means things are queuing.
- **Load balancer** — a traffic director that receives all incoming
  requests and spreads them across several identical servers, skipping
  any that fail a health check.
- **Managed database** — a database run by the cloud provider (they
  install, patch, back up, and monitor it). You connect to it; you do not
  administer it.
- **MariaDB** — the database program OpenEMR uses; a free relative of
  MySQL.
- **Model (language model)** — the AI (here OpenAI's `gpt-4o-mini`) that
  writes prose. Called over the internet; not on our server.
- **Partition (a table)** — split a very large database table into chunks
  (e.g., one per month) so searches and clean-ups only touch the chunk
  they need.
- **Point-in-time recovery** — the ability to restore a database to how it
  was at any chosen minute in the recent past. Standard with managed
  databases.
- **Pre-warm** — computing and caching the day's briefings early in the
  morning, before physicians arrive, so chart opens are cache hits.
- **Queue** — a waiting line for work. Requests are added at one end and
  a worker takes them from the other when it has capacity. Turns
  "overloaded, failed" into "delayed."
- **Rate limit** — a cap an outside service (OpenAI) places on how many
  requests per minute one customer may send. Exceeding it returns error
  `429`.
- **Read replica** — a second, automatically synchronized, read-only copy
  of the database, used to take reporting and other read-heavy work off
  the main one.
- **Redis** — a very fast data store that keeps everything in memory.
  Used for things many servers need to share quickly: login sessions,
  queues, locks.
- **Request** — one message from a browser to the server asking it to do
  something and reply.
- **Request path** — the sequence of steps the server runs while the
  physician is waiting. Moving work "off the request path" (like
  pre-warm) means the physician no longer waits for it.
- **Requests per second (req/s)** — how many requests a server can finish
  each second. The measured ceiling of today's droplet is ~3.
- **Resilience** — the system keeps working when one part fails.
- **Retention policy** — a rule for how long records are kept before being
  archived or deleted.
- **Server** — the computer that runs the application and answers
  requests.
- **Session** — the server's memory that "this browser is Dr. X, logged in
  at 8:02." Must be shared across servers once there is more than one.
- **Stateless** — a program that does not need to remember anything
  between one request and the next. Any copy can handle any request,
  which is what makes horizontal scaling easy.
- **Trace** — a step-by-step timing record of one request, sent to
  Langfuse.
- **TTL (time to live)** — how long a cached item is allowed to stay
  before it is automatically thrown away.
- **Verifier** — the non-AI check that reads the model's sentences and
  removes any that do not point at a real fact, or that state a number or
  date not in that fact.
- **Vertical scaling** — making the one machine bigger (more CPU, more
  memory).
- **`429`** — the error code an online service returns for "too many
  requests; slow down."
