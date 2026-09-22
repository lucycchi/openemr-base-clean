"""The allowlist formatter is the only thing between a log call and stdout:
whatever a developer attaches to a record, only allowlisted fields are
written, exception messages become class names, and a run over the intake
fixture (which carries a name, a date of birth and a phone number) leaves
none of them in the log output."""

from __future__ import annotations

import json
import logging
from pathlib import Path

from copilot_sidecar import extractor
from copilot_sidecar.logging_setup import ALLOWED, AllowlistJsonFormatter, bind_correlation_id
from copilot_sidecar.schemas import IntakeFormProposal
from tools import generate_fixtures


def test_formatter_drops_fields_outside_the_allowlist() -> None:
    record = logging.LogRecord("t", logging.INFO, "", 0, "hello", None, None)
    record.__dict__.update({"correlation_id": "abc", "document_id": 3, "filename": "secret.pdf", "question": "is Test Zeta ok?", "patient_name": "Test Zeta"})
    out = json.loads(AllowlistJsonFormatter().format(record))
    assert out["correlation_id"] == "abc" and out["document_id"] == 3
    assert "filename" not in out and "question" not in out and "patient_name" not in out
    assert "Zeta" not in json.dumps(out)


def test_exception_is_logged_as_its_class_only() -> None:
    try:
        raise ValueError("patient Test Zeta, DOB 1970-01-01")
    except ValueError:
        record = logging.LogRecord("t", logging.ERROR, "", 0, "boom", None, __import__("sys").exc_info())
    out = json.loads(AllowlistJsonFormatter().format(record))
    assert out["exception_class"] == "ValueError"
    assert "Zeta" not in json.dumps(out) and "1970" not in json.dumps(out)


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


def test_bound_correlation_id_reaches_every_line_even_without_extra() -> None:
    """The id is structural: a log call that forgets it still carries it."""
    bind_correlation_id("ctx-0001")
    record = logging.LogRecord("t", logging.INFO, "", 0, "forgot the id", None, None)
    assert json.loads(AllowlistJsonFormatter().format(record))["correlation_id"] == "ctx-0001"
    explicit = logging.LogRecord("t", logging.INFO, "", 0, "explicit", None, None)
    explicit.__dict__["correlation_id"] = "explicit-0001"
    assert json.loads(AllowlistJsonFormatter().format(explicit))["correlation_id"] == "explicit-0001"
    bind_correlation_id("")
