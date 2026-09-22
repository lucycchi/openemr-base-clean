"""The hand-written JSON Schema contracts and the Pydantic models must agree.

Equality of the two schema texts is not a useful test (Pydantic's export
differs in shape from a hand-written draft 2020-12 document), so the test
is behavioural: the accept and reject documents in contracts/examples/ are
run through both validators, and any disagreement means the contract and
the code have drifted. The PHP side runs the same example files
(ContractExamplesTest), so one set of examples holds both implementations
to one contract.
"""

from __future__ import annotations

import json
from datetime import date

import pytest
from jsonschema import Draft202012Validator
from pydantic import ValidationError
from referencing import Registry, Resource

from copilot_sidecar import contracts, schemas

CONTRACTS = contracts.CONTRACTS_DIR
EXAMPLES = CONTRACTS / "examples"


def _registry() -> Registry:
    registry = Registry()
    for path in CONTRACTS.glob("*.schema.json"):
        doc = json.loads(path.read_text())
        resource = Resource.from_contents(doc)
        registry = registry.with_resource(doc["$id"], resource).with_resource(path.name, resource)
    return registry


def validator(name: str) -> Draft202012Validator:
    return Draft202012Validator(contracts.load(name), registry=_registry())


def examples(name: str) -> dict:
    return json.loads((EXAMPLES / f"{name}.examples.json").read_text())


MODELS = {
    "citation": schemas.Citation, "lab-report": schemas.LabReport, "intake-form": schemas.IntakeForm, "handoff": schemas.Handoff,
    "run.request": schemas.RunRequest, "run.response": schemas.RunResponse, "run.error": schemas.RunError,
    "llm.lab-proposal.output": schemas.LabReportProposal, "llm.intake-proposal.output": schemas.IntakeFormProposal,
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
    ex = examples(name)
    assert ex["accept"] and ex["reject"], "every contract needs at least one accept and one reject example"
    for doc in ex["accept"]:
        assert v.is_valid(doc), [e.message for e in v.iter_errors(doc)]
        assert _pydantic_ok(name, doc), f"pydantic rejected an accepted {name}: {doc}"
    for doc in ex["reject"]:
        assert not v.is_valid(doc), f"contract accepted a bad {name}: {doc}"
        assert not _pydantic_ok(name, doc), f"pydantic accepted a bad {name}: {doc}"


def test_every_example_file_names_a_contract() -> None:
    for path in EXAMPLES.glob("*.examples.json"):
        name = path.name[: -len(".examples.json")]
        assert (CONTRACTS / f"{name}.schema.json").is_file(), path.name


def test_model_output_round_trips_through_the_contract() -> None:
    """What the code emits must validate against the contract, including dates and aliases."""
    v = validator("run.response")
    report = schemas.LabReport.model_validate(examples("lab-report")["accept"][0])
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


@pytest.mark.parametrize("name", sorted(contracts.PROPOSAL_CONTRACT.values()))
def test_proposal_contract_is_what_the_model_is_asked_to_fill(name: str) -> None:
    """The response_format sent to OpenAI is the contract file, reduced to
    strict mode: every object lists every property as required, forbids
    extras, and carries none of the keywords strict mode rejects."""
    fmt = contracts.openai_response_format(name)
    assert fmt["json_schema"]["strict"] is True
    schema = fmt["json_schema"]["schema"]
    assert "$id" not in schema and "$schema" not in schema

    def walk(node: object) -> None:
        if isinstance(node, dict):
            assert not (set(node) & contracts.UNSUPPORTED_KEYWORDS), node
            if node.get("type") == "object":
                assert node["additionalProperties"] is False
                assert set(node["required"]) == set(node["properties"])
            for v in node.values():
                walk(v)
        elif isinstance(node, list):
            for v in node:
                walk(v)

    walk(schema)
