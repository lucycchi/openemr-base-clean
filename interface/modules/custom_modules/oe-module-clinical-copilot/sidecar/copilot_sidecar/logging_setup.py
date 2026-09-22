"""JSON logs restricted to an allowlist of fields.

The design doc's log-field allowlist: correlation id, ids, doc type, hashes,
counts, timings, tokens, model names, status and reason codes. Anything
else attached to a log record is dropped here, so a future log line cannot
leak a filename, a question, an extracted value or an exception message
by accident. Exceptions are logged as their class name only.

The correlation id is structural, not a courtesy: app.py binds the request's
id to a context variable and the formatter writes it on every line, so a
log call that forgets it still carries it (the PHP side does the same with
CorrelatedLogger). A full run can be reconstructed from these lines alone.
"""

from __future__ import annotations

import json
import logging
import sys
import time
from contextvars import ContextVar

ALLOWED = {
    "correlation_id", "document_id", "doc_type", "sha3_512", "status", "failure_reason", "code",
    "pages", "ocr_pages", "page", "fields", "anchored", "confidence", "ms", "model", "kind", "input", "output",
    "from", "to", "reason", "count", "hops", "mode", "exception_class",
}

_correlation_id: ContextVar[str] = ContextVar("copilot_correlation_id", default="")


def bind_correlation_id(correlation_id: str) -> None:
    """Makes the id the default for every log line and model call in this request."""
    _correlation_id.set(correlation_id)


def correlation_id() -> str:
    return _correlation_id.get()


class AllowlistJsonFormatter(logging.Formatter):
    def format(self, record: logging.LogRecord) -> str:
        out = {"ts": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime(record.created)), "level": record.levelname, "logger": record.name, "event": record.getMessage()}
        bound = correlation_id()
        if bound:
            out["correlation_id"] = bound
        for k, v in record.__dict__.items():
            if k in ALLOWED:
                out[k] = v
        if record.exc_info and record.exc_info[0] is not None:
            out["exception_class"] = record.exc_info[0].__name__
        return json.dumps(out)


def setup_logging() -> None:
    handler = logging.StreamHandler(sys.stdout)
    handler.setFormatter(AllowlistJsonFormatter())
    root = logging.getLogger()
    root.handlers[:] = [handler]
    root.setLevel(logging.INFO)
    # uvicorn's access log would print the request path only; keep it, silence its default formatter.
    logging.getLogger("uvicorn.access").handlers[:] = [handler]
