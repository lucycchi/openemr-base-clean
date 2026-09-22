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
from copilot_sidecar.logging_setup import ALLOWED, AllowlistJsonFormatter
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
    with caplog.at_level(logging.INFO):
        outcome = extractor.extract(1, "intake_form", (tmp_path / "intake-full.pdf").read_bytes(), "phi-test", proposal=proposal)
    assert outcome.extraction.status == "extracted"
    rendered = "\n".join(AllowlistJsonFormatter().format(r) for r in caplog.records)
    for phi in [truth["demographics"]["name"], truth["demographics"]["dob"], truth["demographics"]["phone"], truth["chief_concern"]]:
        assert phi not in rendered, phi
    for r in caplog.records:
        extras = {k for k in r.__dict__ if k in ALLOWED}
        assert "correlation_id" in extras or r.name.startswith("httpx")
