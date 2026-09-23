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

How a log line is produced:

  log.info("event", extra={...})  -> Python's logging builds a record with
      the message and every extra key as attributes -> AllowlistJsonFormatter
      keeps only the allowlisted attributes, adds the bound correlation id,
      and writes one JSON object per line to standard output -> Docker
      collects standard output.

A ContextVar (context variable) is a value that follows one request through
the code without being passed as an argument: each request sees its own
value, and threads started for that request (see run_in_threadpool in
app.py) see the same one. That is what lets a log call deep inside
anchor.py carry the id that app.py bound at the start of the request.
"""

from __future__ import annotations

import json
import logging
import sys
import time
from contextvars import ContextVar

# The only record attributes that reach the log. Grouped by kind: identity
# (ids, hashes, document type), outcome (status, reason and error codes),
# counts and timings, model call accounting (model, kind, tokens), and the
# route log fields graph.py writes (from, to, reason, hops).
ALLOWED = {
    "correlation_id", "document_id", "doc_type", "sha3_512", "status", "failure_reason", "code",
    "pages", "ocr_pages", "page", "fields", "anchored", "confidence", "ms", "model", "kind", "input", "output",
    "from", "to", "reason", "count", "hops", "mode", "exception_class",
}

# The per-request slot for the correlation id. The default "" means "no id
# bound", in which case the formatter simply omits the field.
_correlation_id: ContextVar[str] = ContextVar("copilot_correlation_id", default="")


def bind_correlation_id(correlation_id: str) -> None:
    """Makes the id the default for every log line and model call in this request."""
    _correlation_id.set(correlation_id)


def correlation_id() -> str:
    """The id bound for the current request, or "" when none is."""
    return _correlation_id.get()


class AllowlistJsonFormatter(logging.Formatter):
    """Turns a log record into one line of JSON containing only allowlisted
    fields. A Formatter is the object Python's logging asks to render each
    record; subclassing it and overriding format() replaces the rendering."""
    def format(self, record: logging.LogRecord) -> str:
        # The fixed part of every line: UTC timestamp, level, logger name and
        # the event text (the message passed to log.info).
        out = {"ts": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime(record.created)), "level": record.levelname, "logger": record.name, "event": record.getMessage()}
        bound = correlation_id()
        if bound:
            out["correlation_id"] = bound
        # Every key passed as extra={...} lands on the record as an attribute,
        # alongside logging's own attributes. record.__dict__ is that whole
        # attribute dictionary; only allowlisted names are copied out.
        for k, v in record.__dict__.items():
            if k in ALLOWED:
                out[k] = v
        # When the call carried an exception, name its class only; the
        # message and traceback stay out of the log on purpose.
        if record.exc_info and record.exc_info[0] is not None:
            out["exception_class"] = record.exc_info[0].__name__
        return json.dumps(out)


def setup_logging() -> None:
    """Installs the allowlist formatter as the only handler on the root
    logger, at INFO level, writing to standard output. Called once by app.py
    before the first log line."""
    handler = logging.StreamHandler(sys.stdout)
    handler.setFormatter(AllowlistJsonFormatter())
    root = logging.getLogger()
    # `handlers[:] = [...]` replaces the contents of the existing list, so any
    # handler another library installed earlier is removed rather than kept.
    root.handlers[:] = [handler]
    root.setLevel(logging.INFO)
    # uvicorn's access log would print the request path only; keep it, silence its default formatter.
    logging.getLogger("uvicorn.access").handlers[:] = [handler]
