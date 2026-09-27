"""How much extraction work the sidecar takes on, and the counters that show it.

Why this exists: the 2026-09-23 run 3 load test sent 50 users' uploads to
one sidecar. Every /run went straight to the thread pool, so about 40
extractions ran at once, each calling the model page by page: roughly 40
calls in flight against a provider that allows far fewer. The provider
answered 429, the SDK's single retry was spent at once, and 87 % of the
documents came back "failed: model_error" inside HTTP 200 responses.
Nothing was broken per request; the process simply accepted more work than
the provider would serve.

Four controls, all per process:

  ExtractionGate    at most MAX_EXTRACTIONS documents are worked on at
                    once (fewer while the provider is throttling, see
                    below); up to MAX_WAITING more wait in a first-in,
                    first-out line for at most MAX_WAIT_S. Anyone beyond
                    that, or anyone who waits too long, is told "overloaded"
                    at once (HTTP 503 + Retry-After) instead of being
                    accepted and failed later. The waiting happens on the
                    event loop, so a waiting request holds no thread.
  provider slots    model calls in flight from this process, whatever mix
                    of pages, documents, retries and critic verdicts is
                    asking, under a limit that adapts to the provider: a 429
                    halves it and pauses new calls for the provider's
                    Retry-After; successes grow it back towards
                    MAX_PROVIDER_CALLS. A call sleeping in backoff gives its
                    slot back.
  deadline          an extraction request has DEADLINE_S from arrival
                    (below PHP's 60 s client timeout). Queue wait counts
                    against it; every model attempt is cut to what remains;
                    the extractor stops asking for pages once it is spent.
  cancellation      when the client disconnects, the request leaves the
                    line, or its running work stops at the next page or
                    retry: nobody pays for an answer nobody will read.

"Per process" matters: the sidecar runs as one uvicorn process (Dockerfile
CMD, no --workers), so today these are also the limits for the whole
deployment. Running N processes or N containers multiplies the fixed
numbers by N. The adaptive provider limit softens that, because each
process shrinks its own limit when the provider starts refusing, whoever
caused the refusals; a strict cap shared by all processes would still need
a shared store (Redis, say) and is not built.

Plain words for readers new to this: a semaphore is a counter of free
slots; taking one when none is free means waiting. A deadline is a point on
the monotonic clock after which the work is abandoned. A ContextVar carries
the deadline from the request handler into the worker thread the same way
the correlation id travels (logging_setup.py), so no function signature had
to change to pass it down.
"""

from __future__ import annotations

import asyncio
import os
import random
import threading
import time
from collections import deque
from contextlib import contextmanager
from contextvars import ContextVar
from dataclasses import dataclass
from typing import Callable, Iterator


def _env_int(name: str, default: int, minimum: int) -> int:
    try:
        return max(minimum, int(os.environ.get(name, default)))
    except ValueError:
        return default


def _env_float(name: str, default: float, minimum: float) -> float:
    try:
        return max(minimum, float(os.environ.get(name, default)))
    except ValueError:
        return default


@dataclass(frozen=True)
class Limits:
    """Every tunable, read from the environment once. The defaults are a
    starting point, not a measurement of the account's rate limit: 8
    concurrent extractions is what the 10-user run 3 sustained with 100 %
    of documents extracted, and 50 s leaves PHP's 60 s timeout room to
    receive the answer. Tune them with tools/load_mock.py and a real run."""
    max_extractions: int
    max_waiting: int
    max_wait_s: float
    deadline_s: float
    max_provider_calls: int
    provider_attempts: int
    attempt_timeout_s: float
    backoff_base_s: float
    backoff_cap_s: float

    @classmethod
    def from_env(cls) -> Limits:
        return cls(
            max_extractions=_env_int("COPILOT_MAX_EXTRACTIONS", 8, 1),
            max_waiting=_env_int("COPILOT_MAX_WAITING_EXTRACTIONS", 16, 0),
            max_wait_s=_env_float("COPILOT_EXTRACTION_MAX_WAIT_S", 20.0, 0.0),
            deadline_s=_env_float("COPILOT_EXTRACTION_DEADLINE_S", 50.0, 1.0),
            max_provider_calls=_env_int("COPILOT_MAX_PROVIDER_CALLS", 8, 1),
            provider_attempts=_env_int("COPILOT_PROVIDER_MAX_ATTEMPTS", 3, 1),
            attempt_timeout_s=_env_float("COPILOT_PROVIDER_ATTEMPT_TIMEOUT_S", 30.0, 1.0),
            backoff_base_s=_env_float("COPILOT_PROVIDER_BACKOFF_BASE_S", 1.0, 0.0),
            backoff_cap_s=_env_float("COPILOT_PROVIDER_BACKOFF_CAP_S", 8.0, 0.0),
        )


# A model attempt with less time than this left is not started: it could
# not finish, and it would still be billed.
MIN_ATTEMPT_S = 2.0


class Overloaded(Exception):
    """No room for this extraction: the line was full ("queue_full") or the
    wait ran out ("queue_timeout"). app.py answers 503 overloaded."""
    def __init__(self, reason: str) -> None:
        super().__init__(reason)
        self.reason = reason


# ---- Metrics ----------------------------------------------------------------


class Metrics:
    """In-process counters and recent timings, served by GET /metrics and
    reset when the process restarts. Counts, codes and milliseconds only:
    nothing read from a document is ever recorded here.

    The point of `documents` next to `runs`: a run answered with HTTP 200
    can carry a failed document, so HTTP success is never read as
    extraction success."""

    TIMINGS = ("queue_wait_ms", "extract_active_ms", "document_ms", "provider_call_ms", "provider_slot_wait_ms")

    def __init__(self) -> None:
        self._lock = threading.Lock()
        self.started = time.time()
        self.counters: dict[str, int] = {}
        self.documents: dict[str, int] = {}
        self.provider_gave_up: dict[str, int] = {}
        self._timings: dict[str, deque[float]] = {k: deque(maxlen=1000) for k in self.TIMINGS}
        self._timing_counts: dict[str, int] = {k: 0 for k in self.TIMINGS}

    def inc(self, name: str, n: int = 1) -> None:
        with self._lock:
            self.counters[name] = self.counters.get(name, 0) + n

    def document(self, outcome: str) -> None:
        """outcome is "extracted" or the failure_reason code."""
        with self._lock:
            self.documents[outcome] = self.documents.get(outcome, 0) + 1

    def gave_up(self, cause: str) -> None:
        with self._lock:
            self.provider_gave_up[cause] = self.provider_gave_up.get(cause, 0) + 1

    def observe(self, name: str, ms: float) -> None:
        with self._lock:
            self._timings[name].append(ms)
            self._timing_counts[name] += 1

    def snapshot(self) -> dict:
        def summary(name: str) -> dict:
            values = sorted(self._timings[name])
            at = lambda p: int(values[min(len(values) - 1, int(round(p * (len(values) - 1))))]) if values else None  # noqa: E731
            return {"count": self._timing_counts[name], "p50": at(0.50), "p95": at(0.95), "max": int(values[-1]) if values else None}

        with self._lock:
            c = dict(self.counters)
            extracted = self.documents.get("extracted", 0)
            return {
                "since": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime(self.started)),
                "runs": {
                    "admitted": c.get("runs_admitted", 0),
                    "rejected_queue_full": c.get("runs_rejected_queue_full", 0),
                    "rejected_queue_timeout": c.get("runs_rejected_queue_timeout", 0),
                    "abandoned_by_client": c.get("runs_abandoned_by_client", 0),
                    "http_ok": c.get("runs_http_ok", 0),
                    "http_error": c.get("runs_http_error", 0),
                },
                "documents": {"extracted": extracted, "failed": {k: v for k, v in sorted(self.documents.items()) if k != "extracted"}},
                "provider": {
                    "calls": c.get("provider_calls", 0),
                    "throttled": c.get("provider_throttled", 0),
                    "retries": c.get("provider_retries", 0),
                    "limit_decreases": c.get("provider_limit_decreases", 0),
                    "cooldowns": c.get("provider_cooldowns", 0),
                    "gave_up": dict(sorted(self.provider_gave_up.items())),
                },
                "timings_ms": {k: summary(k) for k in self.TIMINGS},
            }


# ---- The admission gate (event loop side) -----------------------------------


class ExtractionGate:
    """A bounded, first-in first-out admission line for extraction runs.

    Used only from the event loop thread (the /run handler), so plain
    counters need no lock. A waiter is an asyncio future; a freed slot goes
    to the oldest waiter by completing its future, so a newcomer can never
    overtake the line.

    `follow` ties the number of slots to the adaptive provider limit: when
    the provider throttles and the limit halves, fewer extractions are let
    in, instead of admitting documents that could only queue for a model
    call until their deadline ran out."""

    def __init__(self, slots: int, max_waiting: int, follow: Callable[[], int] | None = None) -> None:
        self.max_slots = slots
        self.max_waiting = max_waiting
        self._follow = follow
        self.active = 0
        self._waiters: deque[asyncio.Future[None]] = deque()

    @property
    def slots(self) -> int:
        """Slots open right now: the configured number, or fewer while the provider is throttling."""
        return self.max_slots if self._follow is None else max(1, min(self.max_slots, self._follow()))

    @property
    def waiting(self) -> int:
        return len(self._waiters)

    async def acquire(self, max_wait_s: float) -> float:
        """Takes a slot, waiting at most max_wait_s; returns the seconds
        waited. Raises Overloaded when the line is full or the wait ran out."""
        # Slots may have opened since the last release (the provider limit
        # grew); let the line move before judging the newcomer.
        self.admit()
        if self.active < self.slots and not self._waiters:
            self.active += 1
            return 0.0
        if len(self._waiters) >= self.max_waiting:
            raise Overloaded("queue_full")
        started = time.monotonic()
        fut: asyncio.Future[None] = asyncio.get_running_loop().create_future()
        self._waiters.append(fut)
        try:
            # asyncio.wait never cancels `fut`, so after it returns the future
            # says truthfully whether a slot was handed over.
            await asyncio.wait({fut}, timeout=max_wait_s)
        except BaseException:
            # The request itself was cancelled while waiting (the client left):
            # give back a slot handed over in the same instant, or leave the line.
            self._leave(fut)
            raise
        if not fut.done():
            self._leave(fut)
            raise Overloaded("queue_timeout")
        return time.monotonic() - started

    def _leave(self, fut: asyncio.Future[None]) -> None:
        if fut.done() and not fut.cancelled():
            self.release()
            return
        fut.cancel()
        try:
            self._waiters.remove(fut)
        except ValueError:
            pass

    def release(self) -> None:
        """Frees a slot, then admits waiters, oldest first, while slots are open."""
        self.active -= 1
        self.admit()

    def admit(self) -> None:
        """Hands open slots to waiters. Also called when the provider limit grows."""
        while self._waiters and self.active < self.slots:
            fut = self._waiters.popleft()
            if not fut.done():
                self.active += 1
                fut.set_result(None)


# ---- Provider slots and the deadline (worker thread side) ---------------------


_deadline: ContextVar[float | None] = ContextVar("copilot_deadline", default=None)


def bind_deadline(at: float | None) -> None:
    """Sets the monotonic time this request's work must finish by (None: no deadline)."""
    _deadline.set(at)


def remaining() -> float | None:
    """Seconds left before the bound deadline, or None when there is none."""
    at = _deadline.get()
    return None if at is None else at - time.monotonic()


# Set by the /run handler when the client disconnects (app.py); the worker
# thread checks it at the same points as the deadline and stops paying for
# pages nobody will receive.
_cancel: ContextVar[threading.Event | None] = ContextVar("copilot_cancel", default=None)


def bind_cancel(event: threading.Event | None) -> None:
    _cancel.set(event)


def cancelled() -> bool:
    """True once the request's client has gone away."""
    event = _cancel.get()
    return event is not None and event.is_set()


def pause(seconds: float) -> None:
    """Waits between retries; returns early if the request is cancelled."""
    event = _cancel.get()
    if event is None:
        sleep(seconds)
    else:
        event.wait(seconds)


class ProviderSlots:
    """Model calls in flight, under a limit that adapts to the provider.

    The limit starts at COPILOT_MAX_PROVIDER_CALLS and follows the rule
    TCP uses for network congestion, "additive increase, multiplicative
    decrease" (AIMD):

    * a 429 from the provider halves the limit (never below 1), at most once
      per second, so a burst of 429s from calls that were already in flight
      counts as one signal, not eight;
    * every successful call raises it by 1/limit, so it climbs back by about
      one slot per `limit` successes, never above the configured maximum.

    A 429 also starts a cooldown: no new call is started, by anyone, until
    the provider's Retry-After (or one second) has passed. Callers throttled
    together then do not each discover the limit separately.

    Why this helps with what the sidecar cannot see: PHP's own briefing
    calls and any other process on the same API key draw from the same
    quota. Their load is invisible here, but the 429s it causes are not, so
    the limit shrinks to what is actually left. Each process adapts on its
    own, which is how the limit copes with more than one process without a
    shared store (a strict cap across processes would still need one)."""

    DECREASE_EVERY_S = 1.0
    # Multiplies every cooldown; tests of the retry loop set it to 0 so their
    # instant sleeps are not held up by a real-time pause.
    cooldown_scale = 1.0

    def __init__(self, slots: int) -> None:
        self.max_slots = slots
        self.limit = float(slots)
        self.in_flight = 0
        self.cooldown_until = 0.0
        self._last_decrease = 0.0
        self._cond = threading.Condition()

    @property
    def current(self) -> int:
        return max(1, int(self.limit))

    @contextmanager
    def hold(self, wait_s: float) -> Iterator[bool]:
        """Yields True with a slot held, or False when none opened within wait_s
        (or the request was cancelled while waiting)."""
        started = time.monotonic()
        give_up_at = started + max(0.0, wait_s)
        cancel = _cancel.get()
        got = False
        with self._cond:
            while True:
                now = time.monotonic()
                cooling = self.cooldown_until - now
                if cooling <= 0 and self.in_flight < self.current:
                    self.in_flight += 1
                    got = True
                    break
                left = give_up_at - now
                if left <= 0 or (cancel is not None and cancel.is_set()):
                    break
                # Wake for a freed slot, the end of a cooldown, or every
                # half second to notice a cancelled request.
                self._cond.wait(timeout=min(left, cooling if cooling > 0 else left, 0.5))
        metrics.observe("provider_slot_wait_ms", (time.monotonic() - started) * 1000)
        if not got:
            yield False
            return
        try:
            yield True
        finally:
            with self._cond:
                self.in_flight -= 1
                self._cond.notify_all()

    def succeeded(self) -> None:
        with self._cond:
            self.limit = min(float(self.max_slots), self.limit + 1.0 / max(self.limit, 1.0))
            self._cond.notify_all()

    def throttled(self, retry_after: float | None) -> None:
        now = time.monotonic()
        with self._cond:
            pause = (retry_after if retry_after is not None else 1.0) * self.cooldown_scale
            self.cooldown_until = max(self.cooldown_until, now + pause)
            if now - self._last_decrease >= self.DECREASE_EVERY_S:
                self.limit = max(1.0, self.limit / 2)
                self._last_decrease = now
                metrics.inc("provider_limit_decreases")
            metrics.inc("provider_cooldowns")
            self._cond.notify_all()


def backoff_delay(attempt: int, retry_after: float | None, rng=random.random) -> float:
    """Seconds to wait before attempt `attempt + 1`.

    With the provider's Retry-After: exactly that, plus up to 20 % more at
    random, so the callers it throttled together do not all return in the
    same instant. Without it: "full jitter" exponential backoff, a random
    point between 0 and base * 2^(attempt-1), capped."""
    if retry_after is not None:
        return retry_after * (1.0 + 0.2 * rng())
    return rng() * min(_limits.backoff_cap_s, _limits.backoff_base_s * (2 ** (attempt - 1)))


# Seconds the worker sleeps; tests replace it to run retries instantly.
sleep = time.sleep


# ---- Process-wide instances ---------------------------------------------------

_limits = Limits.from_env()
provider_slots = ProviderSlots(_limits.max_provider_calls)
gate = ExtractionGate(_limits.max_extractions, _limits.max_waiting, follow=lambda: provider_slots.current)
metrics = Metrics()


def limits() -> Limits:
    return _limits


def status() -> dict:
    """The live gauges and the configured limits, for /metrics."""
    return {
        "limits": {
            "max_extractions": _limits.max_extractions, "max_waiting": _limits.max_waiting, "max_wait_s": _limits.max_wait_s,
            "deadline_s": _limits.deadline_s, "max_provider_calls": _limits.max_provider_calls, "provider_attempts": _limits.provider_attempts,
            "attempt_timeout_s": _limits.attempt_timeout_s,
        },
        "now": {
            "active_extractions": gate.active, "waiting_extractions": gate.waiting, "extraction_slots": gate.slots,
            "provider_in_flight": provider_slots.in_flight, "provider_limit": provider_slots.current,
        },
    }


def reset(new: Limits | None = None) -> None:
    """Rebuilds every instance (tests only; nothing may be in flight)."""
    global _limits, gate, provider_slots, metrics
    _limits = new or Limits.from_env()
    provider_slots = ProviderSlots(_limits.max_provider_calls)
    gate = ExtractionGate(_limits.max_extractions, _limits.max_waiting, follow=lambda: provider_slots.current)
    metrics = Metrics()
