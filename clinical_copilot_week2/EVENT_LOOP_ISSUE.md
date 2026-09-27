# The event loop issue, and the extraction capacity fix that followed

A reviewer asked for an investigation of concurrency and reliability in the
Clinical Co-Pilot's Python sidecar. This document covers four things:

- what the code did before the change
- what went wrong under load, and why
- what was changed, and what the tests and load tests show
- what is still not solved

Every technical term is defined the first time it appears, and again in the
[glossary](#glossary) at the end.

Date: 2026-09-26. Branch: `dashboard-migration`, on top of `32098f9`. The
changes described here are not yet committed and not yet deployed: the
sidecar image must be rebuilt before the droplet or the dev stack runs them.

---

## Contents

1. [Background: what the sidecar is and how a request reaches it](#1-background)
2. [The first problem: a blocked event loop](#2-the-first-problem-a-blocked-event-loop)
3. [The first fix: the thread pool](#3-the-first-fix-the-thread-pool)
4. [The second problem: too much work sent to the provider](#4-the-second-problem-too-much-work-sent-to-the-provider)
5. [Tracing one extraction request](#5-tracing-one-extraction-request)
6. [What was changed](#6-what-was-changed)
7. [What the user sees when the service is full](#7-what-the-user-sees-when-the-service-is-full)
8. [Deadlines, retries, and work that outlives its request](#8-deadlines-retries-and-work-that-outlives-its-request)
9. [Measuring document success separately from HTTP success](#9-measuring-document-success-separately-from-http-success)
10. [Evidence: tests and load tests that were actually run](#10-evidence-tests-and-load-tests-that-were-actually-run)
11. [Tests proposed but not run](#11-tests-proposed-but-not-run)
12. [What is still not solved](#12-what-is-still-not-solved)
13. [Interview answers in plain words](#13-interview-answers-in-plain-words)
14. [Files changed](#14-files-changed)
15. [Glossary](#glossary)

---

## 1. Background

### The two services

The Clinical Co-Pilot has two parts that talk to each other:

- **OpenEMR (PHP).** It owns the patient chart, the logins, the permission
  checks, file storage and the user interface panel.
- **The sidecar (Python).** It is a separate small web service running in
  its own Docker container. A **container** is an isolated package holding
  a program and everything it needs to run. The sidecar does the work PHP
  is poorly suited to:
  - reading PDF files
  - running **OCR**, which stands for optical character recognition: turning
    a picture of text into characters, here with a program called
    tesseract
  - asking the language model to propose values from each page
  - **anchoring**: proving that each proposed value is actually printed on
    the page, by finding it in the same row as its test name and unit

When a clinician uploads a lab report and presses Extract, this happens:

```
browser -> PHP (documents.php, DocumentController)
        -> HTTP POST to the sidecar's /run endpoint, carrying the PDF
        -> sidecar parses, calls the model once per page, anchors
        -> sidecar answers with the extraction (or a failure code)
        -> PHP saves the result and shows it in the panel
```

PHP waits up to **60 seconds** for the sidecar's answer
(`SidecarClient::TIMEOUT_S`). After that, it gives up and tells the user
the file is stored and can be retried.

### How the sidecar serves requests

The sidecar is written with **FastAPI**, a Python web framework, and is run
by **uvicorn**, a web server program. It runs as **one process**: one
running copy of the program, with its own memory. The Dockerfile's start
command has no `--workers` option, so there is only ever one copy.

FastAPI and uvicorn are built around an **event loop**. Understanding the
event loop is the key to everything below.

**The event loop.** An event loop is one thread that serves many requests
by taking turns between them. A **thread** is a single line of execution
inside a process; one process can have several threads running side by
side. The loop runs one piece of work until that work says "I am waiting
for something" (for example, for data to arrive over the network). Then it
switches to another request that is ready to continue.

It works like a single receptionist handling many phone lines. The
receptionist can put caller A on hold while waiting for a file and talk to
caller B, then return to A. But if one caller keeps the receptionist busy
for a full minute without pausing, every other line waits.

**`async def` and `await`.** In Python, a function written `async def` is
one the event loop may pause. Each `await` inside it marks a point where
the function says "I am waiting; serve someone else meanwhile". Code
between two `await`s runs without interruption and holds the loop the
whole time.

**Blocking.** Code *blocks* when it makes the thread wait (for the network,
for a file, for a long calculation) *without* handing control back to the
loop. Ordinary Python code, and most libraries, block. Blocking inside an
`async def` handler freezes every request on that process.

---

## 2. The first problem: a blocked event loop

The sidecar's `/run` handler is written `async def`, but the actual work
(the **graph**, described below) is ordinary synchronous Python. It runs
straight through: it parses the PDF, calls the model five times in a row
(once per page, each call waiting a few seconds on the network), and
anchors the results.

**The graph.** The sidecar uses LangGraph, a library for arranging work as
steps ("nodes") connected by rules ("edges"). Here a supervisor step
decides which worker runs: the extractor, the guideline retriever, or the
critic. `graph.run(...)` runs the whole thing from start to finish and
returns the result.

When `graph.run` was called directly inside the `async def` handler, the
event loop was held for the entire extraction, often 12 seconds or more.
No other request could be served during that time, not even a health
check.

The first load test (2026-09-23, recorded in [BASELINES.md](BASELINES.md))
showed the effect:

- ten extractions sent at the same moment did not run side by side; they
  ran one after another, so the later ones waited for all the earlier ones
- ten concurrent 12-second extractions turned into 60-second timeouts
- ten 2.7-second guideline retrievals turned into a 19-second median wait

A **load test** sends many requests at once, the way many simultaneous
users would, to see how a system behaves under pressure. The **median**,
also written **p50**, is the middle value when all measurements are
sorted: half were faster, half slower. **p95** is the value that 95 % of
measurements were faster than, which describes the slow tail.

## 3. The first fix: the thread pool

The fix was one line in [app.py](../interface/modules/custom_modules/oe-module-clinical-copilot/sidecar/copilot_sidecar/app.py):

```python
state = await run_in_threadpool(graph.run, ...)
```

**Thread pool.** A thread pool is a fixed set of spare threads kept ready
to run blocking work. `run_in_threadpool` (from Starlette, the toolkit
FastAPI is built on) hands `graph.run` to one of those threads. The
`await` tells the event loop "pause this request until that thread is
done, and serve others meanwhile".

**Why it helped.** Most of an extraction's time is spent waiting on the
network for the model provider. Several threads can all wait at the same
time, so ten extractions now overlapped instead of queueing. The second
load test confirmed it: extraction at 10 users went from timeouts to
100 % success.

**Verified before changing anything:** the current code does use
`await run_in_threadpool(graph.run, ...)`. That first fix is in place and
correct. The problems below are the ones that remained after it.

---

## 4. The second problem: too much work sent to the provider

### What the load test reported

A later 50-user load test (run 3, 2026-09-23 21:05 UTC,
`tests/load/results/20260923T2105Z-50vu-extract.txt`) reported:

| Measure | Value |
|---|---|
| HTTP requests | 1,066 |
| HTTP failures | **0 %** |
| Co-Pilot errors | 0 % |
| Extractions attempted | 322 |
| Documents actually extracted | **12.73 %** |
| Sidecar log lines with `model_error` in the window | 281 |

**HTTP status codes** are the three-digit numbers a web server sends with
every answer. `200` means "OK, here is your answer". `4xx` means the
request was wrong; `5xx` means the server failed.

The report attributed the failures to the provider's rate limit, and that
was correct. But the numbers expose a second, deeper problem: **every one
of those failed documents came back inside an HTTP 200.** The sidecar
reports a failed document as a document with `status: "failed"` inside a
successful response. That is deliberate, so that one bad file in a batch
does not sink the others. The consequence is that "0 % HTTP errors" said
nothing about whether documents were extracted.

### Why removing the event-loop bottleneck exposed the next one

Once the event loop stopped being the bottleneck, nothing else limited how
much work the sidecar accepted:

1. **Every request went straight to the thread pool.** Starlette's thread
   pool allows up to **40** threads by default. With 50 users uploading,
   about 40 extractions ran at the same moment.
2. **Each extraction calls the model once per page, one page after
   another.** The test document has 5 pages, so it makes 5 calls. With 40
   extractions running, about 40 model calls were in flight at once, all
   the time.
3. **The provider has a rate limit.** A **rate limit** is the provider's
   cap on how many requests (and how much text) one account may send per
   minute. Above it, the provider refuses the call with HTTP **429 Too
   Many Requests**. This is called **throttling**.
4. **The retry budget was spent immediately.** The OpenAI **SDK** (software
   development kit: the provider's Python library that makes the HTTP
   calls) was set to retry once (`max_retries=1`). One retry, a fraction of
   a second later, meets the same full quota and fails again. The page
   fails, which fails the whole document with `failure_reason:
   "model_error"`.
5. **Failing fast made it worse.** A failed extraction returned in about
   one second, so each simulated user immediately uploaded the next
   document. The failures generated more load, which generated more
   failures.

In short: the thread pool fix let the sidecar accept far more simultaneous
work than the provider would serve. The bottleneck moved from our event
loop to the provider's quota, and nothing in the sidecar was aware of that
limit.

### Three more problems found while verifying

- **The health check shared the thread pool with extraction.** `/health`
  and `/ready` are written as plain `def` handlers, not `async def`.
  FastAPI runs plain `def` handlers on the same 40-thread pool. If
  extractions occupy every thread, the health check waits behind them.
  Docker uses `/health` to decide whether the container is alive; a slow
  answer there can get a healthy but busy container restarted.
- **Two layers of retries could stack.** The SDK retried on its own, with
  45 seconds allowed per attempt. With 5 pages, one document could in the
  worst case take 5 × 2 × 45 = 450 seconds. PHP stops waiting at 60
  seconds. The sidecar would keep working, and keep paying for model
  calls, long after anyone could use the answer.
- **Nothing stopped abandoned work.** When PHP gave up after 60 seconds,
  the sidecar's thread kept running to the end. Threads cannot be stopped
  from outside in Python, and nothing told the work to stop.

---

## 5. Tracing one extraction request

The reviewer asked for one request to be traced, to identify where it
waits on the network, where it does blocking work, and where it uses the
**CPU** (the processor, i.e. actual computation). Here is the path, with
the thread each step runs on.

| Step | Where it runs | Kind of work | Typical cost |
|---|---|---|---|
| Receive the request body | event loop | network wait (`await`) | small |
| Parse the JSON body (holds the PDF as base64 text) | event loop | CPU | milliseconds, even for several MB |
| Validate it against the contract (Pydantic) | event loop | CPU | milliseconds |
| Look up the **idempotency cache** | event loop | CPU | microseconds |
| **New:** wait for an extraction slot | event loop | waiting (`await`), holds no thread | 0 to 20 s |
| Decode the base64 PDF to bytes | worker thread | CPU | milliseconds |
| Open the PDF and read words with their positions (PyMuPDF) | worker thread | CPU | tens of milliseconds per page |
| OCR a scanned page (tesseract) | worker thread | CPU, in a separate program | about 2–3 s per page |
| One model call per page | worker thread | **network wait** | about 2–3 s per page, 5 pages in a row |
| Anchor each value to its row, compute confidence | worker thread | CPU (pure Python) | tens of milliseconds |
| Possibly one extra model call for missed rows | worker thread | network wait | about 2–3 s |
| Build and send the response | event loop | CPU | milliseconds |

Definitions for the table:

- **base64** is a way of writing binary data (such as a PDF) using only
  ordinary text characters, so it can travel inside JSON. It makes the data
  about a third larger.
- **Pydantic** is a Python library that checks data against a declared
  shape and refuses anything that does not fit.
- The **idempotency cache** remembers finished answers for ten minutes. If
  the same request arrives twice (for example after a network retry), the
  second one gets the stored answer and no model is called again.
  *Idempotent* means "doing it twice has the same effect as doing it once".

**Conclusion of the trace.** Nothing long-running happens on the event
loop; only small, millisecond-scale work does. The long parts (network
waits, OCR, anchoring) all run on worker threads.

**One caveat: Python's GIL.** Python's main implementation has a **GIL**
(global interpreter lock): only one thread can run Python code at any
instant. Threads that are *waiting* (on the network, or on the separate
tesseract program) release the lock, so they do not get in the way. Pure
Python calculation in a worker thread, such as anchoring, does hold the
lock. The event loop then has to take turns with it: Python switches
threads every 5 milliseconds or so. The loop is slowed slightly, never
frozen. Limiting how many extractions run at once also limits this
contention.

---

## 6. What was changed

The change adds three controls, all in a new module,
[capacity.py](../interface/modules/custom_modules/oe-module-clinical-copilot/sidecar/copilot_sidecar/capacity.py),
and wires them into the request handler and the model call.

### 6.1 The admission gate: a limit on extractions running at once

**Admission control** means deciding, at the door, whether to take on a
piece of work. The gate (`ExtractionGate`) works like a clinic waiting
room with a fixed number of exam rooms and a fixed number of chairs:

- **Exam rooms (`COPILOT_MAX_EXTRACTIONS`, default 8).** At most 8
  extractions are worked on at the same time.
- **Chairs (`COPILOT_MAX_WAITING_EXTRACTIONS`, default 16).** When every
  room is busy, up to 16 more requests may wait.
- **Longest wait (`COPILOT_EXTRACTION_MAX_WAIT_S`, default 20 seconds).**
  A request that has waited 20 seconds without a room is sent away.
- **Anyone else is turned away immediately** with HTTP **503 Service
  Unavailable** and the code `overloaded`, instead of being accepted and
  failed later.

The line is **FIFO**, "first in, first out": whoever has waited longest
gets the next free room. When a request finishes, its room is handed
straight to the oldest waiter, so a newcomer can never jump the line.

**Why wait on the event loop rather than in a thread.** A waiting request
is represented by an asyncio **future**: a placeholder object that will
later be given a result. The request `await`s that future. While it waits
it costs almost nothing and **holds no thread**. The thread pool therefore
stays available for real work and for the health check. If the waiting
happened inside threads instead, 16 waiters would occupy 16 of the 40
threads doing nothing.

**Why 8.** In run 3, at 10 users, about 8–9 extractions ran at once and
100 % of documents were extracted. So 8 is known to work with the current
provider account. It is a starting point to tune, not a measurement of
the account's true limit.

The gate lives in the `/run` handler in
[app.py](../interface/modules/custom_modules/oe-module-clinical-copilot/sidecar/copilot_sidecar/app.py).
It applies only to `extract` mode. Briefing and question-answering runs
are unchanged. A repeat request served from the idempotency cache never
reaches the gate.

### 6.2 Provider slots: a limit on model calls in flight

**Semaphore.** A semaphore is a counter of free slots. Taking a slot when
none is free means waiting until someone gives one back.

`ProviderSlots` is a semaphore of size `COPILOT_MAX_PROVIDER_CALLS`
(default 8). Every single model call, from any page of any document,
including retries, must hold a slot while it talks to the provider. A call
that is waiting between retries gives its slot back, so waiting callers do
not block working ones.

**Why a second limit when the first already caps extractions?** Today
each extraction asks for pages one after another, so 8 extractions make
at most about 8 calls at once, and the two limits coincide. They protect
different things, though:

- **The extraction limit** protects the sidecar itself: memory and CPU for
  PDF parsing and OCR. The container has a 768 MB memory limit.
- **The provider limit** protects the provider quota. It keeps holding if
  pages are later requested in parallel (one of the recommendations in
  BASELINES.md), when one document could otherwise make 5 calls at once.

### 6.3 The deadline

A **deadline** is a fixed point in time after which the work is
abandoned. Each extraction request gets one: **50 seconds from arrival**
(`COPILOT_EXTRACTION_DEADLINE_S`). That leaves PHP's 60-second limit room
to receive the answer. Time spent waiting for a slot counts against it.

The deadline travels from the request handler into the worker thread in a
**context variable** (`ContextVar`). A context variable is a value that
follows one request through the code, including into threads started for
that request, without being passed as an argument to every function. The
correlation id that labels every log line already travels the same way.

The deadline is checked at four points:

1. before each page's model call, in
   [extractor.py](../interface/modules/custom_modules/oe-module-clinical-copilot/sidecar/copilot_sidecar/extractor.py):
   once it has passed, no more pages are paid for, and the document fails
   with `timeout`
2. before each model attempt: an attempt with less than 2 seconds left
   (`MIN_ATTEMPT_S`) is not started, because it could not finish but would
   still be billed
3. inside each attempt: the attempt's own time limit is cut to the time
   remaining
4. before the optional extra call for missed rows: skipped when time is
   short; the original extraction stands, and the missed rows stay listed
   for the clinician

### 6.4 One retry layer, bounded, respecting the provider

In [llm.py](../interface/modules/custom_modules/oe-module-clinical-copilot/sidecar/copilot_sidecar/llm.py):

- **The SDK's automatic retries are switched off** (`max_retries=0`).
  Before, the SDK retried on its own, and any retries added on top would
  multiply with it: 3 × 3 = 9 attempts. Now there is exactly one retry
  layer, in `_call_with_retries`, and it can be reasoned about.
- **At most 3 attempts per call** (`COPILOT_PROVIDER_MAX_ATTEMPTS`).
- **Only failures that can pass are retried.** A failure that might
  succeed on a second try is called **transient**. Each failure is sorted
  by the SDK's own error types (`_classify`):

| Failure | Retried? | Why |
|---|---|---|
| 429 rate limit | yes | the quota refills with time |
| 429 with code `insufficient_quota` | **no** | the account is out of credit; waiting does not help |
| Timeout | yes | may have been a slow moment |
| Connection dropped | yes | network hiccup |
| 5xx server error, 408, 409 | yes | provider-side, often brief |
| Other 4xx (bad request, bad key) | no | the same request would fail again |

- **The wait between attempts follows the provider's instructions when
  given.** A provider that throttles a call usually sends a
  **`Retry-After`** header: "try again in N seconds". (A **header** is a
  labelled piece of information sent alongside an HTTP message.) OpenAI
  may also send `retry-after-ms`. When either is present, the sidecar
  waits exactly that long plus up to 20 % more, chosen at random.
- **Otherwise it uses exponential backoff with full jitter.**
  - **Exponential backoff** doubles the maximum wait after each failure:
    up to 1 s, then 2 s, then 4 s, capped at 8 s.
  - **Jitter** means the actual wait is chosen at random between zero and
    that maximum.
  - Without jitter, many callers throttled at the same instant would all
    retry at the same instant and collide again (the "thundering herd").
    The random 20 % added to `Retry-After` serves the same purpose.
- **No retry is started that the deadline could not fit.** If the wait
  plus a minimal attempt would pass the deadline, the call gives up
  immediately instead of sleeping uselessly.
- **Retries do not add pressure.** Every retry must take a provider slot
  again, so retries can never push the number of calls in flight above
  the limit.

The critic's model call (used when briefing, not extracting) is unchanged:
one attempt, 10 seconds, no retries.

### 6.5 The slot is freed only when the work really ends

A Python thread cannot be stopped from outside. Suppose a request were
cancelled (for example, during a server shutdown) and its slot freed at
once. The thread would still be running and calling the provider, and the
limit would be counting requests rather than real work.

The handler therefore runs the thread's work under `asyncio.shield`, which
protects it from the request's cancellation. It frees the extraction slot
in a callback that fires only when the thread actually finishes.

---

## 7. What the user sees when the service is full

The design choice was between two options:

- **A job queue:** accept every upload, process it later in the background,
  and let the user check back.
- **An overload response:** do the work while the user waits, and say
  "busy, try again" when full.

The current architecture is synchronous end to end: PHP calls the sidecar
and waits for the answer, and the panel waits for PHP. A background job
queue would need job storage, a status endpoint, and polling in the panel.
That is a much larger change. The overload response fits the current
design, and the waiting line (at most 16 requests, at most 20 seconds)
absorbs short bursts, so it is a small, bounded queue.

What happens, step by step, when capacity is exhausted:

1. The sidecar answers within milliseconds: HTTP 503 with
   `{"code": "overloaded"}` and the header `Retry-After: 15`. Nothing was
   attempted, and no model call was paid for.
2. PHP's `SidecarClient` turns that into a `SidecarException` with code
   `overloaded`.
3. `DocumentController` keeps the uploaded file stored, and returns the
   message the panel displays:

   > The document service is busy; the file is stored. Try extracting it
   > again in a minute

4. The file stays in `stored` status, so pressing Extract again later
   works normally.

There is no automatic retry on either side, and no unlimited queue. Both
the number waiting and the time waited are capped.

Two contracts gained the new `overloaded` code:
[run.error](../interface/modules/custom_modules/oe-module-clinical-copilot/contracts/run.error.schema.json)
(sidecar to PHP) and
[documents.error.response](../interface/modules/custom_modules/oe-module-clinical-copilot/contracts/documents.error.response.schema.json)
(PHP to browser). A **contract** here is a JSON Schema file that defines
exactly what a message may contain. Both the PHP and Python test suites
check their code against the same files, so the two sides cannot drift
apart.

---

## 8. Deadlines, retries, and work that outlives its request

**Does a timed-out or disconnected request leave work running?** Partly,
and now only for a bounded time:

- **When the deadline passes,** no new page call and no new retry starts.
  A call already in progress was given only the time that remained, so it
  ends at about the deadline. Work stops within a few seconds of the
  50-second mark. The one step that does not check the deadline is OCR of
  a single page, about 2–3 seconds.
- **When PHP gives up at 60 seconds,** the sidecar has already stopped at
  50.
- **When a client disconnects early,** the sidecar does not notice. The
  work runs on until it finishes or reaches the deadline. The result is
  stored in the idempotency cache, but PHP generates a new correlation id
  for every attempt, so a retried request will not find it. The waste is
  bounded, but not eliminated.
- **When a waiting request's client disconnects,** it still takes its turn
  when a slot frees. It is bounded by the 20-second maximum wait.

**How long can one document take in the worst case?** Before the change:
up to about 450 seconds, from 5 pages × 2 SDK attempts × 45 seconds, with
no deadline. After: about 50 seconds from arrival, plus at most one OCR
page.

---

## 9. Measuring document success separately from HTTP success

The central lesson of run 3 is that **a successful HTTP response is not a
successful extraction.** The sidecar now counts both, separately.

**`GET /metrics`** is a new endpoint (**endpoint**: a URL the service
answers). It has its own contract,
[sidecar.metrics.response](../interface/modules/custom_modules/oe-module-clinical-copilot/contracts/sidecar.metrics.response.schema.json),
and reports everything since the process started:

| Section | What it counts |
|---|---|
| `limits` | the configured limits, so a reading can be tied to its settings |
| `now` | extractions running, requests waiting, model calls in flight |
| `runs` | extract requests admitted, refused because the line was full, refused because the wait ran out, answered 200, answered with an error |
| `documents` | documents **extracted**, and documents **failed, by reason** (`model_error`, `timeout`, `unreadable`, …) |
| `provider` | model calls made, 429s received, retries made, and calls given up on, by cause (`throttled`, `quota`, `deadline`, …) |
| `timings_ms` | median, p95 and maximum of the last 1,000 samples of: time in line, time working, time per document, time per model call, time waiting for a provider slot |

Each document also gets one **`document outcome`** log line, with its
status, failure reason, number of model calls, retries and duration. The
log also gains `run admitted` / `run rejected` lines (with time in line and
the counts running and waiting) and `model_call retry` lines (with the
attempt number, the cause, and the wait before the next attempt).

**No patient data can leak through these.** The sidecar's logs pass
through an **allowlist**: a fixed list of permitted field names (ids,
counts, timings, codes). Anything else attached to a log line is dropped
before it is written. The new fields (`queue_ms`, `active`, `waiting`,
`attempt`, `cause`, `retry_in_ms`, `retries`) were added to that list.
The metrics only ever contain counts, milliseconds and short codes.
A test also checks that a metrics key must be a short lower-case code, so
text from a document (such as a patient's name) can never appear there.

The counters reset when the process restarts. They are for an operator
reading the current state, not a permanent record. PHP's own log line
(`copilot document extracted`) and the Langfuse trace remain the lasting
record of each document's outcome.

---

## 10. Evidence: tests and load tests that were actually run

Everything in this section was run on 2026-09-26. No real model provider
was called: every test ran with its network switched off
(`--network none`), so a real API key could not have been used even by
mistake.

### 10.1 Unit tests

The tests ran inside the sidecar's own Docker image (Python 3.12 with
tesseract) against the changed source.

- **Sidecar suite: 123 of 123 passed.** This includes 16 new tests in
  [tests/test_capacity.py](../interface/modules/custom_modules/oe-module-clinical-copilot/sidecar/tests/test_capacity.py).
  A **mocked provider** here means a fake object standing in for OpenAI.
  It answers or fails on command, raising the SDK's real error types, so
  the error sorting under test is the real one.
- **PHP contract tests: 104 passed, 13 skipped** (the same skips as
  before). These are `ContractsTest`, `ContractExamplesTest`,
  `SidecarClientTest` and `DocumentRequestTest`, run in the dev OpenEMR
  container. The changed PHP file also passed a syntax check (`php -l`).

What the new tests prove:

| Requirement | Test |
|---|---|
| Configured limits are respected | 5 simultaneous extractions against 2 slots + 1 chair: exactly 2 run at once, 1 waits, 2 are refused (`test_run_holds_the_limit_...`) |
| Configured limits are respected | 12 simultaneous model calls against 3 slots: never more than 3 in flight (`test_provider_calls_in_flight_never_exceed_the_slots`) |
| Overload is handled predictably | the refused requests get 503, code `overloaded`, `Retry-After: 15`; `/metrics` counts them as refused |
| Overload is handled predictably | the gate admits, queues and refuses correctly; a wait that runs out is refused; order is first-in-first-out; a cancelled waiter leaves the line without leaking a slot (four gate tests) |
| Concurrent requests stay responsive | while both slots are blocked, `/health` still answers in under 1 second |
| Retries terminate | a provider that always answers 429 is called exactly 3 times, and the waits honour `Retry-After` (3.0–3.6 s for `Retry-After: 3`) |
| Retries terminate | without `Retry-After`, the waits stay within the jittered, capped backoff |
| Retries terminate | an exhausted quota is not retried at all |
| Retries terminate | a transient failure followed by success returns the result |
| Deadline | no retry starts that the deadline could not fit, and the attempt is given only the remaining time |
| Deadline | a spent deadline makes no call at all |
| Deadline | on a 3-page document, the extractor stops after page 1 once the deadline is spent (pages 2 and 3 are never paid for) |
| One retry layer | the SDK is created with `max_retries=0` |
| Failures stay visible | a provider refusing every call gives an HTTP 200 whose document failed, and `/metrics` shows `http_ok: 1` next to `failed: {model_error: 1}` |
| No patient text in metrics | a metrics key that is free text (a name) is refused |

### 10.2 Load tests with a mocked provider

**The tool.** A new script,
[tools/load_mock.py](../interface/modules/custom_modules/oe-module-clinical-copilot/sidecar/tools/load_mock.py),
runs the whole comparison without an API key, without cost, and without
OpenEMR:

- It starts a **fake provider**: a small local web server that answers
  exactly like OpenAI's chat completion endpoint. It enforces a rate limit
  with a **token bucket**. A token bucket holds a number of tokens that
  refill at a steady rate; each call spends one, and a call that finds the
  bucket empty gets 429 with `Retry-After: 1`, as OpenAI does. Each
  accepted call takes 2 seconds.
- It starts the **real sidecar**, from whichever copy of the code is being
  tested, exactly as the Dockerfile does. The sidecar's own OpenAI library
  is pointed at the fake through `OPENAI_BASE_URL`, so the real retry
  code, the real PDF parser and the real per-page loop all run.
- It simulates **50 users** for **90 seconds**. Each user extracts the
  5-page `lab-layout1.pdf`, pauses 1 second, and repeats. Each request
  gets a fresh correlation id so the idempotency cache never answers.
  Each allows 60 seconds, like PHP.
- Meanwhile it checks `GET /health` every 250 milliseconds, to see whether
  the service stays responsive.
- It scores each response by **reading the document's status inside the
  body**, never by the HTTP code alone.

The "before" code is an exact copy of the sidecar at commit `32098f9`,
taken with `git archive`. The "after" code is the working tree.

**Scenario A: provider allows 4 calls per second (bursts of 8).**

| Measure | Before | After |
|---|---|---|
| Requests sent | 1,783 | 2,258 |
| HTTP 200 | 1,783 (100 %) | 80 |
| HTTP 503 `overloaded` | 0 | 2,178 |
| **Documents extracted** | **23** (15.3 per minute) | **80** (53.3 per minute) |
| **Documents failed inside an HTTP 200** | **1,760** | **0** |
| Share of HTTP 200s that were real extractions | 1.3 % | 100 % |
| Model calls made | 3,993 | 400 (exactly 80 documents × 5 pages) |
| Calls throttled (429) | 3,605 | 0 |
| Most calls in flight at once | 12 | 8 |
| Time for a successful extraction, median / p95 | 11.2 s / 11.3 s | 22.4 s / 23.6 s |
| Time to receive an `overloaded` answer, median / p95 | – | 7 ms / 591 ms |
| `/health` median / p95 / max | 5 / 99 / 1,091 ms | 4 / 16 / 818 ms |

The "before" column reproduces run 3's failure shape exactly: every HTTP
answer is a 200, and almost every document inside them failed.

Reading the "after" column:

- **Every admitted document succeeded.** The provider was never throttled,
  and not one model call was wasted.
- **Throughput is at the provider's ceiling.** 4 calls per second ÷ 5
  pages = 0.8 documents per second, or 48 per minute. The measured 53 per
  minute includes the initial burst. This is the most the provider would
  allow, which is where this control should put it.
- **The successful extraction takes longer: 22 s instead of 11 s.** About
  11 s of that is waiting in line (median time in line: 11.2 s) and about
  11 s is the work itself. It is still well inside the 50-second deadline
  and PHP's 60 seconds. This is the intended trade: wait a little and
  succeed, rather than start at once and fail.
- **The overflow is refused in milliseconds** rather than accepted and
  failed after a second of paid, wasted calls.

**Scenario A is kind to the change, and that should be said openly.**
Eight slots × 2 seconds per call = 4 calls per second, which happens to
equal the fake's limit. No retry ever fired. So the next two scenarios
use a stricter provider.

**Scenario B: provider allows 2 calls per second (bursts of 4).**

| Measure | Before | After, default limits (8/8) | After, tuned limits (4/4) |
|---|---|---|---|
| **Documents extracted** | **9** (6.0 per minute) | 25 (16.7 per minute) | **40** (26.7 per minute) |
| **Documents failed inside an HTTP 200** | **1,953** | 138 | **0** |
| HTTP 503 `overloaded` | 0 | 2,198 | 2,583 |
| Model calls / 429s | 4,152 / 3,954 | 712 / 496 | 200 / 0 |
| Retries made | (inside the SDK, not counted) | 358 | 0 |
| Calls given up on, by cause | – | throttled: 138 | none |
| `/health` max | 1,137 ms | 204 ms | 606 ms |

What scenario B shows:

- **With default limits, the change helps but does not solve it.**
  Documents extracted rose 2.8×, but 138 documents still failed after 3
  throttled attempts each. The reason: 8 calls in flight at 2 seconds each
  is 4 calls per second, double what this provider allows. Retries and
  backoff can absorb short bursts of throttling, but not a limit that is
  set too high all the time.
- **With the limits tuned to the provider, it does.** At 4 extractions and
  4 provider calls, there were 0 failures, 0 throttles and 4.4× the old
  throughput. The settings work as intended; they must be set to match
  the account.

### 10.3 How to rerun the comparison

From the sidecar directory, with `BEFORE` pointing at a copy of an older
build:

```bash
# make the "before" copy, e.g. of commit 32098f9:
#   git archive 32098f9 interface/modules/custom_modules/oe-module-clinical-copilot/sidecar \
#     | tar -x --strip-components=5 -C "$BEFORE"
R=$(git rev-parse --show-toplevel)
for build in "$BEFORE:before" "$PWD:after"; do
  docker run --rm --network none -v "${build%%:*}":/code:ro -v "$PWD/tools":/tools:ro \
    -v "$R/interface/modules/custom_modules/oe-module-clinical-copilot/contracts":/contracts:ro \
    -v "$R/tests/evals/fixtures/docs":/fixtures:ro -e COPILOT_CONTRACTS_DIR=/contracts \
    development-easy-copilot-sidecar python /tools/load_mock.py --sidecar-dir /code --label "${build##*:}"
done
```

This exact command was run (with a short duration) and works as written.

- **Scenario B:** add `--rate 2 --burst 4`.
- **Tuned limits:** add
  `--env COPILOT_MAX_PROVIDER_CALLS=4 --env COPILOT_MAX_EXTRACTIONS=4`.

The saved results are in `tests/load/results/20260926T-mock-*.json` (five
files: `before`, `after`, `before-rate2`, `after-rate2`,
`after-rate2-tuned`).

---

## 11. Tests proposed but not run

- **A real 50-user load test against the real provider**, with the rebuilt
  sidecar image, using the existing k6 script
  (`tests/load/run-baselines.sh`, scenario `extract`). This is the only
  way to learn the account's true rate limit and tune the settings to it.
  It was not run because it costs money and deliberately hits the real
  quota. It needs the user's go-ahead.
- **A full PHPStan run** (static analysis of all the PHP code). The PHP
  change is a two-branch message choice. The pre-commit hook runs PHPStan
  on commit.
- **A PHP controller test** checking that the `overloaded` code produces
  the "busy" message. No existing test pins these messages.
- **A test of client disconnection** during a long extraction. The
  behaviour is described in section 8 but not tested.

---

## 12. What is still not solved

1. **A concurrency limit is only a stand-in for a rate limit.** The
   provider limits calls and text *per minute*; the sidecar limits calls
   *at the same time*. The two match only when the call length is known.
   Scenario B shows that a limit set too high still produces failures. The
   settings must be tuned to the account's tier with a real load test. A
   future improvement would read the provider's own rate-limit headers
   (`x-ratelimit-remaining-requests` and similar) and adjust automatically.
2. **The limits are per process.** Today there is one process, so they are
   also the limits for the deployment. Running two containers, or uvicorn
   with several workers, would double every limit, with no coordination.
   A limit shared across processes would need a shared store such as
   Redis.
3. **The sidecar cannot see PHP's own model calls.** The briefing and
   question answering call OpenAI directly from PHP, on the same account
   and quota. The critic's calls in the sidecar are also not counted. Under
   mixed load, those calls and extraction compete for one quota.
4. **A disconnected client's work is not cancelled.** It is bounded by the
   deadline, not stopped at once.
5. **Overload means the user must press Extract again.** A background job
   queue would let uploads wait longer and finish on their own, at the
   cost of a larger design change (job storage, a status endpoint, polling
   in the panel).
6. **The metrics reset on restart** and cover one process. They are a live
   view, not a history. The history is in PHP's logs and Langfuse.

---

## 13. Interview answers in plain words

**What blocked the event loop?**
The sidecar's extraction work (reading the PDF, OCR, five model calls in
a row, anchoring) is ordinary synchronous code. It was called directly
from inside an `async` request handler. The event loop is the single
thread that takes turns serving every request, and that code held it for
the whole extraction, often 12 seconds or more. Every other request,
including health checks, waited. Ten simultaneous extractions ran one
after another and timed out.

**Why did the thread pool help?**
`run_in_threadpool` moved the work onto spare worker threads, and `await`
freed the event loop to serve other requests meanwhile. Most of an
extraction's time is spent waiting on the network for the model, and many
threads can wait at once. So extractions overlapped instead of queueing,
and 10 users went from timeouts to 100 % success.

**Why did provider limits become the next problem?**
Removing the first bottleneck removed the only thing that was holding
work back. With 50 users, about 40 extractions started at once, each
calling the model page by page: roughly 40 calls in flight all the time.
The provider's rate limit refused most of them with 429. The SDK's single
quick retry met the same full quota and failed again, and the documents
failed. The failures came back inside HTTP 200 responses, because a
failed document is reported inside a successful answer by design. So the
HTTP error rate showed 0 % while only 12.7 % of documents were extracted.
The bottleneck had moved from our code to the provider's quota, and
nothing in the sidecar knew about that quota.

**What does the new control do when demand exceeds capacity?**
It works like a waiting room with a fixed number of exam rooms. 8
extractions run at once. Up to 16 more wait their turn, in order, for at
most 20 seconds. Anyone beyond that is told "busy, try again in 15
seconds" within milliseconds, with nothing attempted and nothing paid
for. The file stays stored, and the user sees "The document service is
busy; try extracting it again in a minute". Separately:

- model calls are capped at 8 in flight
- throttled calls are retried at most 3 times, waiting what the provider
  asks, with some randomness so they do not all return together
- every extraction has a 50-second deadline, so nothing runs on after
  PHP has stopped waiting

**What evidence supports the improvement?**
Two kinds, both run with a mocked provider and no real key.

- **Unit tests:** 123 of 123 sidecar tests pass, 16 of them new. They show
  the limits holding, overflow refused with 503, the health check
  answering in under a second while every slot is busy, retries stopping
  after 3 attempts and honouring `Retry-After`, the deadline stopping
  further page calls, and a failed document inside a 200 still counted as
  a failure.
- **A before-and-after load test:** the same workload (50 users, 90
  seconds, a rate-limited fake provider) against the old and new code.
  - Documents extracted per minute rose 3.5× (15 to 53).
  - Documents failed inside a 200 fell from 1,760 to 0.
  - Throttled calls fell from 3,605 to 0.
  - Against a stricter provider, with the limits tuned to it: 4.4× the
    throughput and zero failures.

**What limitation remains?**

- A concurrency cap only stands in for a rate limit, so it must be tuned
  to the real account. Set too high, it still produced 138 failures in
  the strict test.
- The limits are per process.
- PHP's own model calls share the quota but are invisible to the sidecar.
- A disconnected client's work runs on until the deadline.
- When full, the user has to press Extract again; there is no background
  queue.

---

## 14. Files changed

All paths are under `interface/modules/custom_modules/oe-module-clinical-copilot/`
unless stated otherwise.

| File | Change |
|---|---|
| `sidecar/copilot_sidecar/capacity.py` | **New.** Limits read from the environment, the admission gate, provider slots, the deadline, backoff, and the metrics counters |
| `sidecar/copilot_sidecar/app.py` | `/run` admits extractions through the gate, answers 503 `overloaded` with `Retry-After`, binds the deadline, frees the slot only when the thread ends; new `GET /metrics` |
| `sidecar/copilot_sidecar/llm.py` | SDK retries off; one bounded retry loop with error sorting, `Retry-After`, jittered backoff, provider slots and the deadline |
| `sidecar/copilot_sidecar/extractor.py` | Deadline checked before each page; the optional missed-rows call is skipped when time is short; failure cause logged |
| `sidecar/copilot_sidecar/graph.py` | Each document's final outcome and duration counted and logged |
| `sidecar/copilot_sidecar/schemas.py` | `overloaded` added to `RunError`; new `SidecarMetrics` model |
| `sidecar/copilot_sidecar/logging_setup.py` | New allowlisted log fields |
| `sidecar/tests/test_capacity.py` | **New.** 16 tests |
| `sidecar/tests/test_contracts.py` | The metrics contract added to the contract-to-model checks |
| `sidecar/tools/load_mock.py` | **New.** The mocked-provider load test |
| `sidecar/README.md` | Settings table and how to run the comparison |
| `contracts/run.error.schema.json` (+ examples) | `overloaded` code |
| `contracts/documents.error.response.schema.json` (+ examples) | `overloaded` reason |
| `contracts/sidecar.metrics.response.schema.json` (+ examples) | **New** contract for `/metrics` |
| `src/Controller/DocumentController.php` | "Busy, try again" message for `overloaded` |
| `tests/load/results/20260926T-mock-*.json` (repository root) | **New.** The five load-test results |

The settings, with their defaults:

| Variable | Default | What it controls |
|---|---|---|
| `COPILOT_MAX_EXTRACTIONS` | 8 | extractions worked on at once |
| `COPILOT_MAX_WAITING_EXTRACTIONS` | 16 | requests allowed to wait for a slot |
| `COPILOT_EXTRACTION_MAX_WAIT_S` | 20 | longest wait for a slot before `overloaded` |
| `COPILOT_EXTRACTION_DEADLINE_S` | 50 | time an extraction has from arrival |
| `COPILOT_MAX_PROVIDER_CALLS` | 8 | model calls in flight |
| `COPILOT_PROVIDER_MAX_ATTEMPTS` | 3 | attempts per model call |
| `COPILOT_PROVIDER_ATTEMPT_TIMEOUT_S` | 30 | longest single attempt (further cut to the time left) |
| `COPILOT_PROVIDER_BACKOFF_BASE_S` / `_CAP_S` | 1 / 8 | backoff when no `Retry-After` is given |

---

## Glossary

**429 Too Many Requests.** The HTTP status a provider sends when a caller
has gone over its rate limit.

**503 Service Unavailable.** The HTTP status meaning "I cannot take this
right now". The sidecar sends it, with code `overloaded`, when extraction
capacity is full.

**Admission control.** Deciding at the door whether to accept a piece of
work, instead of accepting everything and failing some later.

**Allowlist.** A fixed list of what is permitted; everything else is
refused. The sidecar's logs keep only allowlisted field names.

**Anchoring.** In this project: proving a value the model proposed is
really printed on the page, by finding it in the same row as its test name
and unit, and recording where.

**`async def` / `await`.** Python syntax for a function the event loop may
pause (`async def`), and the points where it pauses to wait (`await`).

**Backoff (exponential).** Waiting longer after each failed attempt,
usually doubling the wait each time, up to a cap.

**base64.** A way of writing binary data as ordinary text characters so it
can travel inside JSON.

**Blocking.** Making a thread wait (for the network, a file, or a long
calculation) without letting anything else run on it.

**Container (Docker).** An isolated, packaged copy of a program with
everything it needs to run.

**Context variable (`ContextVar`).** A value that follows one request
through the code, including into threads started for it, without being
passed as an argument.

**Contract.** Here, a JSON Schema file that defines exactly what a message
between two parts of the system may contain; both sides are tested
against it.

**Correlation id.** A unique label attached to one request and to every
log line and model call it causes, so its whole path can be found later.

**CPU-bound.** Work limited by calculation speed rather than by waiting.

**Deadline.** A fixed point in time after which work is abandoned.

**Endpoint.** A URL a web service answers, such as `/run` or `/health`.

**Event loop.** One thread that serves many requests by switching between
them whenever the current one is waiting.

**FIFO (first in, first out).** Serving a line in arrival order.

**Future (asyncio).** A placeholder for a result that is not ready yet;
code can `await` it until it is filled.

**GIL (global interpreter lock).** In standard Python, only one thread can
run Python code at any instant. Threads that are waiting release it.

**Header (HTTP).** A labelled piece of information sent alongside an HTTP
request or response, such as `Retry-After: 15`.

**HTTP status code.** The three-digit result number on every web response:
2xx success, 4xx the request was wrong, 5xx the server failed.

**Idempotency cache.** A store of recent finished answers, so a repeated
identical request gets the same answer without the work being done twice.

**Jitter.** Randomness added to a wait, so that many callers do not all
retry at the same instant.

**Load test.** Sending many requests at once to see how a system behaves
under pressure.

**Mock / fake.** A stand-in for a real service in a test, which behaves
the way the test tells it to.

**OCR (optical character recognition).** Reading text from a picture of a
page; here done by tesseract.

**p50 / p95.** The 50th and 95th percentiles: the value half, or 95 %, of
the measurements were at or below. p50 is the median.

**Process.** One running copy of a program, with its own memory.

**Pydantic.** A Python library that checks data against a declared shape.

**Rate limit.** A provider's cap on requests (and text) per minute for one
account.

**`Retry-After`.** A header in which a server says how many seconds to
wait before trying again.

**SDK (software development kit).** A provider's ready-made library for
calling its service; here, OpenAI's Python library.

**Semaphore.** A counter of free slots. Taking a slot when none is free
means waiting until one is given back.

**Thread.** A single line of execution inside a process. A process can
run several threads side by side.

**Thread pool.** A set of spare threads kept ready to run blocking work.

**Throttling.** A provider refusing or slowing calls because a caller is
over its limit.

**Throughput.** How much work is completed per unit of time, here
documents extracted per minute.

**Token bucket.** A rate-limiting method: tokens refill at a steady rate,
each request spends one, and a request that finds no token is refused.

**Transient failure.** A failure that may well succeed if tried again
(a timeout, a brief overload), as opposed to a permanent one (a bad key).

**uvicorn.** The web server program that runs the sidecar's FastAPI
application.
