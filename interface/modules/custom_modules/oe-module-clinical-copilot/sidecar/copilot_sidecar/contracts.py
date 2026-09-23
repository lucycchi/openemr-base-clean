"""The JSON Schema contracts as the sidecar uses them at runtime.

The files in ../contracts/ (mounted at COPILOT_CONTRACTS_DIR) are the source
of truth for every input and output. The Pydantic models in schemas.py
conform to them and are held to them by tests/test_contracts.py through the
shared examples in contracts/examples/. The one place the sidecar reads a
contract at runtime is the model call: the proposal contract file, not a
Pydantic export, is what OpenAI is asked to fill (response_format), exactly
as the PHP side sends llm.briefing.output. Keywords OpenAI's strict mode
rejects are stripped here and enforced by the Pydantic model on the reply.

In plain words: a JSON Schema is a document that describes the exact shape
another document must have (which keys, which types, which are required).
OpenAI's "strict mode" makes the model's reply obey such a schema at the
provider, but it accepts only a subset of the schema language. So:

  contract file -> load (read once, cached) -> strict_schema (drop the
      keywords strict mode refuses) -> openai_response_format (wrap in the
      envelope the API expects) -> llm.propose sends it with the request

The dropped rules (minimum length, patterns, ...) are not lost: the Pydantic
proposal model in schemas.py re-checks the reply against all of them.
"""

from __future__ import annotations

import json
import os
from functools import lru_cache
from pathlib import Path

# Where the contract files live: the COPILOT_CONTRACTS_DIR environment
# variable when set (the Docker mount), otherwise two directories above this
# file, which is the module root where contracts/ sits in the source tree.
CONTRACTS_DIR = Path(os.environ.get("COPILOT_CONTRACTS_DIR") or Path(__file__).resolve().parents[2] / "contracts")

# Document metadata (not part of the data shape) and validation keywords OpenAI strict mode refuses.
METADATA_KEYS = {"$schema", "$id", "title"}
UNSUPPORTED_KEYWORDS = {"minLength", "maxLength", "minItems", "maxItems", "format", "pattern", "minimum", "maximum", "default"}

# Which contract file describes the model's reply for each document type.
PROPOSAL_CONTRACT = {"lab_pdf": "llm.lab-proposal.output", "intake_form": "llm.intake-proposal.output"}


# `@lru_cache` remembers the result for each name it has seen, so a contract
# file is read from disk once per process; later calls (every model call,
# every /ready probe) get the parsed copy back.
@lru_cache(maxsize=None)
def load(name: str) -> dict:
    """Reads and parses `<name>.schema.json`. A missing file is a
    configuration error worth a clear message; /ready reports it as
    "contracts unavailable" without exposing the path."""
    path = CONTRACTS_DIR / f"{name}.schema.json"
    if not path.is_file():
        raise RuntimeError(f"contract not found: {name} (is {CONTRACTS_DIR} mounted?)")
    return json.loads(path.read_text())


def strict_schema(name: str) -> dict:
    """The contract reduced to what OpenAI strict mode accepts, otherwise verbatim."""

    # scrub walks the schema tree: dictionaries lose the unsupported keys and
    # are walked deeper, lists are walked item by item, anything else (a
    # string, a number, true/false) is returned as is. A function that calls
    # itself like this is how nested data of unknown depth is handled.
    def scrub(node: object) -> object:
        if isinstance(node, dict):
            return {k: scrub(v) for k, v in node.items() if k not in UNSUPPORTED_KEYWORDS}
        if isinstance(node, list):
            return [scrub(x) for x in node]
        return node

    # The top-level metadata keys ($schema, $id, title) are dropped first;
    # they describe the file, not the data.
    doc = {k: v for k, v in load(name).items() if k not in METADATA_KEYS}
    return scrub(doc)  # type: ignore[return-value]


def openai_response_format(name: str) -> dict:
    """The `response_format` argument for the chat call: the scrubbed schema
    inside the JSON-schema envelope, with strict=True so the provider itself
    refuses a reply outside it. Dots and dashes in the contract name are
    replaced with underscores so the schema name is a plain identifier."""
    return {"type": "json_schema", "json_schema": {"name": name.replace(".", "_").replace("-", "_"), "strict": True, "schema": strict_schema(name)}}
