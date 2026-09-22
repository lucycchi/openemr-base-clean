"""The supervisor: deterministic routing with a logged handoff per hop.

Every route in this system is decidable from the run state (is there a
stored document? is there a question?), so the supervisor never asks a
model where to go; that keeps routing exact, free and testable. Phase 5
of the plan mounts these same functions as nodes of a LangGraph
StateGraph so the run is inspectable in the standard tooling; the
Handoff log is the contract either way.
"""

from __future__ import annotations

import time
from dataclasses import dataclass, field

from . import extractor
from .schemas import Chunk, Extraction, Handoff, RunDocument, Usage

SUPPORTED_DOC_TYPES = {"lab_pdf", "intake_form"}


@dataclass
class RunState:
    mode: str
    correlation_id: str
    facts_hash: str
    question: str | None
    documents: list[RunDocument]
    extractions: list[Extraction] = field(default_factory=list)
    chunks: list[Chunk] = field(default_factory=list)
    handoffs: list[Handoff] = field(default_factory=list)
    usage: list[Usage] = field(default_factory=list)

    def hop(self, from_: str, to: str, reason: str, changed: list[str], started: float) -> None:
        self.handoffs.append(Handoff(**{"from": from_, "to": to, "reason": reason, "state_keys_changed": changed, "ms": int((time.monotonic() - started) * 1000)}))


def run(state: RunState, retriever=None) -> RunState:
    """Supervisor loop. Order: documents first (facts must exist before an
    answer can cite them), then the question, then done."""
    t = time.monotonic()
    pending = [d for d in state.documents if d.status == "stored"]
    if state.mode == "extract" and pending:
        state.hop("supervisor", "intake_extractor", "stored_document", [], t)
        t = time.monotonic()
        for d in pending:
            if d.doc_type not in SUPPORTED_DOC_TYPES:
                state.extractions.append(Extraction(document_id=d.document_id, status="failed", failure_reason="schema_mismatch", extraction=None, confidence=0.0))
                continue
            try:
                data = extractor.decode(d.bytes_base64)
            except Exception:
                state.extractions.append(Extraction(document_id=d.document_id, status="failed", failure_reason="unreadable", extraction=None, confidence=0.0))
                continue
            outcome = extractor.extract(d.document_id, d.doc_type, data, state.correlation_id)
            state.extractions.append(outcome.extraction)
            state.usage.extend(outcome.usage)
        ok = any(e.status == "extracted" for e in state.extractions)
        state.hop("intake_extractor", "supervisor", "worker_finished" if ok else "worker_failed", ["extractions", "usage"], t)
        t = time.monotonic()
    elif state.mode == "extract":
        state.hop("supervisor", "done", "already_extracted" if state.documents else "no_documents", [], t)
        return state

    if state.mode == "answer" and state.question:
        state.hop("supervisor", "evidence_retriever", "question_present", [], t)
        t = time.monotonic()
        if retriever is not None:
            chunks, usage = retriever(state.question)
            state.chunks = chunks
            state.usage.extend(usage)
        state.hop("evidence_retriever", "supervisor", "worker_finished", ["chunks", "usage"], t)
        t = time.monotonic()
        state.hop("supervisor", "done", "worker_finished", [], t)
        return state

    state.hop("supervisor", "done", "no_question", [], t)
    return state
