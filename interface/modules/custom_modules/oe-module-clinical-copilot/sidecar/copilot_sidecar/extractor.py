"""The intake_extractor worker: bytes in, a contract extraction out.

parse -> propose (model) -> anchor -> validate. Every failure becomes a
failure_reason code on the extraction rather than an exception, so one
bad document never fails the run. The same function serves the /run
endpoint and the eval harness's /eval/anchor endpoint (which supplies a
recorded proposal instead of calling the model).

The same pipeline, step by step:

  PDF bytes -> parse.parse_pdf      words with boxes, grouped into rows;
                                    OCR for pages with no text layer
            -> _propose_per_page    one model call per page, replies merged
            -> anchor.build_*       find each proposed value on its page and
                                    cite it, or mark it unverified; list the
                                    result rows nothing was anchored to
            -> one retry call       lab reports only: ask again for exactly
                                    those leftover rows
            -> confidence           the share of citations that anchored
            -> Extraction           status "extracted", or "failed" + reason

Why per page and why the retry: a model asked for a whole multi-page report
at once tends to skip rows. Asking per page shortens what it must hold in
view; the retry quotes back the rows a deterministic detector found were
missed. The model can be asked twice, but it is never trusted: anchor.py
must find every value on the page before it counts as verified.
"""

from __future__ import annotations

import base64
import logging
from dataclasses import dataclass

from pydantic import ValidationError

from . import anchor, llm, parse
from .schemas import Extraction, IntakeFormProposal, LabReportProposal, Usage

log = logging.getLogger("copilot.extractor")


# `@dataclass` writes the boilerplate constructor for a plain record. This one
# bundles what extract() produces: the contract Extraction, every paid model
# call, and the raw model reply as text (kept so the eval harness can record
# it as a fixture; None when a recorded proposal was supplied instead).
@dataclass
class ExtractOutcome:
    extraction: Extraction
    usage: list[Usage]
    proposal_raw: str | None = None


def extract(document_id: int, doc_type: str, data: bytes, correlation_id: str, proposal: LabReportProposal | IntakeFormProposal | None = None) -> ExtractOutcome:
    """Runs one document through parse -> propose -> anchor -> validate.

    `proposal` is normally None and the model is called. The eval harness
    passes a recorded proposal instead, so anchoring can be tested on its
    own with no model, no key and no cost. Every expected failure (bad file,
    model down, reply off-contract) comes back as an Extraction with status
    "failed" and a failure_reason; the caller never has to catch those."""
    # A helper defined inside the function (a closure) so every failure path
    # logs the same fields and builds the same shape of failed Extraction.
    def failed(reason: str) -> ExtractOutcome:
        log.info("extract failed", extra={"correlation_id": correlation_id, "document_id": document_id, "doc_type": doc_type, "failure_reason": reason})
        return ExtractOutcome(Extraction(document_id=document_id, status="failed", failure_reason=reason, extraction=None, confidence=0.0), [])

    # 1. Parse. ParseError codes already match the contract's failure reasons,
    #    except the generic "parse_failed", which the contract calls "unreadable".
    try:
        parsed = parse.parse_pdf(data)
    except parse.ParseError as exc:
        return failed(exc.code if exc.code != "parse_failed" else "unreadable")

    # 2. Propose. Skipped when the harness supplied a recorded proposal; then
    #    `raw` stays None, which also switches off the retry further down.
    usage: list[Usage] = []
    raw = None
    retries = 0
    if proposal is None:
        try:
            proposal, raw, usage = _propose_per_page(doc_type, parsed)
        except llm.ModelError as exc:
            return failed(exc.code)

    # 3. Anchor. `assert isinstance` checks the proposal is the right shape for
    #    the document type; a wrong pairing, or an anchored object that fails
    #    its own contract checks, is reported as schema_mismatch, not a crash.
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
    #
    # 4. In more detail: anchor.unextracted_rows found result-like rows that
    #    no proposed result anchored to. Their text is quoted back to the
    #    model with RETRY_TASK ("these rows were missing; one result per
    #    row"), the new results are appended to the proposal, and the merged
    #    proposal is anchored again from scratch. If anything in the retry
    #    fails, the first extraction stands and the rows stay listed as
    #    unextracted, so the clinician still sees them. `raw is not None`
    #    limits this to live model runs; a recorded proposal is never re-asked.
    if doc_type == "lab_pdf" and raw is not None and built.unextracted:
        assert isinstance(proposal, LabReportProposal)
        rows_text = "\n".join(f"=== page {u.page} ===\n{u.text}" for u in built.unextracted)
        try:
            p = llm.propose(doc_type, rows_text, extra_task=llm.RETRY_TASK)
            usage.append(p.usage)
            retries += 1
            extra = p.data
            assert isinstance(extra, LabReportProposal)
            # model_copy(update=...) returns a new proposal with the results
            # list replaced by old + new; Pydantic models are not edited in place.
            merged = proposal.model_copy(update={"results": [*proposal.results, *extra.results]})
            rebuilt, _ = anchor.build_lab_report(document_id, parsed, merged, anchor.load_loinc_map())
            if rebuilt is not None:
                built, proposal = rebuilt, merged
                raw = merged.model_dump_json()
        except (llm.ModelError, ValidationError, AssertionError):
            pass  # the first extraction stands; unextracted rows stay visible

    # 5. Confidence is simply the share of citations that anchored: 1.0 means
    #    every value was found on its page, 0.0 means none was. The log line
    #    carries counts only, never a value or a name.
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
    return ExtractOutcome(Extraction(document_id=document_id, status="extracted", failure_reason=None, extraction=built, confidence=conf, retries=retries), usage, raw)


def _propose_per_page(doc_type: str, parsed: parse.ParsedDocument) -> tuple[LabReportProposal | IntakeFormProposal, str, list[Usage]]:
    """One model call per page (fewer omissions than one call for the whole
    document), merged: list fields concatenate, scalar header fields take the
    first non-null value across pages.

    Returns the merged proposal, its JSON text (for the eval fixtures) and
    the usage of every call. A ModelError from any page aborts the whole
    document; extract() turns it into a failure_reason."""
    usage: list[Usage] = []
    merged: LabReportProposal | IntakeFormProposal | None = None
    for page in parsed.pages:
        # text_for_model([n]) renders only that page's rows, one per line.
        p = llm.propose(doc_type, parsed.text_for_model([page.number]), page=page.number)
        usage.append(p.usage)
        # The first page's reply seeds the merge.
        if merged is None:
            merged = p.data  # type: ignore[assignment]
            continue
        # Iterating a Pydantic model yields (field name, value) pairs. Lists
        # (results, medications, ...) are appended; a header field such as
        # lab_name is filled only if every earlier page left it null.
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
    """Base64 text (the way PHP ships the file inside JSON) back to bytes.
    validate=True rejects characters outside the base64 alphabet instead of
    skipping them, so a corrupted payload is "unreadable" rather than a
    silently truncated PDF. A missing payload is unreadable too."""
    if not bytes_base64:
        raise parse.ParseError("unreadable")
    try:
        return base64.b64decode(bytes_base64, validate=True)
    except Exception as exc:
        raise parse.ParseError("unreadable") from exc
