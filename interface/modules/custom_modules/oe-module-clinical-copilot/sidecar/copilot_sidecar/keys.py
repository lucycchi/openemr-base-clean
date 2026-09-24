"""Model API keys, read per request.

The deterministic eval cases replay recorded model output and must not depend
on the network. But the dev stack's sidecar normally holds real keys (the live
cases and the app need them), and retrieval reranks through Cohere whenever
COHERE_API_KEY is set, so those cases used to call Cohere on every push.

The eval harness therefore marks each deterministic request with the header
X-Eval-Keyless: 1. The app's middleware binds that here, and model_key()
then answers "" for the rest of that request only: no rerank, no embeddings
call, exactly as on a machine with no .env. Other requests, and the live
cases, see the real keys. The header is honoured only while the test-only
eval endpoints are enabled (COPILOT_EVAL_ENDPOINTS=1), so a deployed sidecar
ignores it.

A ContextVar follows one request through the code, the same mechanism the
correlation id uses (logging_setup.py).
"""

from __future__ import annotations

import os
from contextvars import ContextVar

_keyless: ContextVar[bool] = ContextVar("copilot_keyless", default=False)


def bind_keyless(keyless: bool) -> None:
    """Marks the current request as keyless (or not)."""
    _keyless.set(keyless)


def keyless() -> bool:
    """True while serving a request the eval harness marked keyless."""
    return _keyless.get()


def model_key(name: str) -> str:
    """The named API key from the environment, or "" during a keyless request."""
    return "" if _keyless.get() else os.environ.get(name, "")
