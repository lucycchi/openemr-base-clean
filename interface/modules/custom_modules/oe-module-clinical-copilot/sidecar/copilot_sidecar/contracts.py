"""The JSON Schema contracts as the sidecar uses them at runtime.

The files in ../contracts/ (mounted at COPILOT_CONTRACTS_DIR) are the source
of truth for every input and output. The Pydantic models in schemas.py
conform to them and are held to them by tests/test_contracts.py through the
shared examples in contracts/examples/. The one place the sidecar reads a
contract at runtime is the model call: the proposal contract file, not a
Pydantic export, is what OpenAI is asked to fill (response_format), exactly
as the PHP side sends llm.briefing.output. Keywords OpenAI's strict mode
rejects are stripped here and enforced by the Pydantic model on the reply.
"""

from __future__ import annotations

import json
import os
from functools import lru_cache
from pathlib import Path

CONTRACTS_DIR = Path(os.environ.get("COPILOT_CONTRACTS_DIR") or Path(__file__).resolve().parents[2] / "contracts")

# Document metadata (not part of the data shape) and validation keywords OpenAI strict mode refuses.
METADATA_KEYS = {"$schema", "$id", "title"}
UNSUPPORTED_KEYWORDS = {"minLength", "maxLength", "minItems", "maxItems", "format", "pattern", "minimum", "maximum", "default"}

PROPOSAL_CONTRACT = {"lab_pdf": "llm.lab-proposal.output", "intake_form": "llm.intake-proposal.output"}


@lru_cache(maxsize=None)
def load(name: str) -> dict:
    path = CONTRACTS_DIR / f"{name}.schema.json"
    if not path.is_file():
        raise RuntimeError(f"contract not found: {name} (is {CONTRACTS_DIR} mounted?)")
    return json.loads(path.read_text())


def strict_schema(name: str) -> dict:
    """The contract reduced to what OpenAI strict mode accepts, otherwise verbatim."""

    def scrub(node: object) -> object:
        if isinstance(node, dict):
            return {k: scrub(v) for k, v in node.items() if k not in UNSUPPORTED_KEYWORDS}
        if isinstance(node, list):
            return [scrub(x) for x in node]
        return node

    doc = {k: v for k, v in load(name).items() if k not in METADATA_KEYS}
    return scrub(doc)  # type: ignore[return-value]


def openai_response_format(name: str) -> dict:
    return {"type": "json_schema", "json_schema": {"name": name.replace(".", "_").replace("-", "_"), "strict": True, "schema": strict_schema(name)}}
