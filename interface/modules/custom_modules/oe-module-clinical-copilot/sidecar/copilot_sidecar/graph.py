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
import os
import time
from typing import Callable, TypedDict

from langgraph.graph import END, START, StateGraph

from . import extractor
from .schemas import Chunk, Extraction, Handoff, RunDocument, Usage, TriggerEvidence, TriggerQuery, PatientContext

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
RetrieveManyFn = Callable[[list[TriggerQuery]], tuple[list[TriggerEvidence], list[Usage]]]
CriticFn = Callable[[str, list[str], int | None, str | None], tuple[bool, str, Usage]]


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
    queries: list[TriggerQuery]  # brief mode: the chart's fired triggers
    evidence: list[TriggerEvidence]  # brief mode: what the retriever found per trigger
    patient: PatientContext | None  # brief mode: age and sex for the critic
    facts: list[str]  # brief mode: the cited fact lines for the critic
    critic_once: bool  # the critic runs at most once per run
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


def supervisor_node(state: RunState, critic_enabled: bool = False) -> RunState:
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
    if state.get("mode") == "brief" and not state.get("retrieved_once"):
        if state.get("queries"):
            _hop(state, "supervisor", "evidence_retriever", "chart_triggers", [])
            state["next"] = "evidence_retriever"
            return state
        _hop(state, "supervisor", "done", "no_triggers", [])
        state["next"] = END
        return state
    # Brief mode, after retrieval: the critic checks each passage's population once,
    # only when there is something to check and a critic is configured.
    if state.get("mode") == "brief" and critic_enabled and not state.get("critic_once") and any(e.chunks for e in state.get("evidence", [])):
        _hop(state, "supervisor", "critic", "applicability_check", [])
        state["next"] = "critic"
        return state
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


def make_retriever_node(retrieve: RetrieveFn, retrieve_many: RetrieveManyFn) -> Callable[[RunState], RunState]:
    """Same pattern for the retriever."""
    def node(state: RunState) -> RunState:
        changed = ["chunks", "usage"]
        # A retrieval failure (index missing, provider down) must not fail the
        # run: the answer proceeds with no evidence and the hop says
        # worker_failed, which PHP can surface.
        try:
            if state.get("mode") == "brief":
                # Brief mode: one batch over every fired trigger; the answer-mode leg is untouched.
                evidence, usage = retrieve_many(state.get("queries") or [])
                state["evidence"] = evidence
                changed = ["evidence", "usage"]
            else:
                chunks, usage = retrieve(state.get("question") or "")
                state["chunks"] = chunks
            state.setdefault("usage", []).extend(usage)
            reason = "worker_finished"
        except Exception:
            state["chunks"] = []
            state["evidence"] = []
            reason = "worker_failed"
        state["retrieved_once"] = True
        _hop(state, "evidence_retriever", "supervisor", reason, changed)
        return state

    return node


def make_critic_node(critic: CriticFn) -> Callable[[RunState], RunState]:
    """The critic worker: one verdict per trigger with passages. A failed
    call leaves that trigger's verdict None (unknown); the hop reports
    worker_failed only when every verdict failed."""
    def node(state: RunState) -> RunState:
        patient = state.get("patient")
        facts = state.get("facts") or []
        updated: list[TriggerEvidence] = []
        attempted = 0
        succeeded = 0
        for e in state.get("evidence", []):
            if not e.chunks:
                updated.append(e)
                continue
            attempted += 1
            try:
                ok, reason, usage = critic("\n\n".join(c.quote for c in e.chunks), facts, patient.age if patient else None, patient.sex if patient else None)
                state.setdefault("usage", []).append(usage)
                updated.append(e.model_copy(update={"applicable": ok, "reason": reason}))
                succeeded += 1
            except Exception:
                updated.append(e.model_copy(update={"applicable": None, "reason": None}))
        state["evidence"] = updated
        state["critic_once"] = True
        _hop(state, "critic", "supervisor", "worker_finished" if succeeded or not attempted else "worker_failed", ["evidence", "usage"])
        return state

    return node


def build_graph(extract: ExtractFn, retrieve: RetrieveFn, retrieve_many: RetrieveManyFn | None = None, critic: CriticFn | None = None):
    """Assembles and compiles the graph with the given workers."""
    # StateGraph(RunState) declares the shape of the shared state. add_node
    # registers a function under a name; add_edge fixes "after A, run B".
    g = StateGraph(RunState)
    g.add_node("supervisor", lambda state: supervisor_node(state, critic_enabled=critic is not None))
    g.add_node("critic", make_critic_node(critic or stub_critic))
    g.add_node("intake_extractor", make_extractor_node(extract))
    g.add_node("evidence_retriever", make_retriever_node(retrieve, retrieve_many or stub_retrieve_many))
    g.add_edge(START, "supervisor")
    # After the supervisor, call the lambda on the state and look its answer
    # (state["next"]) up in the mapping to find the next node, or END.
    g.add_conditional_edges("supervisor", lambda s: s["next"], {"intake_extractor": "intake_extractor", "evidence_retriever": "evidence_retriever", "critic": "critic", END: END})
    # Both workers always report back to the supervisor.
    g.add_edge("intake_extractor", "supervisor")
    g.add_edge("evidence_retriever", "supervisor")
    g.add_edge("critic", "supervisor")
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


def real_critic(passage: str, fact_lines: list[str], age: int | None, sex: str | None) -> tuple[bool, str, Usage]:
    """The production critic. Imported at call time so a test can replace
    llm.applicable after the production graph was built."""
    from . import llm as llm_module

    return llm_module.applicable(passage, fact_lines, age, sex)


def real_retrieve_many(queries: list[TriggerQuery]) -> tuple[list[TriggerEvidence], list[Usage]]:
    """The production brief-mode worker. Imported at call time so a test can
    replace retrieve.retrieve_many after the production graph was built."""
    from . import retrieve as retrieve_module

    return retrieve_module.retrieve_many(queries)


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
        # The critic is wired only when a model key is present at start-up, so a
        # deployment without one skips the check instead of failing every brief.
        _graph = build_graph(real_extract, fn, real_retrieve_many, real_critic if os.environ.get("OPENAI_API_KEY") else None)
    return _graph


def run(mode: str, correlation_id: str, facts_hash: str, question: str | None, documents: list[RunDocument], graph=None, queries: list[TriggerQuery] | None = None, patient: PatientContext | None = None, facts: list[str] | None = None) -> RunState:
    """Runs one request through the graph and returns the final state.
    `graph` lets the eval endpoints pass a stubbed graph; otherwise the
    production graph is used. invoke() runs nodes until END is reached."""
    state: RunState = {"mode": mode, "correlation_id": correlation_id, "facts_hash": facts_hash, "question": question, "documents": documents,
                       "queries": list(queries or []), "evidence": [], "patient": patient, "facts": list(facts or []), "critic_once": False,
                       "extractions": [], "chunks": [], "handoffs": [], "usage": [], "extracted_once": False, "retrieved_once": False}
    g = graph or production_graph()
    return g.invoke(state)  # type: ignore[return-value]


def stub_extract(doc: RunDocument, correlation_id: str) -> tuple[Extraction, list[Usage]]:
    """Eval stub: succeeds without a model so routing can be scored alone."""
    return Extraction(document_id=doc.document_id, status="extracted", failure_reason=None, extraction=None, confidence=1.0), []


def stub_retrieve(question: str) -> tuple[list[Chunk], list[Usage]]:
    """Eval stub: no chunks, no usage, no index needed."""
    return [], []


def stub_retrieve_many(queries: list[TriggerQuery]) -> tuple[list[TriggerEvidence], list[Usage]]:
    """Eval stub for brief mode: one placeholder passage per trigger, so the
    route through the critic is visible without an index."""
    return [TriggerEvidence(trigger_id=q.trigger_id, chunks=[Chunk(chunk_id="000000000000", source_id="stub", section="stub", quote="stub passage", score=0.0)]) for q in queries], []


def stub_critic(passage: str, fact_lines: list[str], age: int | None, sex: str | None) -> tuple[bool, str, Usage]:
    """Eval stub: every passage applies, no model, no usage cost recorded as tokens."""
    return True, "stub: no population restriction stated", Usage(model="stub", kind="chat", input=0, output=0)
