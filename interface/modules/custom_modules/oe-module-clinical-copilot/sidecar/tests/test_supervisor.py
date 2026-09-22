"""Routing is deterministic; the handoff log is the observable contract.
The graph runs with stubbed workers so no parser or model is involved."""

from __future__ import annotations

from copilot_sidecar import graph
from copilot_sidecar.schemas import RunDocument

G = graph.build_graph(graph.stub_extract, graph.stub_retrieve)


def doc(status: str = "stored", doc_type: str = "lab_pdf") -> RunDocument:
    return RunDocument(document_id=1, doc_type=doc_type, status=status, sha3_512="a" * 128, bytes_base64=None)


def hops(state) -> list[tuple[str, str, str]]:
    return [(h.from_, h.to, h.reason) for h in state["handoffs"]]


def run(mode: str, question: str | None, documents) -> dict:
    return graph.run(mode, "abcdefgh", "0" * 64, question, documents, graph=G)


def test_no_documents_no_question() -> None:
    assert hops(run("extract", None, [])) == [("supervisor", "done", "no_documents")]


def test_already_extracted_document_is_not_re_extracted() -> None:
    s = run("extract", None, [doc("extracted")])
    assert hops(s) == [("supervisor", "done", "already_extracted")]
    assert s["extractions"] == []


def test_stored_document_goes_to_the_extractor_once_then_done() -> None:
    s = run("extract", None, [doc("stored")])
    assert hops(s) == [("supervisor", "intake_extractor", "stored_document"), ("intake_extractor", "supervisor", "worker_finished"), ("supervisor", "done", "no_question")]
    assert len(s["extractions"]) == 1


def test_failed_worker_is_reported_not_retried() -> None:
    def failing(d, cid):
        from copilot_sidecar.schemas import Extraction

        return Extraction(document_id=d.document_id, status="failed", failure_reason="unreadable", extraction=None, confidence=0.0), []

    g = graph.build_graph(failing, graph.stub_retrieve)
    s = graph.run("extract", "abcdefgh", "0" * 64, None, [doc("stored")], graph=g)
    assert hops(s) == [("supervisor", "intake_extractor", "stored_document"), ("intake_extractor", "supervisor", "worker_failed"), ("supervisor", "done", "no_question")]


def test_question_goes_to_the_retriever() -> None:
    calls = []

    def retriever(q: str):
        calls.append(q)
        return [], []

    g = graph.build_graph(graph.stub_extract, retriever)
    s = graph.run("answer", "abcdefgh", "0" * 64, "statin?", [], graph=g)
    assert calls == ["statin?"]
    assert hops(s) == [("supervisor", "evidence_retriever", "question_present"), ("evidence_retriever", "supervisor", "worker_finished"), ("supervisor", "done", "worker_finished")]


def test_unsupported_document_type_is_refused_by_the_supervisor() -> None:
    from types import SimpleNamespace

    fax = SimpleNamespace(document_id=1, doc_type="referral_fax", status="stored", sha3_512="0" * 128, bytes_base64=None)
    s = run("extract", None, [fax])
    assert hops(s) == [("supervisor", "done", "unsupported_doc_type")]


def test_graph_is_drawable() -> None:
    # The compiled graph exposes its structure; this is what W2_ARCHITECTURE.md's diagram is checked against.
    nodes = set(G.get_graph().nodes)
    assert {"supervisor", "intake_extractor", "evidence_retriever"} <= nodes
