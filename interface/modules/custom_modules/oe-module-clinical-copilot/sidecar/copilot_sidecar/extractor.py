"""The intake_extractor worker: bytes in, a contract extraction out.

parse -> propose (model) -> anchor -> validate. Every failure becomes a
failure_reason code on the extraction rather than an exception, so one
bad document never fails the run. The same function serves the /run
endpoint and the eval harness's /eval/anchor endpoint (which supplies a
recorded proposal instead of calling the model).
"""

from __future__ import annotations

import base64
import logging
from dataclasses import dataclass

from pydantic import ValidationError

from . import anchor, llm, parse
from .schemas import Extraction, IntakeFormProposal, LabReportProposal, Usage

log = logging.getLogger("copilot.extractor")


@dataclass
class ExtractOutcome:
    extraction: Extraction
    usage: list[Usage]
    proposal_raw: str | None = None


def extract(document_id: int, doc_type: str, data: bytes, correlation_id: str, proposal: LabReportProposal | IntakeFormProposal | None = None) -> ExtractOutcome:
    def failed(reason: str) -> ExtractOutcome:
        log.info("extract failed", extra={"correlation_id": correlation_id, "document_id": document_id, "doc_type": doc_type, "failure_reason": reason})
        return ExtractOutcome(Extraction(document_id=document_id, status="failed", failure_reason=reason, extraction=None, confidence=0.0), [])

    try:
        parsed = parse.parse_pdf(data)
    except parse.ParseError as exc:
        return failed(exc.code if exc.code != "parse_failed" else "unreadable")

    usage: list[Usage] = []
    raw = None
    if proposal is None:
        try:
            proposal, raw, usage = _propose_per_page(doc_type, parsed)
        except llm.ModelError as exc:
            return failed(exc.code)

    try:
        if doc_type == "lab_pdf":
            assert isinstance(proposal, LabReportProposal)
            built, reason = anchor.build_lab_report(document_id, parsed, proposal, anchor.load_loinc_map())
        else:
            assert isinstance(proposal, IntakeFormProposal)
            built, reason = anchor.build_intake_form(document_id, parsed, proposal)
    except (ValidationError, AssertionError):
        return failed("schema_mismatch")
    if built is None:
        return failed(reason or "schema_mismatch")

    # One targeted retry for rows the model skipped (lab reports only): the
    # detector is deterministic, so the retry asks for exactly those rows.
    if doc_type == "lab_pdf" and raw is not None and built.unextracted:
        assert isinstance(proposal, LabReportProposal)
        rows_text = "\n".join(f"=== page {u.page} ===\n{u.text}" for u in built.unextracted)
        try:
            p = llm.propose(doc_type, rows_text, extra_task=llm.RETRY_TASK)
            usage.append(p.usage)
            extra = p.data
            assert isinstance(extra, LabReportProposal)
            merged = proposal.model_copy(update={"results": [*proposal.results, *extra.results]})
            rebuilt, _ = anchor.build_lab_report(document_id, parsed, merged, anchor.load_loinc_map())
            if rebuilt is not None:
                built, proposal = rebuilt, merged
                raw = merged.model_dump_json()
        except (llm.ModelError, ValidationError, AssertionError):
            pass  # the first extraction stands; unextracted rows stay visible

    cites = anchor.citations_of(built)
    conf = anchor.confidence(cites)
    log.info(
        "extracted",
        extra={
            "correlation_id": correlation_id,
            "document_id": document_id,
            "doc_type": doc_type,
            "pages": len(parsed.pages),
            "ocr_pages": parsed.ocr_pages,
            "fields": len(cites),
            "anchored": sum(1 for c in cites if c.anchored),
            "confidence": conf,
        },
    )
    return ExtractOutcome(Extraction(document_id=document_id, status="extracted", failure_reason=None, extraction=built, confidence=conf), usage, raw)


def _propose_per_page(doc_type: str, parsed: parse.ParsedDocument) -> tuple[LabReportProposal | IntakeFormProposal, str, list[Usage]]:
    """One model call per page (fewer omissions than one call for the whole
    document), merged: list fields concatenate, scalar header fields take the
    first non-null value across pages."""
    usage: list[Usage] = []
    merged: LabReportProposal | IntakeFormProposal | None = None
    for page in parsed.pages:
        p = llm.propose(doc_type, parsed.text_for_model([page.number]))
        usage.append(p.usage)
        if merged is None:
            merged = p.data  # type: ignore[assignment]
            continue
        data = p.data
        update = {}
        for name, value in data:
            current = getattr(merged, name)
            if isinstance(current, list):
                update[name] = [*current, *value]
            elif current is None and value is not None:
                update[name] = value
        merged = merged.model_copy(update=update)
    assert merged is not None
    return merged, merged.model_dump_json(), usage


def decode(bytes_base64: str | None) -> bytes:
    if not bytes_base64:
        raise parse.ParseError("unreadable")
    try:
        return base64.b64decode(bytes_base64, validate=True)
    except Exception as exc:
        raise parse.ParseError("unreadable") from exc
