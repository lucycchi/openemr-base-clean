"""The hand-written JSON Schema contracts and the Pydantic models must agree.

Equality of the two schema texts is not a useful test (Pydantic's export
differs in shape from a hand-written draft 2020-12 document), so the test
is behavioural: a set of documents that must be accepted and a set that
must be rejected, run through both validators. If either disagrees with
the other, the contract and the code have drifted.
"""

from __future__ import annotations

import json
import os
from datetime import date
from pathlib import Path

import pytest
from jsonschema import Draft202012Validator
from pydantic import ValidationError
from referencing import Registry, Resource

from copilot_sidecar import schemas

CONTRACTS = Path(os.environ.get("COPILOT_CONTRACTS_DIR") or Path(__file__).resolve().parents[2] / "contracts")


def _registry() -> Registry:
    registry = Registry()
    for path in CONTRACTS.glob("*.schema.json"):
        doc = json.loads(path.read_text())
        resource = Resource.from_contents(doc)
        registry = registry.with_resource(doc["$id"], resource).with_resource(path.name, resource)
    return registry


def validator(name: str) -> Draft202012Validator:
    doc = json.loads((CONTRACTS / f"{name}.schema.json").read_text())
    return Draft202012Validator(doc, registry=_registry())


def cite(anchored: bool = True, source_type: str = "document") -> dict:
    bbox = {"page": 1, "x0": 10, "y0": 10, "x1": 50, "y1": 20, "origin": "top-left", "units": "pt", "page_w": 612, "page_h": 792}
    c = {"source_type": source_type, "source_id": "1", "page_or_section": "1", "field_or_chunk_id": "/results/0/value", "quote_or_value": "92", "anchored": anchored}
    if anchored and source_type == "document":
        c["bbox"] = bbox
    return c


def lab_report() -> dict:
    return {
        "doc_type": "lab_pdf", "patient_name_on_report": "Test Zeta", "collection_date": "2026-09-15",
        "collection_date_citation": cite(), "reported_date": None, "reported_date_citation": None, "lab_name": "Synthetic Labs",
        "results": [{"analyte": "Glucose", "loinc": "2345-7", "value": "92", "unit": "mg/dL", "reference_range": "70-99", "abnormal_flag": None, "unit_mismatch": False, "citation": cite()}],
        "unextracted": [],
    }


def intake_form() -> dict:
    return {
        "doc_type": "intake_form", "form_date": None, "form_date_citation": None,
        "demographics": {"name": None, "dob": None, "sex": None, "phone": None},
        "chief_concern": {"value": "chest tightness on stairs", "citation": cite()},
        "medications": [{"name": "lisinopril", "dose": "10 mg", "frequency": "daily", "citation": cite()}],
        "allergies": [], "family_history": [{"relative": "father", "condition": "MI at 55", "citation": cite()}],
    }


ACCEPT = {
    "citation": [cite(), cite(False), cite(True, "guideline")],
    "lab-report": [lab_report()],
    "intake-form": [intake_form()],
    "handoff": [{"from": "supervisor", "to": "intake_extractor", "reason": "stored_document", "state_keys_changed": [], "ms": 3}],
    "run.request": [{"mode": "extract", "correlation_id": "abcdefgh-1", "facts_hash": "0" * 64, "question": None, "documents": [{"document_id": 1, "doc_type": "lab_pdf", "status": "stored", "sha3_512": "a" * 128, "bytes_base64": "JVBERi0="}]}],
    "run.response": [{"correlation_id": "abcdefgh-1", "extractions": [{"document_id": 1, "status": "extracted", "failure_reason": None, "extraction": lab_report(), "confidence": 1.0}], "chunks": [], "handoffs": [], "usage": [{"model": "gpt-4o-mini", "kind": "chat", "input": 10, "output": 5}]}],
    "run.error": [{"correlation_id": "abcdefgh-1", "code": "timeout"}],
}

REJECT = {
    "citation": [
        {**cite(), "anchored": True, "bbox": None} | {"source_type": "document"},  # anchored document without bbox
        {**cite(), "source_type": "chart", "extra": 1},
        {**cite(), "source_id": ""},
    ],
    "lab-report": [
        {**lab_report(), "results": []},
        {**lab_report(), "results": [{**lab_report()["results"][0], "abnormal_flag": "X"}]},
        {**lab_report(), "doc_type": "intake_form"},
        {k: v for k, v in lab_report().items() if k != "collection_date"},
    ],
    "intake-form": [
        {**intake_form(), "chief_concern": {"value": "", "citation": cite()}},
        {**intake_form(), "medications": [{"name": "x", "citation": cite()}]},
    ],
    "handoff": [{"from": "supervisor", "to": "nowhere", "reason": "stored_document", "state_keys_changed": [], "ms": 0}],
    "run.request": [{"mode": "route", "correlation_id": "abcdefgh-1", "facts_hash": "0" * 64, "question": None, "documents": []}],
    "run.response": [{"correlation_id": "x", "extractions": [], "chunks": [{"chunk_id": "c"}], "handoffs": [], "usage": []}],
    "run.error": [{"correlation_id": "x", "code": "boom"}],
}

MODELS = {
    "citation": schemas.Citation, "lab-report": schemas.LabReport, "intake-form": schemas.IntakeForm, "handoff": schemas.Handoff,
    "run.request": schemas.RunRequest, "run.response": schemas.RunResponse, "run.error": schemas.RunError,
}


def _pydantic_ok(name: str, doc: dict) -> bool:
    try:
        MODELS[name].model_validate(doc)
        return True
    except (ValidationError, ValueError):
        return False


@pytest.mark.parametrize("name", sorted(MODELS))
def test_contract_and_model_accept_the_same_documents(name: str) -> None:
    v = validator(name)
    for doc in ACCEPT[name]:
        assert v.is_valid(doc), [e.message for e in v.iter_errors(doc)]
        assert _pydantic_ok(name, doc), f"pydantic rejected an accepted {name}"
    for doc in REJECT[name]:
        assert not v.is_valid(doc), f"contract accepted a bad {name}: {doc}"
        assert not _pydantic_ok(name, doc), f"pydantic accepted a bad {name}: {doc}"


def test_model_output_round_trips_through_the_contract() -> None:
    """What the code emits must validate against the contract, including dates and aliases."""
    v = validator("run.response")
    report = schemas.LabReport.model_validate(lab_report())
    resp = schemas.RunResponse(
        correlation_id="abcdefgh-1",
        extractions=[schemas.Extraction(document_id=1, status="extracted", failure_reason=None, extraction=report, confidence=1.0)],
        chunks=[],
        handoffs=[schemas.Handoff(**{"from": "supervisor", "to": "done", "reason": "no_question", "state_keys_changed": [], "ms": 1})],
        usage=[],
    )
    doc = json.loads(resp.model_dump_json(by_alias=True))
    assert v.is_valid(doc), [e.message for e in v.iter_errors(doc)]
    assert isinstance(report.collection_date, date)


def test_openai_strict_schema_shape() -> None:
    s = schemas.openai_strict_schema(schemas.LabReportProposal)
    assert s["additionalProperties"] is False
    assert set(s["required"]) == set(s["properties"])
    assert "format" not in json.dumps(s)
