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

The same graph as a picture:

  START -> supervisor --+--> intake_extractor ---> supervisor --> END
                        +--> evidence_retriever -> supervisor --> END
                        +--> END

In plain words, for readers new to LangGraph: a "node" is an ordinary
function that receives the shared state (a dictionary), may change it, and
returns it. An "edge" says which node runs after which. A "conditional
edge" reads a value from the state (here state["next"]) to choose. The
supervisor is the only node that chooses, and it chooses by looking at the
state with plain if/else rules, never by asking a model. A "handoff" is one
recorded step from one node to the next, with the reason and the time
taken, so a run can be replayed from its route log.
"""

from __future__ import annotations

import logging
import time
from typing import Callable, TypedDict

from langgraph.graph import END, START, StateGraph

from . import extractor
from .schemas import Chunk, Extraction, Handoff, RunDocument, Usage

log = logging.getLogger("copilot.graph")

# The document types the extractor knows how to anchor. A stored document of
# any other type is routed to "done" with reason unsupported_doc_type.
SUPPORTED_DOC_TYPES = {"lab_pdf", "intake_form"}

# The shapes of the two injectable workers: extract takes a document and the
# correlation id and returns its Extraction plus usage; retrieve takes the
# question and returns chunks plus usage. Any function of that shape will do,
# which is how the eval harness substitutes stubs.
ExtractFn = Callable[[RunDocument, str], tuple[Extraction, list[Usage]]]
RetrieveFn = Callable[[str], tuple[list[Chunk], list[Usage]]]


# The shared state that travels from node to node. A TypedDict is a plain
# dictionary whose keys and value types are declared for the type checker;
# total=False means a key may be absent. The first five keys are the request,
# the next four are the outputs the response is built from, and the rest are
# the supervisor's bookkeeping.
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
    next: str  # the supervisor's decision, read by the conditional edge
    extracted_once: bool  # guards: each worker runs at most once per run
    retrieved_once: bool
    _t: float  # monotonic start of the current hop


def _hop(state: RunState, from_: str, to: str, reason: str, changed: list[str]) -> None:
    """Records one hop in the route log and restarts the hop clock. `ms` is
    the time since the previous hop, so each entry reports how long the step
    that just ended took. setdefault creates the handoffs list on first use."""
    now = time.monotonic()
    ms = int((now - state.get("_t", now)) * 1000)
    state.setdefault("handoffs", []).append(Handoff(**{"from": from_, "to": to, "reason": reason, "state_keys_changed": changed, "ms": ms}))
    # The same hop the response carries, so the sidecar's own log tells the route.
    log.info("handoff", extra={"from": from_, "to": to, "reason": reason, "ms": ms})
    state["_t"] = now


def supervisor_node(state: RunState) -> RunState:
    """The deterministic supervisor. Looks at the state and writes the next
    destination into state["next"], recording a hop with a reason code for
    every decision, including "nothing to do". No model is consulted."""
    # Start the hop clock on the first visit only; setdefault keeps an
    # existing value on later visits.
    state.setdefault("_t", time.monotonic())
    # Which documents could be extracted: uploaded and not yet processed
    # ("stored"), and of a type the extractor supports.
    stored = [d for d in state.get("documents", []) if d.status == "stored"]
    supported = [d for d in stored if d.doc_type in SUPPORTED_DOC_TYPES]
    # Extract mode, first visit: send the extractor if there is work, else end
    # the run with a reason that says why (PHP shows it, the harness scores it).
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
    # Answer mode with a question, first visit: one retrieval.
    if state.get("mode") == "answer" and state.get("question") and not state.get("retrieved_once"):
        _hop(state, "supervisor", "evidence_retriever", "question_present", [])
        state["next"] = "evidence_retriever"
        return state
    # Back from a worker, or nothing left to do.
    # The closing hop is labelled worker_finished only after a retrieval;
    # every other ending, including the return from the extractor, is
    # labelled no_question.
    reason = "worker_finished" if state.get("retrieved_once") else "no_question"
    _hop(state, "supervisor", "done", reason, [])
    state["next"] = END
    return state


def make_extractor_node(extract: ExtractFn) -> Callable[[RunState], RunState]:
    """A factory: takes the extract function and returns the node that uses
    it. The returned inner function remembers `extract` (a closure), which is
    how the production graph gets the real worker and the eval graph a stub."""
    def node(state: RunState) -> RunState:
        ok = False
        # Every stored, supported document is extracted in turn; a document
        # that fails is recorded as failed and the loop continues. The worker
        # as a whole counts as finished if at least one document succeeded.
        for d in state.get("documents", []):
            if d.status != "stored" or d.doc_type not in SUPPORTED_DOC_TYPES:
                continue
            extraction, usage = extract(d, state.get("correlation_id", ""))
            state.setdefault("extractions", []).append(extraction)
            state.setdefault("usage", []).extend(usage)
            ok = ok or extraction.status == "extracted"
        # The guard the supervisor reads so this node never runs twice.
        state["extracted_once"] = True
        _hop(state, "intake_extractor", "supervisor", "worker_finished" if ok else "worker_failed", ["extractions", "usage"])
        return state

    return node


def make_retriever_node(retrieve: RetrieveFn) -> Callable[[RunState], RunState]:
    """Same pattern for the retriever."""
    def node(state: RunState) -> RunState:
        # A retrieval failure (index missing, provider down) must not fail the
        # run: the answer proceeds with no evidence and the hop says
        # worker_failed, which PHP can surface.
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
    """Assembles and compiles the graph with the given workers."""
    # StateGraph(RunState) declares the shape of the shared state. add_node
    # registers a function under a name; add_edge fixes "after A, run B".
    g = StateGraph(RunState)
    g.add_node("supervisor", supervisor_node)
    g.add_node("intake_extractor", make_extractor_node(extract))
    g.add_node("evidence_retriever", make_retriever_node(retrieve))
    g.add_edge(START, "supervisor")
    # After the supervisor, call the lambda on the state and look its answer
    # (state["next"]) up in the mapping to find the next node, or END.
    g.add_conditional_edges("supervisor", lambda s: s["next"], {"intake_extractor": "intake_extractor", "evidence_retriever": "evidence_retriever", END: END})
    # Both workers always report back to the supervisor.
    g.add_edge("intake_extractor", "supervisor")
    g.add_edge("evidence_retriever", "supervisor")
    # compile() turns the description into a runnable object with .invoke().
    return g.compile()


# ---- Production workers ----------------------------------------------------


def real_extract(doc: RunDocument, correlation_id: str) -> tuple[Extraction, list[Usage]]:
    """The production extract worker: decode the base64 payload, then hand
    the bytes to extractor.extract. A payload that will not decode is
    reported as an unreadable document, not raised."""
    try:
        data = extractor.decode(doc.bytes_base64)
    except Exception:
        return Extraction(document_id=doc.document_id, status="failed", failure_reason="unreadable", extraction=None, confidence=0.0), []
    outcome = extractor.extract(doc.document_id, doc.doc_type, data, correlation_id)
    return outcome.extraction, outcome.usage


def no_retrieve(question: str) -> tuple[list[Chunk], list[Usage]]:
    """Placeholder until Phase 6 lands the retriever: no chunks, no usage."""
    return [], []


# The compiled production graph, built on first use and reused by every run
# (it is read-only once compiled; each run carries its own state).
_graph = None


def production_graph():
    """Builds the production graph once. `global` tells Python to assign to
    the module-level _graph rather than create a local variable."""
    global _graph
    if _graph is None:
        fn: RetrieveFn = no_retrieve
        # retrieve.py needs numpy and rank_bm25; if they are not installed the
        # import fails and the graph runs with the placeholder retriever.
        try:
            from . import retrieve as retrieve_module  # Phase 6

            fn = retrieve_module.retrieve
        except ImportError:
            pass
        _graph = build_graph(real_extract, fn)
    return _graph


def run(mode: str, correlation_id: str, facts_hash: str, question: str | None, documents: list[RunDocument], graph=None) -> RunState:
    """Runs one request through the graph and returns the final state.
    `graph` lets the eval endpoints pass a stubbed graph; otherwise the
    production graph is used. invoke() runs nodes until END is reached."""
    state: RunState = {"mode": mode, "correlation_id": correlation_id, "facts_hash": facts_hash, "question": question, "documents": documents,
                       "extractions": [], "chunks": [], "handoffs": [], "usage": [], "extracted_once": False, "retrieved_once": False}
    g = graph or production_graph()
    return g.invoke(state)  # type: ignore[return-value]


def stub_extract(doc: RunDocument, correlation_id: str) -> tuple[Extraction, list[Usage]]:
    """Eval stub: succeeds without a model so routing can be scored alone."""
    return Extraction(document_id=doc.document_id, status="extracted", failure_reason=None, extraction=None, confidence=1.0), []


def stub_retrieve(question: str) -> tuple[list[Chunk], list[Usage]]:
    """Eval stub: no chunks, no usage, no index needed."""
    return [], []
