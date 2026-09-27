"""Extraction capacity: admission, provider slots, retries and deadlines.

What these pin, in the terms of the 2026-09-23 run 3 failure (50 users,
12.7 % of documents extracted inside 0 % HTTP errors):

* the /run handler never lets more than MAX_EXTRACTIONS documents be
  worked on at once, keeps at most MAX_WAITING in line, and answers the
  rest 503 "overloaded" with Retry-After, predictably and fast;
* the event loop keeps answering /health while every slot is busy;
* no more than MAX_PROVIDER_CALLS model calls are ever in flight;
* a throttled call is retried a bounded number of times, waiting what the
  provider's Retry-After asks, and never past the request's deadline;
* a failed document stays visible as a failure in /metrics even though
  its run answered HTTP 200.

No test calls a real provider: model calls go to fakes that raise the
OpenAI SDK's own exception types, so the classification is the real one."""

from __future__ import annotations

import asyncio
import base64
import json
import threading
import time
from types import SimpleNamespace

import fitz
import httpx
import openai
import pytest

from copilot_sidecar import capacity, graph, llm
from copilot_sidecar.capacity import ExtractionGate, Limits, Overloaded


def limits(**over) -> Limits:
    base = dict(max_extractions=2, max_waiting=1, max_wait_s=5.0, deadline_s=50.0, max_provider_calls=2,
                provider_attempts=3, attempt_timeout_s=30.0, backoff_base_s=1.0, backoff_cap_s=8.0)
    base.update(over)
    return Limits(**base)


@pytest.fixture(autouse=True)
def fresh_capacity(monkeypatch):
    """Every test starts with its own limits, empty counters and instant sleeps."""
    capacity.reset(limits())
    sleeps: list[float] = []
    monkeypatch.setattr(capacity, "sleep", sleeps.append)
    monkeypatch.setattr(capacity.ProviderSlots, "cooldown_scale", 0.0)
    capacity.bind_deadline(None)
    capacity.bind_cancel(None)
    yield sleeps
    capacity.bind_deadline(None)
    capacity.bind_cancel(None)
    capacity.reset()


# ---- The admission gate ------------------------------------------------------


def test_gate_admits_up_to_its_slots_queues_up_to_its_line_and_refuses_the_rest() -> None:
    async def scenario() -> None:
        gate = ExtractionGate(slots=2, max_waiting=1)
        assert await gate.acquire(1.0) == 0.0
        assert await gate.acquire(1.0) == 0.0
        third = asyncio.ensure_future(gate.acquire(1.0))
        await asyncio.sleep(0)
        assert (gate.active, gate.waiting) == (2, 1)
        with pytest.raises(Overloaded) as full:
            await gate.acquire(1.0)
        assert full.value.reason == "queue_full"
        gate.release()  # the freed slot goes straight to the waiter
        assert await third >= 0.0
        assert (gate.active, gate.waiting) == (2, 0)

    asyncio.run(scenario())


def test_gate_refuses_a_waiter_whose_wait_runs_out() -> None:
    async def scenario() -> None:
        gate = ExtractionGate(slots=1, max_waiting=5)
        await gate.acquire(1.0)
        started = time.monotonic()
        with pytest.raises(Overloaded) as late:
            await gate.acquire(0.05)
        assert late.value.reason == "queue_timeout"
        assert time.monotonic() - started < 1.0
        assert (gate.active, gate.waiting) == (1, 0)

    asyncio.run(scenario())


def test_gate_serves_waiters_first_in_first_out() -> None:
    async def scenario() -> list[int]:
        gate = ExtractionGate(slots=1, max_waiting=5)
        await gate.acquire(1.0)
        order: list[int] = []

        async def wait(i: int) -> None:
            await gate.acquire(5.0)
            order.append(i)
            gate.release()

        tasks = [asyncio.ensure_future(wait(i)) for i in range(4)]
        await asyncio.sleep(0)
        gate.release()
        await asyncio.gather(*tasks)
        return order

    assert asyncio.run(scenario()) == [0, 1, 2, 3]


def test_a_cancelled_waiter_leaves_the_line_without_leaking_a_slot() -> None:
    async def scenario() -> None:
        gate = ExtractionGate(slots=1, max_waiting=5)
        await gate.acquire(1.0)
        waiter = asyncio.ensure_future(gate.acquire(5.0))
        await asyncio.sleep(0)
        waiter.cancel()
        with pytest.raises(asyncio.CancelledError):
            await waiter
        assert gate.waiting == 0
        gate.release()
        assert gate.active == 0

    asyncio.run(scenario())


# ---- /run under load (in-process ASGI client, mocked graph) --------------------


def _body(i: int) -> dict:
    return {"mode": "extract", "correlation_id": f"capacity-{i:04d}", "facts_hash": "0" * 64, "question": None,
            "documents": [{"document_id": 1, "doc_type": "lab_pdf", "status": "stored", "sha3_512": "a" * 128, "bytes_base64": "JVBERi0xLjQgbm90IHJlYWxseQ=="}]}


def test_run_holds_the_limit_refuses_the_overflow_and_keeps_health_responsive(monkeypatch) -> None:
    """Five simultaneous extractions against 2 slots + 1 place in line: two
    run, one waits, two are refused at once with 503 overloaded. While the
    two running ones block their worker threads, /health still answers."""
    from copilot_sidecar import app as app_module

    capacity.reset(limits(max_extractions=2, max_waiting=1, max_wait_s=10.0))
    release = threading.Event()
    lock = threading.Lock()
    seen = {"now": 0, "peak": 0}

    def slow_run(mode, correlation_id, *args, **kwargs):
        with lock:
            seen["now"] += 1
            seen["peak"] = max(seen["peak"], seen["now"])
        release.wait(10)
        with lock:
            seen["now"] -= 1
        return {"extractions": [], "chunks": [], "evidence": [], "handoffs": [], "usage": []}

    monkeypatch.setattr(graph, "run", slow_run)

    async def scenario():
        transport = httpx.ASGITransport(app=app_module.app)
        async with httpx.AsyncClient(transport=transport, base_url="http://sidecar") as client:
            runs = [asyncio.ensure_future(client.post("/run", json=_body(i))) for i in range(5)]
            await asyncio.sleep(0.3)
            started = time.monotonic()
            health = await client.get("/health")
            health_s = time.monotonic() - started
            during = (await client.get("/metrics")).json()
            release.set()
            done = await asyncio.gather(*runs)
            after = (await client.get("/metrics")).json()
            return done, health, health_s, during, after

    done, health, health_s, during, after = asyncio.run(scenario())
    statuses = sorted(r.status_code for r in done)
    assert statuses == [200, 200, 200, 503, 503]
    refused = [r for r in done if r.status_code == 503]
    assert all(r.json()["code"] == "overloaded" and r.headers["retry-after"] == "15" for r in refused)
    assert seen["peak"] == 2
    assert health.status_code == 200 and health_s < 1.0
    assert during["now"] == {"active_extractions": 2, "waiting_extractions": 1, "extraction_slots": 2, "provider_in_flight": 0, "provider_limit": 2}
    assert after["runs"]["admitted"] == 3 and after["runs"]["rejected_queue_full"] == 2
    assert after["runs"]["http_ok"] == 3 and after["runs"]["http_error"] == 2
    assert after["now"]["active_extractions"] == 0
    assert after["timings_ms"]["queue_wait_ms"]["count"] == 3


# ---- Provider calls: slots, retries, deadline -----------------------------------

_REQUEST = httpx.Request("POST", "https://api.openai.test/v1/chat/completions")


def _rate_limited(retry_after: str | None = "3", code: str = "rate_limit_exceeded") -> openai.RateLimitError:
    headers = {"retry-after": retry_after} if retry_after is not None else {}
    return openai.RateLimitError("Rate limit reached", response=httpx.Response(429, headers=headers, request=_REQUEST), body={"code": code})


def _server_error() -> openai.InternalServerError:
    return openai.InternalServerError("upstream", response=httpx.Response(503, request=_REQUEST), body=None)


def _reply(content: str):
    message = SimpleNamespace(content=content, refusal=None)
    return SimpleNamespace(choices=[SimpleNamespace(message=message, finish_reason="stop")], usage=SimpleNamespace(prompt_tokens=10, completion_tokens=5), model="gpt-test")


LAB = json.dumps({"patient_name_on_report": None, "collection_date": None, "reported_date": None, "lab_name": None, "results": []})


class FakeProvider:
    """Answers or raises from a script, one entry per call, and records
    each call's timeout and how many calls were in flight at once."""

    def __init__(self, script, delay: float = 0.0) -> None:
        self.script = list(script)
        self.delay = delay
        self.calls: list[dict] = []
        self.lock = threading.Lock()
        self.now = self.peak = 0
        self.chat = SimpleNamespace(completions=self)

    def create(self, **kwargs):
        with self.lock:
            self.calls.append(kwargs)
            self.now += 1
            self.peak = max(self.peak, self.now)
            step = self.script.pop(0) if self.script else LAB
        try:
            if self.delay:
                time.sleep(self.delay)
            if isinstance(step, Exception):
                raise step
            return _reply(step)
        finally:
            with self.lock:
                self.now -= 1


def test_the_sdk_does_not_retry_on_its_own(monkeypatch) -> None:
    made: list[dict] = []
    fake = FakeProvider([LAB])
    monkeypatch.setattr(llm, "OpenAI", lambda **kw: made.append(kw) or fake)
    llm.propose("lab_pdf", "text")
    assert made == [{"max_retries": 0}]


def test_throttled_calls_are_retried_a_bounded_number_of_times_honouring_retry_after(fresh_capacity) -> None:
    fake = FakeProvider([_rate_limited("3")] * 10)
    with pytest.raises(llm.ModelError) as err:
        llm.propose("lab_pdf", "text", client=fake)
    assert err.value.code == "model_error" and err.value.cause == "throttled"
    assert len(fake.calls) == 3  # provider_attempts
    assert len(fresh_capacity) == 2 and all(3.0 <= s <= 3.6 for s in fresh_capacity)
    m = capacity.metrics.snapshot()["provider"]
    # Three 429s inside one second shrink the limit once, not three times.
    assert m == {"calls": 3, "throttled": 3, "retries": 2, "limit_decreases": 1, "cooldowns": 3, "gave_up": {"throttled": 1}}
    assert capacity.provider_slots.current == 1  # 2 halved


def test_without_retry_after_the_backoff_is_jittered_and_capped(fresh_capacity) -> None:
    capacity.reset(limits(provider_attempts=6, backoff_base_s=1.0, backoff_cap_s=4.0))
    fake = FakeProvider([_rate_limited(None)] * 10)
    with pytest.raises(llm.ModelError):
        llm.propose("lab_pdf", "text", client=fake)
    caps = [1, 2, 4, 4, 4]
    assert len(fresh_capacity) == 5
    assert all(0 <= s <= cap for s, cap in zip(fresh_capacity, caps))


def test_an_exhausted_quota_is_not_retried(fresh_capacity) -> None:
    fake = FakeProvider([_rate_limited("1", code="insufficient_quota")] * 3)
    with pytest.raises(llm.ModelError) as err:
        llm.propose("lab_pdf", "text", client=fake)
    assert err.value.cause == "quota" and len(fake.calls) == 1 and fresh_capacity == []


def test_a_transient_failure_then_success_returns_the_proposal(fresh_capacity) -> None:
    fake = FakeProvider([_server_error(), LAB])
    proposal = llm.propose("lab_pdf", "text", client=fake)
    assert proposal.data.results == [] and len(fake.calls) == 2 and len(fresh_capacity) == 1


def test_no_retry_is_started_that_the_deadline_could_not_fit(fresh_capacity) -> None:
    capacity.bind_deadline(time.monotonic() + 4.0)
    fake = FakeProvider([_rate_limited("5")] * 3)
    with pytest.raises(llm.ModelError) as err:
        llm.propose("lab_pdf", "text", client=fake)
    assert err.value.cause == "throttled" and len(fake.calls) == 1 and fresh_capacity == []
    # The one attempt was given only the time that remained, not the full 30 s.
    assert fake.calls[0]["timeout"] <= 4.0


def test_a_spent_deadline_makes_no_call() -> None:
    capacity.bind_deadline(time.monotonic() + 1.0)
    fake = FakeProvider([LAB])
    with pytest.raises(llm.ModelError) as err:
        llm.propose("lab_pdf", "text", client=fake)
    assert (err.value.code, err.value.cause) == ("timeout", "deadline") and fake.calls == []


def test_provider_calls_in_flight_never_exceed_the_slots() -> None:
    capacity.reset(limits(max_provider_calls=3))
    fake = FakeProvider([LAB] * 12, delay=0.05)
    threads = [threading.Thread(target=llm.propose, args=("lab_pdf", "text"), kwargs={"client": fake}) for _ in range(12)]
    for t in threads:
        t.start()
    for t in threads:
        t.join(10)
    assert len(fake.calls) == 12 and fake.peak == 3


# ---- A whole document: deadline between pages, outcome visible ------------------


def _pdf(pages: int) -> bytes:
    doc = fitz.open()
    for i in range(pages):
        doc.new_page().insert_text((72, 72), f"Glucose {90 + i} mg/dL 70-99")
    return doc.tobytes()


def test_the_extractor_stops_asking_for_pages_once_the_deadline_is_spent(monkeypatch) -> None:
    fake = FakeProvider([LAB] * 3, delay=0.6)
    monkeypatch.setattr(llm, "OpenAI", lambda **kw: fake)
    capacity.bind_deadline(time.monotonic() + capacity.MIN_ATTEMPT_S + 0.5)
    doc = SimpleNamespace(document_id=7, doc_type="lab_pdf", bytes_base64=base64.b64encode(_pdf(3)).decode())
    extraction, usage = graph.real_extract(doc, "capacity-deadline")  # type: ignore[arg-type]
    assert (extraction.status, extraction.failure_reason) == ("failed", "timeout")
    assert len(fake.calls) == 1  # pages 2 and 3 were never paid for
    assert capacity.metrics.snapshot()["documents"] == {"extracted": 0, "failed": {"timeout": 1}}


def test_a_failed_document_inside_an_http_200_is_counted_as_a_failure(monkeypatch) -> None:
    """The run 3 shape: the provider refuses every call, the run still
    answers 200, and /metrics says the document failed."""
    from copilot_sidecar import app as app_module

    fake = FakeProvider([_rate_limited("1")] * 10)
    monkeypatch.setattr(llm, "OpenAI", lambda **kw: fake)
    monkeypatch.setattr(graph, "_graph", None)
    body = _body(99)
    body["documents"][0]["bytes_base64"] = base64.b64encode(_pdf(1)).decode()

    async def scenario():
        transport = httpx.ASGITransport(app=app_module.app)
        async with httpx.AsyncClient(transport=transport, base_url="http://sidecar") as client:
            r = await client.post("/run", json=body)
            return r, (await client.get("/metrics")).json()

    r, m = asyncio.run(scenario())
    assert r.status_code == 200
    assert r.json()["extractions"][0]["failure_reason"] == "model_error"
    assert m["runs"]["http_ok"] == 1
    assert m["documents"] == {"extracted": 0, "failed": {"model_error": 1}}
    assert m["provider"]["throttled"] == 3 and m["provider"]["gave_up"] == {"throttled": 1}


def test_metrics_keys_are_codes_never_free_text() -> None:
    """A failure or cause code becomes a /metrics key; the contract's key
    pattern refuses anything that is not a short lower-case code, so text
    read from a document can never surface there. (PHP's schema validator
    does not implement propertyNames, so this rule is pinned here rather
    than in the shared examples.)"""
    from pydantic import ValidationError

    from copilot_sidecar.schemas import SidecarMetrics

    good = {**capacity.metrics.snapshot(), **capacity.status()}
    SidecarMetrics.model_validate(good)
    bad = json.loads(json.dumps(good))
    bad["documents"]["failed"] = {"Jane Doe": 1}
    with pytest.raises(ValidationError):
        SidecarMetrics.model_validate(bad)


# ---- The adaptive provider limit ------------------------------------------------


def test_the_provider_limit_halves_on_throttling_and_grows_back_on_success() -> None:
    slots = capacity.ProviderSlots(8)
    slots.throttled(None)
    assert slots.current == 4
    slots.throttled(None)  # within the same second: one signal, no second halving
    assert slots.current == 4
    slots._last_decrease -= capacity.ProviderSlots.DECREASE_EVERY_S
    slots.throttled(None)
    assert slots.current == 2
    for _ in range(40):
        slots.succeeded()
    assert slots.current == 8  # back to the configured maximum, never above it
    assert slots.limit == 8.0


def test_a_429_pauses_every_caller_for_the_retry_after(monkeypatch) -> None:
    monkeypatch.setattr(capacity.ProviderSlots, "cooldown_scale", 1.0)
    slots = capacity.ProviderSlots(8)
    slots.throttled(0.3)
    started = time.monotonic()
    with slots.hold(5.0) as got:
        waited = time.monotonic() - started
    assert got and 0.25 <= waited < 1.5


def test_the_admission_gate_follows_the_provider_limit() -> None:
    async def scenario() -> None:
        limit = {"n": 1}
        gate = ExtractionGate(slots=4, max_waiting=5, follow=lambda: limit["n"])
        await gate.acquire(1.0)
        second = asyncio.ensure_future(gate.acquire(5.0))
        await asyncio.sleep(0)
        assert (gate.slots, gate.active, gate.waiting) == (1, 1, 1)  # throttled: one slot open
        limit["n"] = 3  # the provider recovered
        third = asyncio.ensure_future(gate.acquire(5.0))  # a newcomer lets the line move first
        await asyncio.sleep(0)
        await second
        await third
        assert (gate.active, gate.waiting) == (3, 0)

    asyncio.run(scenario())


def test_a_throttled_extraction_shrinks_the_gate_for_the_next_arrivals() -> None:
    capacity.reset(limits(max_extractions=8, max_provider_calls=8))
    fake = FakeProvider([_rate_limited("1"), LAB])
    llm.propose("lab_pdf", "text", client=fake)
    assert capacity.provider_slots.current == 4 and capacity.gate.slots == 4
    assert capacity.status()["now"]["provider_limit"] == 4


# ---- The critic shares the provider slots ------------------------------------------


def test_the_critic_waits_only_briefly_for_a_provider_slot(monkeypatch) -> None:
    capacity.reset(limits(max_provider_calls=1))
    monkeypatch.setattr(llm, "CRITIC_SLOT_WAIT_S", 0.1)
    fake = FakeProvider([json.dumps({"applicable": True, "reason": "no restriction stated"})])
    with capacity.provider_slots.hold(1.0):  # extraction holds the only slot
        started = time.monotonic()
        with pytest.raises(llm.ModelError) as err:
            llm.applicable("passage", [], 50, "F", client=fake)
    assert err.value.cause == "no_provider_slot" and fake.calls == [] and time.monotonic() - started < 1.0
    ok, _, _ = llm.applicable("passage", [], 50, "F", client=fake)
    assert ok is True and len(fake.calls) == 1


def test_a_throttled_critic_call_shrinks_the_shared_limit() -> None:
    capacity.reset(limits(max_provider_calls=8))
    fake = FakeProvider([_rate_limited("2")])
    with pytest.raises(llm.ModelError) as err:
        llm.applicable("passage", [], 50, "F", client=fake)
    assert err.value.cause == "throttled" and capacity.provider_slots.current == 4


# ---- Cancellation when the client goes away ---------------------------------------------


def test_a_cancelled_request_makes_no_further_call() -> None:
    event = threading.Event()
    event.set()
    capacity.bind_cancel(event)
    fake = FakeProvider([LAB])
    with pytest.raises(llm.ModelError) as err:
        llm.propose("lab_pdf", "text", client=fake)
    assert (err.value.code, err.value.cause) == ("timeout", "cancelled") and fake.calls == []


def test_the_extractor_stops_at_the_next_page_once_the_client_has_gone(monkeypatch) -> None:
    event = threading.Event()

    class LeavesAfterFirstPage(FakeProvider):
        def create(self, **kwargs):
            reply = super().create(**kwargs)
            event.set()  # the client disconnects while page 1 is being read
            return reply

    fake = LeavesAfterFirstPage([LAB] * 3)
    monkeypatch.setattr(llm, "OpenAI", lambda **kw: fake)
    capacity.bind_cancel(event)
    doc = SimpleNamespace(document_id=8, doc_type="lab_pdf", bytes_base64=base64.b64encode(_pdf(3)).decode())
    extraction, _ = graph.real_extract(doc, "capacity-cancel")  # type: ignore[arg-type]
    assert (extraction.status, extraction.failure_reason) == ("failed", "timeout")
    assert len(fake.calls) == 1
    assert capacity.metrics.snapshot()["provider"]["gave_up"] == {"cancelled": 1}


async def _asgi_post(app, body: dict, disconnect_after: float) -> list[dict]:
    """Calls the app the way uvicorn does, with a client that disconnects
    `disconnect_after` seconds after sending its body."""
    raw = json.dumps(body).encode()
    sent = {"body": False}
    leave_at = time.monotonic() + disconnect_after

    async def receive() -> dict:
        if not sent["body"]:
            sent["body"] = True
            return {"type": "http.request", "body": raw, "more_body": False}
        while time.monotonic() < leave_at:
            await asyncio.sleep(0.05)
        return {"type": "http.disconnect"}

    messages: list[dict] = []

    async def send(message: dict) -> None:
        messages.append(message)

    scope = {"type": "http", "asgi": {"version": "3.0"}, "http_version": "1.1", "method": "POST", "scheme": "http", "path": "/run",
             "raw_path": b"/run", "query_string": b"", "root_path": "", "client": ("127.0.0.1", 5000), "server": ("sidecar", 80),
             "headers": [(b"content-type", b"application/json"), (b"content-length", str(len(raw)).encode())]}
    await app(scope, receive, send)
    return messages


def test_a_running_extraction_is_cancelled_when_its_client_disconnects(monkeypatch) -> None:
    from copilot_sidecar import app as app_module

    seen = {"cancelled_after": None}

    def long_run(mode, correlation_id, *args, **kwargs):
        started = time.monotonic()
        while time.monotonic() - started < 10:
            if capacity.cancelled():  # what every page and retry checks
                seen["cancelled_after"] = time.monotonic() - started
                break
            time.sleep(0.05)
        return {"extractions": [], "chunks": [], "evidence": [], "handoffs": [], "usage": []}

    monkeypatch.setattr(graph, "run", long_run)
    messages = asyncio.run(_asgi_post(app_module.app, _body(500), disconnect_after=0.5))
    assert seen["cancelled_after"] is not None and seen["cancelled_after"] < 2.5
    assert messages[0]["status"] == 499
    m = capacity.metrics.snapshot()
    assert m["runs"]["abandoned_by_client"] == 1 and m["runs"]["http_ok"] == 0
    assert capacity.gate.active == 0


def test_a_waiting_extraction_leaves_the_line_when_its_client_disconnects(monkeypatch) -> None:
    from copilot_sidecar import app as app_module

    capacity.reset(limits(max_extractions=1, max_waiting=2, max_wait_s=10.0, max_provider_calls=1))
    release = threading.Event()

    def blocked_run(mode, correlation_id, *args, **kwargs):
        release.wait(10)
        return {"extractions": [], "chunks": [], "evidence": [], "handoffs": [], "usage": []}

    monkeypatch.setattr(graph, "run", blocked_run)

    async def scenario():
        transport = httpx.ASGITransport(app=app_module.app)
        async with httpx.AsyncClient(transport=transport, base_url="http://sidecar") as client:
            first = asyncio.ensure_future(client.post("/run", json=_body(600)))
            await asyncio.sleep(0.2)
            leaver = await _asgi_post(app_module.app, _body(601), disconnect_after=0.5)
            waiting_after = capacity.gate.waiting
            release.set()
            return leaver, waiting_after, await first

    leaver, waiting_after, first = asyncio.run(scenario())
    assert leaver[0]["status"] == 499 and waiting_after == 0
    assert first.status_code == 200
    m = capacity.metrics.snapshot()["runs"]
    assert m["abandoned_by_client"] == 1 and m["admitted"] == 1
