"""Routing is deterministic; the handoff log is the observable contract."""

from __future__ import annotations

from copilot_sidecar import supervisor
from copilot_sidecar.schemas import RunDocument


def doc(status: str = "stored", doc_type: str = "lab_pdf") -> RunDocument:
    return RunDocument(document_id=1, doc_type=doc_type, status=status, sha3_512="a" * 128, bytes_base64=None if status != "stored" else "bm90IGEgcGRm")


def hops(state: supervisor.RunState) -> list[tuple[str, str, str]]:
    return [(h.from_, h.to, h.reason) for h in state.handoffs]


def test_no_documents_no_question() -> None:
    s = supervisor.run(supervisor.RunState("extract", "abcdefgh", "0" * 64, None, []))
    assert hops(s) == [("supervisor", "done", "no_documents")]


def test_already_extracted_document_is_not_re_extracted() -> None:
    s = supervisor.run(supervisor.RunState("extract", "abcdefgh", "0" * 64, None, [doc("extracted")]))
    assert hops(s) == [("supervisor", "done", "already_extracted")]
    assert s.extractions == []


def test_stored_document_routes_to_extractor_and_a_bad_pdf_fails_that_document() -> None:
    s = supervisor.run(supervisor.RunState("extract", "abcdefgh", "0" * 64, None, [doc("stored")]))
    assert hops(s) == [("supervisor", "intake_extractor", "stored_document"), ("intake_extractor", "supervisor", "worker_failed"), ("supervisor", "done", "no_question")]
    assert s.extractions[0].status == "failed" and s.extractions[0].failure_reason == "unreadable"


def test_question_routes_to_retriever() -> None:
    called = []

    def retriever(q: str):
        called.append(q)
        return [], []

    s = supervisor.run(supervisor.RunState("answer", "abcdefgh", "0" * 64, "statin?", []), retriever=retriever)
    assert called == ["statin?"]
    assert hops(s) == [("supervisor", "evidence_retriever", "question_present"), ("evidence_retriever", "supervisor", "worker_finished"), ("supervisor", "done", "worker_finished")]
