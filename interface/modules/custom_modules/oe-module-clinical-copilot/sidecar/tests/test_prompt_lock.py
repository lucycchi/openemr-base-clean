"""Pins the prompt constants the sidecar sends to the model.

The eval gate's deterministic cases replay recorded model replies, so an edit
to a prompt changes nothing they see and would pass the push. This test hashes
every module-level prompt constant in llm.py (SYSTEM, CRITIC_SYSTEM and every
*_TASK) and compares it with prompts.lock.json beside it; any change fails
until the lock is rewritten on purpose. A new *_TASK or *SYSTEM constant with
no lock entry fails too, so a new prompt cannot slip past the lock.

Known limit (eng review decision D10): text built inline at call time, such as
the DOCUMENT_TEXT markers in propose() and the critic's user message in
applicable(), is not hashed here.

It is a pin, not an eval. To accept a prompt change: re-record the replies the
deterministic cases use, run the live cases, then rewrite the lock inside the
sidecar and copy it back to the checkout:

    docker exec -e UPDATE_PROMPT_LOCK=1 <sidecar> python -m pytest -q tests/test_prompt_lock.py
    docker cp <sidecar>:/app/tests/prompts.lock.json \\
        interface/modules/custom_modules/oe-module-clinical-copilot/sidecar/tests/prompts.lock.json

The PHP prompts are pinned the same way by PromptLockTest.php."""

from __future__ import annotations

import hashlib
import json
import os
from pathlib import Path

import pytest

from copilot_sidecar import llm

LOCK = Path(__file__).with_name("prompts.lock.json")


def _prompt_constants() -> dict[str, str]:
    """Every upper-case string constant in llm.py whose name ends in SYSTEM or _TASK."""
    return {
        name: value
        for name, value in vars(llm).items()
        if name.isupper() and (name.endswith("SYSTEM") or name.endswith("_TASK")) and isinstance(value, str)
    }


def _read_lock() -> dict[str, str]:
    if not LOCK.exists():
        return {}
    raw = json.loads(LOCK.read_text())
    return {k: v for k, v in raw.items() if isinstance(k, str) and isinstance(v, str)}


def test_the_expected_prompts_are_discovered() -> None:
    """Guards the discovery rule itself: renaming a constant out of the pattern would unlock it."""
    assert {"SYSTEM", "LAB_TASK", "RETRY_TASK", "INTAKE_TASK", "CRITIC_SYSTEM"} <= set(_prompt_constants())


@pytest.mark.parametrize("name", sorted(_prompt_constants()))
def test_prompt_matches_the_lock(name: str) -> None:
    digest = hashlib.sha256(_prompt_constants()[name].encode()).hexdigest()
    lock = _read_lock()
    if os.environ.get("UPDATE_PROMPT_LOCK") == "1":
        lock[name] = digest
        LOCK.write_text(json.dumps(dict(sorted(lock.items())), indent=4) + "\n")
        return
    assert name in lock, f"PROMPT NOT LOCKED: {name}. Run with UPDATE_PROMPT_LOCK=1 (see this file's docstring)."
    assert lock[name] == digest, (
        f"PROMPT CHANGED: {name}. The deterministic eval cases replay recorded replies and cannot see a prompt "
        "change. Re-record the replies, run the live cases (COPILOT_GATE_LIVE=1), then rewrite the lock with "
        "UPDATE_PROMPT_LOCK=1 (see this file's docstring)."
    )


def test_the_lock_has_no_entry_for_a_prompt_that_no_longer_exists() -> None:
    assert sorted(set(_read_lock()) - set(_prompt_constants())) == []
