"""The hand-written JSON Schema contracts and the Pydantic models must agree.

Equality of the two schema texts is not a useful test (Pydantic's export
differs in shape from a hand-written draft 2020-12 document), so the test
is behavioural: the accept and reject documents in contracts/examples/ are
run through both validators, and any disagreement means the contract and
the code have drifted. The PHP side runs the same example files
(ContractExamplesTest), so one set of examples holds both implementations
to one contract.

Why it matters: the contract files are what PHP and the sidecar agreed on.
If the Python models accepted something the contract forbids (or the other
way round), a response could pass on one side and be rejected on the other,
and a clinician would see "extraction failed" for a document that was read
perfectly. The last test also guards the schema the model itself is asked
to fill: OpenAI's strict mode refuses certain keywords, and a contract that
carried one would fail every extraction at the model call.
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


# Contract files refer to each other (a lab report contains citations, a run
# response contains lab reports). The registry loads every schema file once
# and lets a `$ref` find it by either its `$id` or its file name.
def _registry() -> Registry:
    registry = Registry()
    for path in CONTRACTS.glob("*.schema.json"):
        doc = json.loads(path.read_text())
        resource = Resource.from_contents(doc)
        registry = registry.with_resource(doc["$id"], resource).with_resource(path.name, resource)
    return registry


# A JSON Schema checker for one named contract, able to follow references.
def validator(name: str) -> Draft202012Validator:
    return Draft202012Validator(contracts.load(name), registry=_registry())


# The shared example file for a contract: {"accept": [...], "reject": [...]}.
def examples(name: str) -> dict:
    return json.loads((EXAMPLES / f"{name}.examples.json").read_text())


# Which Pydantic model implements which contract file. Every entry is tested.
MODELS = {
    "citation": schemas.Citation, "lab-report": schemas.LabReport, "intake-form": schemas.IntakeForm, "handoff": schemas.Handoff,
    "run.request": schemas.RunRequest, "run.response": schemas.RunResponse, "run.error": schemas.RunError,
    "llm.lab-proposal.output": schemas.LabReportProposal, "llm.intake-proposal.output": schemas.IntakeFormProposal,
    "sidecar.health.response": schemas.SidecarHealth, "sidecar.ready.response": schemas.SidecarReady,
    "llm.critic.output": schemas.CriticVerdict,
}


# Does the Python model accept this document? Pydantic raises on rejection;
# a plain ValueError covers the model's own post-validation checks (an
# anchored citation without a box, for instance).
def _pydantic_ok(name: str, doc: dict) -> bool:
    try:
        MODELS[name].model_validate(doc)
        return True
    except (ValidationError, ValueError):
        return False


# Pins, once per contract: every "accept" example passes both the schema
# file and the Python model, and every "reject" example fails both. A
# failure names the first document the two sides disagree on, which is
# exactly the document that would break the PHP <-> sidecar boundary.
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


# Pins housekeeping: an example file with no matching schema file is a
# renamed or deleted contract whose examples would otherwise test nothing.
def test_every_example_file_names_a_contract() -> None:
    for path in EXAMPLES.glob("*.examples.json"):
        name = path.name[: -len(".examples.json")]
        assert (CONTRACTS / f"{name}.schema.json").is_file(), path.name


# Pins the other direction: not just that the models accept the examples,
# but that what the sidecar actually sends (a real RunResponse serialised
# the way app.py serialises it, with "from" spelled as the contract wants
# and dates as ISO strings) passes the run.response contract.
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
    # by_alias=True writes the Python field `from_` as the JSON key "from"
    # ("from" is a reserved word in Python, so the model cannot use it directly).
    doc = json.loads(resp.model_dump_json(by_alias=True))
    assert v.is_valid(doc), [e.message for e in v.iter_errors(doc)]
    assert isinstance(report.collection_date, date)


# Pins the schema handed to OpenAI for each proposal contract: strict mode
# on, document metadata stripped, no forbidden keywords anywhere, and every
# object requiring all of its properties with no extras. Strict mode is what
# makes the provider itself refuse a reply outside the schema; a contract
# edit that broke these rules would fail every extraction at the model call.
@pytest.mark.parametrize("name", sorted(contracts.PROPOSAL_CONTRACT.values()))
def test_proposal_contract_is_what_the_model_is_asked_to_fill(name: str) -> None:
    """The response_format sent to OpenAI is the contract file, reduced to
    strict mode: every object lists every property as required, forbids
    extras, and carries none of the keywords strict mode rejects."""
    fmt = contracts.openai_response_format(name)
    assert fmt["json_schema"]["strict"] is True
    schema = fmt["json_schema"]["schema"]
    assert "$id" not in schema and "$schema" not in schema

    # A schema is nested (objects inside lists inside objects). `walk` visits
    # every level by calling itself on each child, checking objects as it goes.
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


# -- brief mode ---------------------------------------------------------------

def _brief(**over) -> dict:
    base = {"mode": "brief", "correlation_id": "abcdefgh-0002", "facts_hash": "0" * 64, "question": None, "documents": [],
            "queries": [{"trigger_id": "lipids", "query": "statin indication and intensity when LDL cholesterol is above goal"}]}
    base.update(over)
    return base


def test_run_request_accepts_brief_with_queries_and_rejects_brief_with_a_question() -> None:
    v = validator("run.request")
    assert v.is_valid(_brief())
    schemas.RunRequest.model_validate(_brief())
    with pytest.raises(ValidationError):
        schemas.RunRequest.model_validate(_brief(question="what about statins?"))
    with pytest.raises(ValidationError):
        schemas.RunRequest.model_validate(_brief(mode="answer", question="x"))  # queries only travel with brief


def test_handoff_reasons_and_run_modes_match_the_contracts() -> None:
    import typing

    handoff = json.loads((CONTRACTS / "handoff.schema.json").read_text())["properties"]
    assert set(typing.get_args(schemas.HandoffReason)) == set(handoff["reason"]["enum"])
    assert set(typing.get_args(schemas.Node)) == set(handoff["from"]["enum"])
    request = json.loads((CONTRACTS / "run.request.schema.json").read_text())["properties"]
    assert set(typing.get_args(schemas.RunRequest.model_fields["mode"].annotation)) == set(request["mode"]["enum"]) == {"extract", "answer", "brief"}


def test_brief_response_with_evidence_round_trips_through_the_contract() -> None:
    v = validator("run.response")
    chunk = schemas.Chunk(chunk_id="a1b2c3d4e5f6", source_id="acc-aha-2018-cholesterol", section="Title > Statins", quote="A statin is recommended.", score=0.9)
    resp = schemas.RunResponse(
        correlation_id="abcdefgh-2", extractions=[], chunks=[],
        evidence=[schemas.TriggerEvidence(trigger_id="lipids", chunks=[chunk])],
        handoffs=[schemas.Handoff(**{"from": "supervisor", "to": "evidence_retriever", "reason": "chart_triggers", "state_keys_changed": [], "ms": 1})],
        usage=[],
    )
    doc = json.loads(resp.model_dump_json(by_alias=True))
    assert v.is_valid(doc), [e.message for e in v.iter_errors(doc)]
    assert doc["evidence"][0]["applicable"] is None


def test_run_request_carries_patient_context_and_facts_only_in_brief_mode() -> None:
    v = validator("run.request")
    body = _brief(patient={"age": 55, "sex": "M"}, facts=["LDL Cholesterol 165 mg/dL on 2026-09-10"])
    assert v.is_valid(body), [e.message for e in v.iter_errors(body)]
    schemas.RunRequest.model_validate(body)
    bad = {"mode": "answer", "correlation_id": "abcdefgh-0003", "facts_hash": "0" * 64, "question": "x", "documents": [], "facts": ["a fact"]}
    assert not v.is_valid(bad)
    with pytest.raises(ValidationError):
        schemas.RunRequest.model_validate(bad)


def test_critic_output_contract_is_strict_and_matches_its_model() -> None:
    fmt = contracts.openai_response_format("llm.critic.output")
    assert fmt["json_schema"]["strict"] is True
    schema = fmt["json_schema"]["schema"]
    assert set(schema["required"]) == {"applicable", "reason"} and schema["additionalProperties"] is False
    schemas.CriticVerdict.model_validate({"applicable": True, "reason": "no restriction stated"})
    with pytest.raises(ValidationError):
        schemas.CriticVerdict.model_validate({"applicable": "yes", "reason": "x"})
