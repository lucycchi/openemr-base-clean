"""Routing is deterministic; the handoff log is the observable contract.
The graph runs with stubbed workers so no parser or model is involved.

The supervisor decides, from the request alone, whether a document goes to
the extractor, a question goes to the retriever, or there is nothing to do,
and it writes one Handoff (from, to, reason) per decision. PHP shows those
hops in the trace, so each test asserts the exact hop list. A wrong route
would mean a document silently not extracted, extracted twice (double model
spend), or a question answered without its evidence."""

from __future__ import annotations

from copilot_sidecar import graph
from copilot_sidecar.schemas import RunDocument

# The real graph with stand-in workers: the extractor stub reports success
# without reading anything, the retriever stub returns no chunks. Routing
# is all that is left to observe.
G = graph.build_graph(graph.stub_extract, graph.stub_retrieve)


# A document entry as PHP would send it (no bytes: the stubs never read them).
def doc(status: str = "stored", doc_type: str = "lab_pdf") -> RunDocument:
    return RunDocument(document_id=1, doc_type=doc_type, status=status, sha3_512="a" * 128, bytes_base64=None)


# The handoff log reduced to what the tests compare: who, to whom, and why.
def hops(state) -> list[tuple[str, str, str]]:
    return [(h.from_, h.to, h.reason) for h in state["handoffs"]]


# Runs the stubbed graph with a fixed correlation id and facts hash.
def run(mode: str, question: str | None, documents) -> dict:
    return graph.run(mode, "abcdefgh", "0" * 64, question, documents, graph=G)


# Pins: an extract request with nothing attached ends in one hop, reason
# no_documents. No worker runs.
def test_no_documents_no_question() -> None:
    assert hops(run("extract", None, [])) == [("supervisor", "done", "no_documents")]


# Pins: a document PHP already marked "extracted" is not sent to the
# extractor again, so a page reload never repeats the model spend.
def test_already_extracted_document_is_not_re_extracted() -> None:
    s = run("extract", None, [doc("extracted")])
    assert hops(s) == [("supervisor", "done", "already_extracted")]
    assert s["extractions"] == []


# Pins the normal extraction route: supervisor -> extractor -> supervisor ->
# done, exactly once, with one extraction in the result. The final reason
# is no_question because an extract-mode run carries no question.
def test_stored_document_goes_to_the_extractor_once_then_done() -> None:
    s = run("extract", None, [doc("stored")])
    assert hops(s) == [("supervisor", "intake_extractor", "stored_document"), ("intake_extractor", "supervisor", "worker_finished"), ("supervisor", "done", "no_question")]
    assert len(s["extractions"]) == 1


# Pins: when the extractor reports a failed document, the hop says
# worker_failed and the supervisor finishes; it does not send the document
# back for another try. Retrying an unreadable file would only cost time.
def test_failed_worker_is_reported_not_retried() -> None:
    # A stand-in extractor that always fails, built inline for this test.
    def failing(d, cid):
        from copilot_sidecar.schemas import Extraction

        return Extraction(document_id=d.document_id, status="failed", failure_reason="unreadable", extraction=None, confidence=0.0), []

    g = graph.build_graph(failing, graph.stub_retrieve)
    s = graph.run("extract", "abcdefgh", "0" * 64, None, [doc("stored")], graph=g)
    assert hops(s) == [("supervisor", "intake_extractor", "stored_document"), ("intake_extractor", "supervisor", "worker_failed"), ("supervisor", "done", "no_question")]


# Pins the answer route: a question goes to the retriever exactly once with
# its text unchanged, and the run ends after the retriever reports back.
# The `calls` list records what the stand-in retriever was asked.
def test_question_goes_to_the_retriever() -> None:
    calls = []

    def retriever(q: str):
        calls.append(q)
        return [], []

    g = graph.build_graph(graph.stub_extract, retriever)
    s = graph.run("answer", "abcdefgh", "0" * 64, "statin?", [], graph=g)
    assert calls == ["statin?"]
    assert hops(s) == [("supervisor", "evidence_retriever", "question_present"), ("evidence_retriever", "supervisor", "worker_finished"), ("supervisor", "done", "worker_finished")]


# Pins: a stored document of a type the sidecar does not handle is refused
# by the supervisor itself with reason unsupported_doc_type, before any
# worker sees it. SimpleNamespace is a bare object with the given fields;
# it is used because RunDocument would reject "referral_fax" at
# construction, and the point is to test the supervisor's own check.
def test_unsupported_document_type_is_refused_by_the_supervisor() -> None:
    from types import SimpleNamespace

    fax = SimpleNamespace(document_id=1, doc_type="referral_fax", status="stored", sha3_512="0" * 128, bytes_base64=None)
    s = run("extract", None, [fax])
    assert hops(s) == [("supervisor", "done", "unsupported_doc_type")]


# Pins that the compiled graph can describe its own nodes: the architecture
# diagram is checked against this, so a renamed node fails here first.
def test_graph_is_drawable() -> None:
    # The compiled graph exposes its structure; this is what W2_ARCHITECTURE.md's diagram is checked against.
    nodes = set(G.get_graph().nodes)
    assert {"supervisor", "intake_extractor", "evidence_retriever"} <= nodes


# -- brief mode (chart-driven guideline evidence) ---------------------------

def test_brief_with_queries_routes_to_retriever_once_with_chart_triggers() -> None:
    from copilot_sidecar.schemas import TriggerEvidence, TriggerQuery

    calls = []

    def many(queries):
        calls.append([q.trigger_id for q in queries])
        return [TriggerEvidence(trigger_id=q.trigger_id, chunks=[]) for q in queries], []

    g = graph.build_graph(graph.stub_extract, graph.stub_retrieve, many)
    s = graph.run("brief", "abcdefgh", "0" * 64, None, [], graph=g, queries=[TriggerQuery(trigger_id="lipids", query="statin indication"), TriggerQuery(trigger_id="ckd", query="CKD staging")])
    assert calls == [["lipids", "ckd"]]
    assert hops(s) == [("supervisor", "evidence_retriever", "chart_triggers"), ("evidence_retriever", "supervisor", "worker_finished"), ("supervisor", "done", "worker_finished")]
    assert [e.trigger_id for e in s["evidence"]] == ["lipids", "ckd"]


def test_brief_without_queries_routes_done_with_no_triggers() -> None:
    s = run("brief", None, [])
    assert hops(s) == [("supervisor", "done", "no_triggers")]
    assert s["evidence"] == []


def test_brief_retrieval_failure_is_reported_not_raised() -> None:
    from copilot_sidecar.schemas import TriggerQuery

    def broken(queries):
        raise RuntimeError("index missing")

    g = graph.build_graph(graph.stub_extract, graph.stub_retrieve, broken)
    s = graph.run("brief", "abcdefgh", "0" * 64, None, [], graph=g, queries=[TriggerQuery(trigger_id="lipids", query="statin indication")])
    assert hops(s) == [("supervisor", "evidence_retriever", "chart_triggers"), ("evidence_retriever", "supervisor", "worker_failed"), ("supervisor", "done", "worker_finished")]
    assert s["evidence"] == []
