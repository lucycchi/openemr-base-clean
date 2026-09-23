"""The allowlist formatter is the only thing between a log call and stdout:
whatever a developer attaches to a record, only allowlisted fields are
written, exception messages become class names, and a run over the intake
fixture (which carries a name, a date of birth and a phone number) leaves
none of them in the log output.

Why: log lines go to stdout and are read by whoever operates the service;
nothing a patient could be identified by may reach them. The design chooses an
allowlist over a blocklist because a blocklist has to predict every future
mistake, while an allowlist only has to name the handful of safe fields.

Reading the test code: `logging.LogRecord(...)` builds one log entry by
hand (the positional arguments are logger name, level, file, line,
message, arguments, exception info). Writing into `record.__dict__` attaches
extra fields the way a real `log.info(..., extra={...})` call does."""

from __future__ import annotations

import json
import logging
from pathlib import Path

from copilot_sidecar import extractor
from copilot_sidecar.logging_setup import ALLOWED, AllowlistJsonFormatter, bind_correlation_id
from copilot_sidecar.schemas import IntakeFormProposal
from tools import generate_fixtures


# Pins the allowlist itself: allowed fields (correlation id, document id)
# come through, and a filename, a question and a patient name attached to
# the same record are dropped, name included. A failure means a future log
# call could leak an identifier by attaching one field too many.
def test_formatter_drops_fields_outside_the_allowlist() -> None:
    record = logging.LogRecord("t", logging.INFO, "", 0, "hello", None, None)
    record.__dict__.update({"correlation_id": "abc", "document_id": 3, "filename": "secret.pdf", "question": "is Test Zeta ok?", "patient_name": "Test Zeta"})
    out = json.loads(AllowlistJsonFormatter().format(record))
    assert out["correlation_id"] == "abc" and out["document_id"] == 3
    assert "filename" not in out and "question" not in out and "patient_name" not in out
    assert "Zeta" not in json.dumps(out)


# Pins exception handling: only the exception's class name is logged, never
# its message. Messages from libraries can quote the data being processed
# (a value, a path, a name), so the message is the likeliest leak.
# `__import__("sys").exc_info()` fetches the exception currently being
# handled, which is what the logging library attaches for a real error.
def test_exception_is_logged_as_its_class_only() -> None:
    try:
        raise ValueError("patient Test Zeta, DOB 1970-01-01")
    except ValueError:
        record = logging.LogRecord("t", logging.ERROR, "", 0, "boom", None, __import__("sys").exc_info())
    out = json.loads(AllowlistJsonFormatter().format(record))
    assert out["exception_class"] == "ValueError"
    assert "Zeta" not in json.dumps(out) and "1970" not in json.dumps(out)


# Pins the whole extraction path, not just the formatter: a real intake
# extraction (recorded proposal, no model call) is run while pytest's caplog
# captures every log record, and none of the name, date of birth, phone or
# chief concern from the form may appear in the formatted lines. Each line
# must also carry the correlation id. httpx's records are ignored because
# they are a library's, not the sidecar's, and no request is made anyway.
def test_intake_extraction_logs_carry_no_identifiers(tmp_path: Path, caplog) -> None:
    generate_fixtures.intake_full(tmp_path)
    truth = json.loads((tmp_path / "intake-full.truth.json").read_text())
    proposal = IntakeFormProposal(form_date="09/20/2026", name=truth["demographics"]["name"], dob=truth["demographics"]["dob"], sex="F", phone=truth["demographics"]["phone"],
                                  chief_concern=truth["chief_concern"], medications=[], allergies=[], family_history=[])
    bind_correlation_id("phi-test")
    with caplog.at_level(logging.INFO):
        outcome = extractor.extract(1, "intake_form", (tmp_path / "intake-full.pdf").read_bytes(), "phi-test", proposal=proposal)
    assert outcome.extraction.status == "extracted"
    lines = [json.loads(AllowlistJsonFormatter().format(r)) for r in caplog.records if not r.name.startswith("httpx")]
    rendered = json.dumps(lines)
    for phi in [truth["demographics"]["name"], truth["demographics"]["dob"], truth["demographics"]["phone"], truth["chief_concern"]]:
        assert phi not in rendered, phi
    assert lines and all(line["correlation_id"] == "phi-test" for line in lines)


# Pins that the id is supplied by the request context, not by each log
# call: a record with no id gets the bound one, and a record that names its
# own id keeps it. The final bind("") clears the context for later tests.
def test_bound_correlation_id_reaches_every_line_even_without_extra() -> None:
    """The id is structural: a log call that forgets it still carries it."""
    bind_correlation_id("ctx-0001")
    record = logging.LogRecord("t", logging.INFO, "", 0, "forgot the id", None, None)
    assert json.loads(AllowlistJsonFormatter().format(record))["correlation_id"] == "ctx-0001"
    explicit = logging.LogRecord("t", logging.INFO, "", 0, "explicit", None, None)
    explicit.__dict__["correlation_id"] = "explicit-0001"
    assert json.loads(AllowlistJsonFormatter().format(explicit))["correlation_id"] == "explicit-0001"
    bind_correlation_id("")
