"""Extraction load test against a mocked, rate-limited model provider.

Reproduces the 2026-09-23 run 3 failure (50 users, 12.7 % of documents
extracted, 0 % HTTP errors) without an API key, without cost and without
OpenEMR: the real sidecar runs in a subprocess, the model provider is a
local fake that answers like OpenAI's chat completions endpoint and
refuses calls over its rate limit with 429 + Retry-After, the way the real
provider does. The sidecar's own OpenAI SDK talks to the fake through
OPENAI_BASE_URL, so the SDK's retries, the extractor's per-page calls and
the parser all run for real.

What it measures, per run:

  HTTP outcome       200 / 503 overloaded / other status / client timeout
  document outcome   extracted or failed (with the failure_reason), read
                     from the response body: a 200 is NOT counted as success
  latency            p50/p95 of extract responses, by outcome
  responsiveness     GET /health latency sampled every 250 ms during the
                     load: a blocked event loop or an exhausted thread
                     pool shows up here
  provider           calls, 429s and peak in-flight calls seen by the fake
  sidecar /metrics   the sidecar's own counters, when it has the endpoint

Run the same workload against two copies of the code to compare them. In
the sidecar image (Python 3.12, tesseract), with no network so a real key
could never be used:

  docker run --rm --network none \
    -v "$PWD":/work -v "$PWD/../contracts":/contracts:ro \
    -v "$REPO/tests/evals/fixtures/docs":/fixtures:ro \
    -v "$BEFORE_DIR":/before:ro \
    -e COPILOT_CONTRACTS_DIR=/contracts -w /work \
    development-easy-copilot-sidecar \
    python tools/load_mock.py --sidecar-dir /before --label before

and again with --sidecar-dir /work --label after. $BEFORE_DIR is a copy of
the sidecar at the older commit (git archive <sha> <sidecar path> | tar -x).
"""

from __future__ import annotations

import argparse
import asyncio
import base64
import json
import os
import statistics
import subprocess
import sys
import threading
import time
import uuid
from pathlib import Path

import httpx
import uvicorn
from fastapi import FastAPI, Request
from fastapi.responses import JSONResponse


# ---- The fake provider ------------------------------------------------------


class Provider:
    """A token bucket: `rate` calls per second sustained, `burst` at once
    from idle. A call that finds the bucket empty gets 429 with a
    Retry-After of one second, as OpenAI's rate limiter answers. Every
    accepted call takes `latency` seconds (asyncio.sleep, so the fake
    itself never becomes the bottleneck)."""

    def __init__(self, rate: float, burst: int, latency: float, reply: str) -> None:
        self.rate, self.burst, self.latency, self.reply = rate, burst, latency, reply
        self.tokens = float(burst)
        self.updated = time.monotonic()
        self.calls = self.throttled = self.in_flight = self.peak_in_flight = 0

    def take(self) -> bool:
        now = time.monotonic()
        self.tokens = min(self.burst, self.tokens + (now - self.updated) * self.rate)
        self.updated = now
        if self.tokens >= 1:
            self.tokens -= 1
            return True
        return False

    def app(self) -> FastAPI:
        api = FastAPI()

        @api.post("/v1/chat/completions")
        async def completions(request: Request) -> JSONResponse:
            await request.body()
            self.calls += 1
            if not self.take():
                self.throttled += 1
                return JSONResponse(status_code=429, headers={"retry-after": "1"}, content={"error": {"message": "Rate limit reached", "type": "requests", "code": "rate_limit_exceeded"}})
            self.in_flight += 1
            self.peak_in_flight = max(self.peak_in_flight, self.in_flight)
            try:
                await asyncio.sleep(self.latency)
            finally:
                self.in_flight -= 1
            return JSONResponse(content={
                "id": "chatcmpl-mock", "object": "chat.completion", "created": int(time.time()), "model": "gpt-mock",
                "choices": [{"index": 0, "finish_reason": "stop", "message": {"role": "assistant", "content": self.reply, "refusal": None}}],
                "usage": {"prompt_tokens": 1200, "completion_tokens": 400, "total_tokens": 1600},
            })

        return api


def serve_in_thread(app: FastAPI, port: int) -> uvicorn.Server:
    server = uvicorn.Server(uvicorn.Config(app, host="127.0.0.1", port=port, log_level="warning"))
    threading.Thread(target=server.run, daemon=True).start()
    while not server.started:
        time.sleep(0.05)
    return server


# ---- The load --------------------------------------------------------------


def pct(values: list[float], p: float) -> int | None:
    if not values:
        return None
    s = sorted(values)
    return int(s[min(len(s) - 1, int(round(p / 100 * (len(s) - 1))))])


async def user(client: httpx.AsyncClient, body_for, stop_at: float, think: float, results: list[dict], client_timeout: float) -> None:
    """One simulated user: extract a document, wait `think` seconds, repeat
    until the run ends. The default 60 s client timeout is PHP's
    SidecarClient::TIMEOUT_S; a shorter one simulates clients that give up."""
    while time.monotonic() < stop_at:
        started = time.monotonic()
        rec: dict = {}
        try:
            r = await client.post("/run", json=body_for(), timeout=client_timeout)
            rec["http"] = r.status_code
            payload = r.json()
            if r.status_code == 200:
                ex = payload["extractions"][0]
                rec["doc"] = ex["status"] if ex["status"] == "extracted" else f"failed:{ex['failure_reason']}"
            else:
                rec["doc"] = f"error:{payload.get('code')}"
        except httpx.TimeoutException:
            rec["http"], rec["doc"] = "client_timeout", "client_timeout"
        except httpx.HTTPError as exc:
            rec["http"], rec["doc"] = type(exc).__name__, "transport_error"
        rec["ms"] = (time.monotonic() - started) * 1000
        results.append(rec)
        await asyncio.sleep(think)


async def prober(client: httpx.AsyncClient, stop_at: float, samples: list[float]) -> None:
    """GET /health every 250 ms. Liveness is the probe Docker restarts the
    container on, so its latency under load is the operational number."""
    while time.monotonic() < stop_at:
        started = time.monotonic()
        try:
            await client.get("/health", timeout=10.0)
            samples.append((time.monotonic() - started) * 1000)
        except httpx.HTTPError:
            samples.append(10_000.0)
        await asyncio.sleep(0.25)


async def load(base_url: str, users: int, seconds: float, think: float, pdf: bytes, client_timeout: float) -> tuple[list[dict], list[float], dict | None]:
    b64 = base64.b64encode(pdf).decode()

    def body_for() -> dict:
        # A fresh correlation id every time, so the idempotency cache never answers.
        return {"mode": "extract", "correlation_id": uuid.uuid4().hex, "facts_hash": "0" * 64, "question": None,
                "documents": [{"document_id": 1, "doc_type": "lab_pdf", "status": "stored", "sha3_512": "a" * 128, "bytes_base64": b64}]}

    results: list[dict] = []
    health: list[float] = []
    limits = httpx.Limits(max_connections=users + 10, max_keepalive_connections=users + 10)
    async with httpx.AsyncClient(base_url=base_url, limits=limits) as client:
        stop_at = time.monotonic() + seconds
        await asyncio.gather(prober(client, stop_at, health), *(user(client, body_for, stop_at, think, results, client_timeout) for _ in range(users)))
        metrics = None
        # Let work abandoned by timed-out clients finish or stop, so the
        # provider's call count below includes what it cost.
        await asyncio.sleep(max(0.0, min(15.0, 60.0 - client_timeout)))
        r = await client.get("/metrics")
        if r.status_code == 200:
            metrics = r.json()
    return results, health, metrics


def summarise(label: str, results: list[dict], health: list[float], provider: Provider, metrics: dict | None, args) -> dict:
    http: dict[str, int] = {}
    docs: dict[str, int] = {}
    for r in results:
        http[str(r["http"])] = http.get(str(r["http"]), 0) + 1
        docs[r["doc"]] = docs.get(r["doc"], 0) + 1
    ok = [r["ms"] for r in results if r["http"] == 200]
    extracted = [r["ms"] for r in results if r["doc"] == "extracted"]
    rejected = [r["ms"] for r in results if r["http"] == 503]
    n = len(results)
    n200 = http.get("200", 0)
    return {
        "label": label,
        "workload": {"users": args.users, "seconds": args.seconds, "think_s": args.think, "client_timeout_s": args.client_timeout, "provider_rate_per_s": args.rate, "provider_burst": args.burst, "provider_latency_s": args.latency, "document": args.pdf},
        "requests": n,
        "http": dict(sorted(http.items())),
        "http_200_pct": round(100 * n200 / n, 2) if n else None,
        "documents": dict(sorted(docs.items())),
        "extracted_pct_of_requests": round(100 * len(extracted) / n, 2) if n else None,
        "extracted_pct_of_http_200": round(100 * len(extracted) / n200, 2) if n200 else None,
        "extracted_per_min": round(len(extracted) / args.seconds * 60, 1),
        "latency_ms": {
            "http_200_p50": pct(ok, 50), "http_200_p95": pct(ok, 95),
            "extracted_p50": pct(extracted, 50), "extracted_p95": pct(extracted, 95),
            "rejected_503_p50": pct(rejected, 50), "rejected_503_p95": pct(rejected, 95),
        },
        "health_ms": {"samples": len(health), "p50": pct(health, 50), "p95": pct(health, 95), "max": int(max(health)) if health else None, "over_1s": sum(1 for h in health if h > 1000)},
        "provider": {"calls": provider.calls, "throttled_429": provider.throttled, "peak_in_flight": provider.peak_in_flight},
        "sidecar_metrics": metrics,
    }


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--sidecar-dir", default=str(Path(__file__).resolve().parent.parent), help="directory holding the copilot_sidecar package to test")
    ap.add_argument("--label", default="run")
    ap.add_argument("--users", type=int, default=50)
    ap.add_argument("--seconds", type=float, default=90)
    ap.add_argument("--think", type=float, default=1.0, help="pause between one user's requests")
    ap.add_argument("--rate", type=float, default=4.0, help="provider calls per second before 429")
    ap.add_argument("--burst", type=int, default=8, help="provider calls allowed at once from idle")
    ap.add_argument("--latency", type=float, default=2.0, help="seconds per accepted provider call")
    ap.add_argument("--pdf", default="/fixtures/lab-layout1.pdf")
    ap.add_argument("--reply", default="/fixtures/lab-layout1.model.json", help="the proposal JSON the fake returns for every page")
    ap.add_argument("--client-timeout", type=float, default=60.0, help="seconds a user waits before giving up (PHP: 60)")
    ap.add_argument("--out", default=None, help="write the JSON summary here too")
    ap.add_argument("--env", action="append", default=[], help="KEY=VALUE passed to the sidecar (repeatable)")
    args = ap.parse_args()

    reply = json.dumps(json.loads(Path(args.reply).read_text()))
    provider = Provider(args.rate, args.burst, args.latency, reply)
    serve_in_thread(provider.app(), 18081)

    # The sidecar exactly as the Dockerfile starts it: one uvicorn process.
    env = {**os.environ, "OPENAI_BASE_URL": "http://127.0.0.1:18081/v1", "OPENAI_API_KEY": "sk-mock-not-a-key", "PYTHONPATH": args.sidecar_dir}
    env.pop("COHERE_API_KEY", None)
    env.pop("COPILOT_EVAL_ENDPOINTS", None)
    for kv in args.env:
        k, _, v = kv.partition("=")
        env[k] = v
    log_path = Path(f"/tmp/sidecar-{args.label}.log")
    with log_path.open("w") as log:
        proc = subprocess.Popen([sys.executable, "-m", "uvicorn", "copilot_sidecar.app:app", "--host", "127.0.0.1", "--port", "18080"], cwd=args.sidecar_dir, env=env, stdout=log, stderr=subprocess.STDOUT)
        try:
            for _ in range(200):
                try:
                    if httpx.get("http://127.0.0.1:18080/health", timeout=1).status_code == 200:
                        break
                except httpx.HTTPError:
                    time.sleep(0.1)
            results, health, metrics = asyncio.run(load("http://127.0.0.1:18080", args.users, args.seconds, args.think, Path(args.pdf).read_bytes(), args.client_timeout))
        finally:
            proc.terminate()
            proc.wait(timeout=30)
    summary = summarise(args.label, results, health, provider, metrics, args)
    text = json.dumps(summary, indent=2)
    print(text)
    if args.out:
        Path(args.out).write_text(text + "\n")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
