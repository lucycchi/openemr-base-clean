"""The multi-agent graph: one supervisor, two workers, built with LangGraph.

Nodes
  supervisor          decides where to go next from the state alone (no model):
                      a stored document of a supported type -> intake_extractor,
                      a question -> evidence_retriever, otherwise done. Each
                      decision is appended to the handoff log with a reason code.
  intake_extractor    parses, proposes (model), anchors; runs at most once per run
  evidence_retriever  hybrid retrieval over the guideline corpus; at most once per run

Edges
  START -> supervisor; supervisor -(conditional on state["next"])-> a worker or END;
  each worker -> supervisor.

Why deterministic routing: every route in this system is decidable from the
state, so asking a model where to go would add latency, cost and a second
failure mode for nothing (Codex review, design decision D12). LangGraph is
still the runtime because it makes the run inspectable: the compiled graph
can be drawn, every hop is a Handoff in the response, and PHP writes each
one to the Langfuse trace.

The workers are injected (build_graph(extract, retrieve)) so the eval
harness can run the same graph with stubs and score only the routing.
"""

from __future__ import annotations

import time
from typing import Callable, TypedDict

from langgraph.graph import END, START, StateGraph

from . import extractor
from .schemas import Chunk, Extraction, Handoff, RunDocument, Usage

SUPPORTED_DOC_TYPES = {"lab_pdf", "intake_form"}

ExtractFn = Callable[[RunDocument, str], tuple[Extraction, list[Usage]]]
RetrieveFn = Callable[[str], tuple[list[Chunk], list[Usage]]]


class RunState(TypedDict, total=False):
    mode: str
    correlation_id: str
    facts_hash: str
    question: str | None
    documents: list[RunDocument]
    extractions: list[Extraction]
    chunks: list[Chunk]
    handoffs: list[Handoff]
    usage: list[Usage]
    next: str
    extracted_once: bool
    retrieved_once: bool
    _t: float  # monotonic start of the current hop


def _hop(state: RunState, from_: str, to: str, reason: str, changed: list[str]) -> None:
    now = time.monotonic()
    state.setdefault("handoffs", []).append(
        Handoff(**{"from": from_, "to": to, "reason": reason, "state_keys_changed": changed, "ms": int((now - state.get("_t", now)) * 1000)})
    )
    state["_t"] = now


def supervisor_node(state: RunState) -> RunState:
    state.setdefault("_t", time.monotonic())
    stored = [d for d in state.get("documents", []) if d.status == "stored"]
    supported = [d for d in stored if d.doc_type in SUPPORTED_DOC_TYPES]
    if state.get("mode") == "extract" and not state.get("extracted_once"):
        if supported:
            _hop(state, "supervisor", "intake_extractor", "stored_document", [])
            state["next"] = "intake_extractor"
            return state
        if stored:
            _hop(state, "supervisor", "done", "unsupported_doc_type", [])
            state["next"] = END
            return state
        _hop(state, "supervisor", "done", "already_extracted" if state.get("documents") else "no_documents", [])
        state["next"] = END
        return state
    if state.get("mode") == "answer" and state.get("question") and not state.get("retrieved_once"):
        _hop(state, "supervisor", "evidence_retriever", "question_present", [])
        state["next"] = "evidence_retriever"
        return state
    # Back from a worker, or nothing left to do.
    reason = "worker_finished" if state.get("retrieved_once") else "no_question"
    _hop(state, "supervisor", "done", reason, [])
    state["next"] = END
    return state


def make_extractor_node(extract: ExtractFn) -> Callable[[RunState], RunState]:
    def node(state: RunState) -> RunState:
        ok = False
        for d in state.get("documents", []):
            if d.status != "stored" or d.doc_type not in SUPPORTED_DOC_TYPES:
                continue
            extraction, usage = extract(d, state.get("correlation_id", ""))
            state.setdefault("extractions", []).append(extraction)
            state.setdefault("usage", []).extend(usage)
            ok = ok or extraction.status == "extracted"
        state["extracted_once"] = True
        _hop(state, "intake_extractor", "supervisor", "worker_finished" if ok else "worker_failed", ["extractions", "usage"])
        return state

    return node


def make_retriever_node(retrieve: RetrieveFn) -> Callable[[RunState], RunState]:
    def node(state: RunState) -> RunState:
        try:
            chunks, usage = retrieve(state.get("question") or "")
            state["chunks"] = chunks
            state.setdefault("usage", []).extend(usage)
            reason = "worker_finished"
        except Exception:
            state["chunks"] = []
            reason = "worker_failed"
        state["retrieved_once"] = True
        _hop(state, "evidence_retriever", "supervisor", reason, ["chunks", "usage"])
        return state

    return node


def build_graph(extract: ExtractFn, retrieve: RetrieveFn):
    g = StateGraph(RunState)
    g.add_node("supervisor", supervisor_node)
    g.add_node("intake_extractor", make_extractor_node(extract))
    g.add_node("evidence_retriever", make_retriever_node(retrieve))
    g.add_edge(START, "supervisor")
    g.add_conditional_edges("supervisor", lambda s: s["next"], {"intake_extractor": "intake_extractor", "evidence_retriever": "evidence_retriever", END: END})
    g.add_edge("intake_extractor", "supervisor")
    g.add_edge("evidence_retriever", "supervisor")
    return g.compile()


# ---- Production workers ----------------------------------------------------


def real_extract(doc: RunDocument, correlation_id: str) -> tuple[Extraction, list[Usage]]:
    try:
        data = extractor.decode(doc.bytes_base64)
    except Exception:
        return Extraction(document_id=doc.document_id, status="failed", failure_reason="unreadable", extraction=None, confidence=0.0), []
    outcome = extractor.extract(doc.document_id, doc.doc_type, data, correlation_id)
    return outcome.extraction, outcome.usage


def no_retrieve(question: str) -> tuple[list[Chunk], list[Usage]]:
    """Placeholder until Phase 6 lands the retriever: no chunks, no usage."""
    return [], []


_graph = None


def production_graph():
    global _graph
    if _graph is None:
        fn: RetrieveFn = no_retrieve
        try:
            from . import retrieve as retrieve_module  # Phase 6

            fn = retrieve_module.retrieve
        except ImportError:
            pass
        _graph = build_graph(real_extract, fn)
    return _graph


def run(mode: str, correlation_id: str, facts_hash: str, question: str | None, documents: list[RunDocument], graph=None) -> RunState:
    state: RunState = {"mode": mode, "correlation_id": correlation_id, "facts_hash": facts_hash, "question": question, "documents": documents,
                       "extractions": [], "chunks": [], "handoffs": [], "usage": [], "extracted_once": False, "retrieved_once": False}
    g = graph or production_graph()
    return g.invoke(state)  # type: ignore[return-value]


def stub_extract(doc: RunDocument, correlation_id: str) -> tuple[Extraction, list[Usage]]:
    """Eval stub: succeeds without a model so routing can be scored alone."""
    return Extraction(document_id=doc.document_id, status="extracted", failure_reason=None, extraction=None, confidence=1.0), []


def stub_retrieve(question: str) -> tuple[list[Chunk], list[Usage]]:
    return [], []
