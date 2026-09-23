"""The critic worker: one boolean per retrieved passage, answering whether
the passage's stated population includes this patient. It never writes
patient-facing text; its output is a verdict and a reason drawn from the
passage. A model failure leaves the verdict unknown (None) and is reported
as worker_failed, never raised, so a briefing still renders its cards with
the "not assessed" label."""

from __future__ import annotations

import json
from types import SimpleNamespace

import pytest

from copilot_sidecar import graph, llm
from copilot_sidecar.schemas import Chunk, TriggerEvidence, TriggerQuery, Usage


def _client(reply: str | None, refusal: str | None = None):
    """A stand-in for the OpenAI client returning one canned chat reply."""
    message = SimpleNamespace(content=reply, refusal=refusal)
    choice = SimpleNamespace(message=message, finish_reason="stop")
    resp = SimpleNamespace(choices=[choice], usage=SimpleNamespace(prompt_tokens=120, completion_tokens=18), model="gpt-test")

    class Completions:
        def create(self, **kwargs):
            _client.last_kwargs = kwargs
            return resp

    return SimpleNamespace(chat=SimpleNamespace(completions=Completions()))


def test_critic_output_is_boolean_and_reason_quotes_passage() -> None:
    reply = json.dumps({"applicable": False, "reason": "The passage applies to adults 40 to 75 years of age; the patient is 82."})
    ok, reason, usage = llm.applicable("In adults 40 to 75 years of age with LDL-C 70 to 189 mg/dL ...", ["LDL Cholesterol 165 mg/dL on 2026-09-10 (above the standard range 0-129 mg/dL)"], 82, "F", client=_client(reply))
    assert ok is False
    assert "40 to 75" in reason
    assert isinstance(usage, Usage) and usage.kind == "chat" and usage.input == 120 and usage.output == 18
    sent = _client.last_kwargs
    assert sent["response_format"]["json_schema"]["strict"] is True
    assert sent["response_format"]["json_schema"]["name"] == "llm_critic_output"
    user = sent["messages"][1]["content"]
    assert "age 82" in user and "sex F" in user and "LDL Cholesterol 165" in user and "40 to 75" in user


def test_critic_schema_mismatch_and_refusal_are_model_errors() -> None:
    with pytest.raises(llm.ModelError) as e:
        llm.applicable("passage", [], None, None, client=_client("not json"))
    assert e.value.code == "schema_mismatch"
    with pytest.raises(llm.ModelError) as e2:
        llm.applicable("passage", [], None, None, client=_client(None, refusal="no"))
    assert e2.value.code == "model_error"


def _many_with_chunks(queries: list[TriggerQuery]):
    return [TriggerEvidence(trigger_id=q.trigger_id, chunks=[Chunk(chunk_id=f"{i:012x}", source_id="src", section="S", quote=f"Passage for {q.trigger_id}", score=0.5)]) for i, q in enumerate(queries)], []


def hops(state) -> list[tuple[str, str, str]]:
    return [(h.from_, h.to, h.reason) for h in state["handoffs"]]


def test_brief_routes_retriever_then_critic_then_done() -> None:
    def critic(passage, facts, age, sex):
        return True, "no population restriction stated", Usage(model="m", kind="chat", input=1, output=1)

    g = graph.build_graph(graph.stub_extract, graph.stub_retrieve, _many_with_chunks, critic)
    s = graph.run("brief", "abcdefgh", "0" * 64, None, [], graph=g, queries=[TriggerQuery(trigger_id="lipids", query="q")])
    assert hops(s) == [
        ("supervisor", "evidence_retriever", "chart_triggers"), ("evidence_retriever", "supervisor", "worker_finished"),
        ("supervisor", "critic", "applicability_check"), ("critic", "supervisor", "worker_finished"),
        ("supervisor", "done", "worker_finished"),
    ]


def test_critic_marks_each_trigger_evidence_and_records_usage() -> None:
    seen = []

    def critic(passage, facts, age, sex):
        seen.append((passage, facts, age, sex))
        verdict = len(seen) % 2 == 1
        return verdict, "reason " + str(len(seen)), Usage(model="m", kind="chat", input=10, output=2)

    g = graph.build_graph(graph.stub_extract, graph.stub_retrieve, _many_with_chunks, critic)
    s = graph.run("brief", "abcdefgh", "0" * 64, None, [], graph=g, queries=[TriggerQuery(trigger_id="lipids", query="q1"), TriggerQuery(trigger_id="ckd", query="q2")],
                  patient=graph.PatientContext(age=55, sex="M"), facts=["LDL Cholesterol 165 mg/dL"])
    assert [(e.trigger_id, e.applicable, e.reason) for e in s["evidence"]] == [("lipids", True, "reason 1"), ("ckd", False, "reason 2")]
    assert seen[0] == ("Passage for lipids", ["LDL Cholesterol 165 mg/dL"], 55, "M")
    assert [u.kind for u in s["usage"]] == ["chat", "chat"]


def test_critic_model_error_leaves_applicable_none_and_logs_worker_failed() -> None:
    def broken(passage, facts, age, sex):
        raise llm.ModelError("timeout")

    g = graph.build_graph(graph.stub_extract, graph.stub_retrieve, _many_with_chunks, broken)
    s = graph.run("brief", "abcdefgh", "0" * 64, None, [], graph=g, queries=[TriggerQuery(trigger_id="lipids", query="q")])
    assert [(e.applicable, e.reason) for e in s["evidence"]] == [(None, None)]
    assert ("critic", "supervisor", "worker_failed") in hops(s)
    assert hops(s)[-1] == ("supervisor", "done", "worker_finished")


def test_critic_never_runs_without_evidence() -> None:
    calls = []

    def critic(passage, facts, age, sex):
        calls.append(passage)
        return True, "x", Usage(model="m", kind="chat", input=1, output=1)

    def many_empty(queries):
        return [TriggerEvidence(trigger_id=q.trigger_id, chunks=[]) for q in queries], []

    g = graph.build_graph(graph.stub_extract, graph.stub_retrieve, many_empty, critic)
    s = graph.run("brief", "abcdefgh", "0" * 64, None, [], graph=g, queries=[TriggerQuery(trigger_id="lipids", query="q")])
    assert calls == []
    assert ("supervisor", "critic", "applicability_check") not in hops(s)


def test_without_a_critic_the_graph_skips_the_check() -> None:
    g = graph.build_graph(graph.stub_extract, graph.stub_retrieve, _many_with_chunks, None)
    s = graph.run("brief", "abcdefgh", "0" * 64, None, [], graph=g, queries=[TriggerQuery(trigger_id="lipids", query="q")])
    assert ("supervisor", "critic", "applicability_check") not in hops(s)
    assert s["evidence"][0].applicable is None


# -- review fix: per-trigger context, bounded time -----------------------------

def test_critic_receives_each_triggers_own_facts_not_the_union() -> None:
    seen = []

    def critic(passage, facts, age, sex):
        seen.append((passage, list(facts)))
        return True, "ok", Usage(model="m", kind="chat", input=1, output=1)

    g = graph.build_graph(graph.stub_extract, graph.stub_retrieve, _many_with_chunks, critic)
    queries = [TriggerQuery(trigger_id="lipids", query="q1", facts=["LDL 165", "On the problem list: HTN"]), TriggerQuery(trigger_id="anemia", query="q2", facts=["Hgb 10.2", "On the problem list: HTN"])]
    graph.run("brief", "abcdefgh", "0" * 64, None, [], graph=g, queries=queries, facts=["LDL 165", "Hgb 10.2"])
    assert seen == [("Passage for lipids", ["LDL 165", "On the problem list: HTN"]), ("Passage for anemia", ["Hgb 10.2", "On the problem list: HTN"])]


def test_critic_falls_back_to_the_run_facts_when_a_query_carries_none() -> None:
    seen = []

    def critic(passage, facts, age, sex):
        seen.append(list(facts))
        return True, "ok", Usage(model="m", kind="chat", input=1, output=1)

    g = graph.build_graph(graph.stub_extract, graph.stub_retrieve, _many_with_chunks, critic)
    graph.run("brief", "abcdefgh", "0" * 64, None, [], graph=g, queries=[TriggerQuery(trigger_id="lipids", query="q1")], facts=["LDL 165"])
    assert seen == [["LDL 165"]]


def test_critic_verdicts_are_gathered_concurrently_and_in_order() -> None:
    import threading
    import time

    started = []
    lock = threading.Lock()

    def slow(passage, facts, age, sex):
        with lock:
            started.append(time.monotonic())
        time.sleep(0.3)
        return passage.endswith("lipids"), passage, Usage(model="m", kind="chat", input=1, output=1)

    g = graph.build_graph(graph.stub_extract, graph.stub_retrieve, _many_with_chunks, slow)
    queries = [TriggerQuery(trigger_id=t, query="q") for t in ("lipids", "anemia", "ckd", "diabetes")]
    t0 = time.monotonic()
    s = graph.run("brief", "abcdefgh", "0" * 64, None, [], graph=g, queries=queries)
    elapsed = time.monotonic() - t0
    assert [(e.trigger_id, e.applicable) for e in s["evidence"]] == [("lipids", True), ("anemia", False), ("ckd", False), ("diabetes", False)]
    assert elapsed < 0.9, f"four 0.3 s verdicts took {elapsed:.2f} s: not concurrent"


def test_critic_client_is_given_a_short_timeout_and_no_retries(monkeypatch) -> None:
    captured = {}

    class FakeOpenAI:
        def __init__(self, **kwargs):
            captured.update(kwargs)
            raise RuntimeError("stop here")

    monkeypatch.setattr(llm, "OpenAI", FakeOpenAI)
    with pytest.raises(RuntimeError):
        llm.applicable("passage", [], None, None)
    assert captured.get("timeout", 999) <= 12
    assert captured.get("max_retries") == 0
