"""Pydantic models for the sidecar's wire contracts.

The JSON Schema files in ../contracts/ are the source of truth (written by
hand first). These models must accept and reject the same documents as the
files; tests/test_contracts.py holds them to that through the shared
examples in contracts/examples/ (the PHP side runs the same examples). Two
families:

* Contract models (Citation, LabReport, IntakeForm, Handoff, Usage,
  RunRequest, RunResponse, RunError): what crosses the PHP <-> sidecar
  boundary.
* Proposal models (LabReportProposal, IntakeFormProposal): what the language
  model returns. The request to the model carries the contract file
  (llm.lab-proposal.output, llm.intake-proposal.output) via contracts.py,
  and these models validate the reply. They carry values only; citations,
  bounding boxes, LOINC codes and the anchored flag are attached by
  anchor.py, never by the model. This is the "model proposes, code anchors"
  rule.

How the two families meet:

  PHP request (RunRequest) -> graph -> model reply (LabReportProposal or
      IntakeFormProposal) -> anchor.py attaches a Citation (and BBox) to
      every value -> LabReport / IntakeForm -> RunResponse back to PHP

Notes for readers new to Python and Pydantic:

* Pydantic turns a class definition into a validator. Each line of the form
  `name: type = Field(...)` declares one field: the type says what kind of
  value it holds, and Field(...) adds a rule the value must satisfy. Feeding
  JSON to `Model.model_validate(...)` either returns a typed object or raises
  ValidationError listing every rule that was broken.
* `Literal["a", "b"]` means "exactly one of these spellings"; anything else
  is rejected. `X | None` means the value may be null. A field with no
  `= default` is required: the key must be present, even if its value is
  null.
* `min_length=1` on a string rejects the empty string, which the type `str`
  alone would accept. On a list it means "at least one item".
* A model whose config says `extra="forbid"` rejects keys it does not
  declare, so a misspelled or unexpected field fails loudly instead of being
  silently dropped.
"""

from __future__ import annotations

from datetime import date
from typing import Literal

from pydantic import BaseModel, ConfigDict, Field, model_validator


class Strict(BaseModel):
    """Base class for every model in this file. `extra="forbid"` makes
    Pydantic reject any key the model does not declare, so a typo or a
    smuggled field is an error rather than ignored. The contract files
    say additionalProperties: false; this is the Python side of that."""
    model_config = ConfigDict(extra="forbid")


# ---- Contract models -------------------------------------------------------


class BBox(Strict):
    """A bounding box: the rectangle on a page where some text was found.

    Coordinates are PDF points (1/72 of an inch) measured from the top-left
    corner of the page, the one coordinate system parse.py produces for
    text-layer words and OCR words alike. The PHP viewer draws this rectangle
    over the page image so the clinician can see exactly where a value came
    from. page_w and page_h travel with the box so the viewer can scale it to
    whatever size it renders the page at.
    """
    page: int = Field(ge=1)  # 1-based page number; ge=1 rejects 0 and negatives
    x0: float  # left edge
    y0: float  # top edge
    x1: float  # right edge
    y1: float  # bottom edge
    # Pinned to a single allowed spelling each: the contract admits no other
    # coordinate system, so a box measured differently cannot slip through.
    origin: Literal["top-left"] = "top-left"
    units: Literal["pt"] = "pt"
    page_w: float  # page width in points
    page_h: float  # page height in points


# The three kinds of thing a citation can point at: a chart fact, an uploaded
# document, or a passage of the guideline corpus.
SourceType = Literal["chart", "document", "guideline"]


class Citation(Strict):
    """Where a value came from. Every extracted value carries one. The
    anchored flag is what the physician sees as "verified" or "unverified"
    next to the value: true means code found the value on the page, false
    means the model claims it and the code could not confirm it."""
    source_type: SourceType
    # Identifier of the source (a document id, a corpus source id). Never empty.
    source_id: str = Field(min_length=1)
    # Page number for documents, section heading for guidelines. May be empty
    # when the value was found nowhere, so it carries no min_length.
    page_or_section: str
    # A JSON-pointer-style path to the field inside the extraction, such as
    # "/results/3/value", or the chunk id for a guideline passage. Never empty.
    field_or_chunk_id: str = Field(min_length=1)
    # The text as proposed by the model (documents) or the quoted passage.
    quote_or_value: str
    # Rectangle around the matched words, and around the whole row they sit
    # in. Both are null when the value could not be located on the page.
    bbox: BBox | None = None
    row_bbox: BBox | None = None
    # Set by anchor.py only. The model never produces this field.
    anchored: bool

    def model_post_init(self, __context: object) -> None:
        """Pydantic calls this after the per-field checks pass; it is the
        place for a rule that involves two fields at once. A document
        citation may not claim to be anchored without the box that proves
        it, so "verified" can never be shown without a place to point at."""
        if self.source_type == "document" and self.anchored and self.bbox is None:
            raise ValueError("an anchored document citation requires a bbox")


# Lab flags as printed on reports: H high, L low, HH/LL critically high/low,
# A abnormal (non-numeric result), N normal.
AbnormalFlag = Literal["H", "L", "HH", "LL", "A", "N"]


class LabResult(Strict):
    """One result row of a lab report, after anchoring."""
    analyte: str = Field(min_length=1)  # the test name as printed
    # LOINC is the standard code for lab tests. Looked up by anchor.py from the
    # module's loinc_map.json; null when the printed name is not in the map.
    loinc: str | None
    # A string on purpose: "<5", "7.80" and "Positive" are all values, and a
    # number type would reshape or reject them. Never empty.
    value: str = Field(min_length=1)
    unit: str | None  # as printed, spelled through anchor.py's alias table
    reference_range: str | None  # the normal range as printed, e.g. "3.5-5.0"
    abnormal_flag: AbnormalFlag | None
    # True when the printed unit differs from the unit the LOINC map expects
    # for this analyte: a cue to check before comparing with chart values.
    unit_mismatch: bool
    citation: Citation  # where the value was found; anchored=false if it was not


class UnextractedRow(Strict):
    """A row that looks like a result but that no extracted result was
    anchored to. Listed so a row the model skipped stays visible to the
    clinician instead of disappearing."""
    page: int = Field(ge=1)
    text: str  # the row's words in reading order
    row_bbox: BBox  # always present: the row itself is what was found


class LabReport(Strict):
    """The whole extracted lab report: the shape PHP stores and displays."""
    # A fixed tag so a reader of the JSON can tell the two extraction shapes
    # apart, and so Pydantic can pick the right one when both are allowed.
    doc_type: Literal["lab_pdf"] = "lab_pdf"
    patient_name_on_report: str | None  # as printed; PHP compares it to the chart
    collection_date: date  # required: a report with no specimen date is refused
    collection_date_citation: Citation
    reported_date: date | None
    reported_date_citation: Citation | None
    lab_name: str | None
    # At least one result: a report with none is not a lab report and is
    # rejected here rather than displayed empty.
    results: list[LabResult] = Field(min_length=1)
    # default_factory=list gives each report its own new empty list. (A plain
    # `= []` default would be one list shared by every instance, a Python trap.)
    unextracted: list[UnextractedRow] = Field(default_factory=list)


class CitedString(Strict):
    """A single phrase with its citation, used for intake fields that are one
    value each (name, date of birth, chief concern)."""
    value: str = Field(min_length=1)
    citation: Citation


class Demographics(Strict):
    """Who the intake form is about. Each field is null when the form left
    it blank or the model could not read it."""
    name: CitedString | None
    dob: CitedString | None
    sex: CitedString | None
    phone: CitedString | None


class IntakeMedication(Strict):
    """One medication line. Only the name is anchored (its citation);
    dose and frequency are kept as the model proposed them."""
    name: str = Field(min_length=1)
    dose: str | None
    frequency: str | None
    citation: Citation


class IntakeAllergy(Strict):
    """One allergy line. The substance is anchored; the reaction is kept as
    proposed."""
    substance: str = Field(min_length=1)
    reaction: str | None
    citation: Citation


class IntakeFamilyHistory(Strict):
    """One family history line. The condition is anchored; the relative is
    kept as proposed."""
    relative: str = Field(min_length=1)
    condition: str = Field(min_length=1)
    citation: Citation


class IntakeForm(Strict):
    """The whole extracted intake form."""
    doc_type: Literal["intake_form"] = "intake_form"
    form_date: date | None
    form_date_citation: Citation | None
    demographics: Demographics
    chief_concern: CitedString | None
    # Lists may be empty (a patient with no medications); items are validated.
    medications: list[IntakeMedication]
    allergies: list[IntakeAllergy]
    family_history: list[IntakeFamilyHistory]


# The nodes of the graph in graph.py that can hand off. ("critic" is reserved
# in the contract; graph.py does not build one.)
Node = Literal["supervisor", "intake_extractor", "evidence_retriever", "critic"]
# Why a hop happened, as graph.py assigns them:
#   stored_document      a stored document of a supported type is waiting
#   question_present     answer mode and a question is waiting
#   no_documents         extract mode with no documents at all
#   no_question          nothing left to do (also the label of the final hop
#                        after an extraction)
#   already_extracted    documents were sent but none is still "stored"
#   unsupported_doc_type stored documents exist but none of a supported type
#   worker_finished      a worker reports success (or the final hop after a
#                        retrieval)
#   worker_failed        a worker reports failure
HandoffReason = Literal[
    "stored_document",
    "question_present",
    "no_documents",
    "no_question",
    "already_extracted",
    "unsupported_doc_type",
    "worker_finished",
    "worker_failed",
    "chart_triggers",  # brief mode: the chart fired guideline topics, retrieve them
    "no_triggers",  # brief mode: no topic applies, nothing to retrieve
    "applicability_check",  # brief mode: the critic checks each passage's population against the chart
]


class Handoff(Strict):
    """One hop in the graph's route log: who handed off to whom, why, and
    how long the step before it took. PHP writes each one to the trace.

    `from` is a reserved word in Python, so the attribute is named from_ and
    Field(alias="from") writes it out as "from" in JSON to match the contract.
    """
    from_: Node = Field(alias="from")
    to: Literal["supervisor", "intake_extractor", "evidence_retriever", "critic", "done"]
    reason: HandoffReason
    state_keys_changed: list[str]  # which parts of the shared state the hop wrote
    ms: int = Field(ge=0)  # milliseconds since the previous hop

    # populate_by_name lets Python code build a Handoff by the attribute name
    # (from_=...) as well as by the alias ("from").
    model_config = ConfigDict(extra="forbid", populate_by_name=True)


class Usage(Strict):
    """One paid call to a model provider: which model, what kind, and the
    token counts. PHP sums these onto the trace for cost tracking."""
    model: str
    kind: Literal["chat", "embedding", "rerank"]
    input: int = Field(ge=0)  # tokens sent (a rerank records 1 search unit here)
    output: int = Field(ge=0)  # tokens received (0 for embeddings and rerank)


DocType = Literal["lab_pdf", "intake_form"]
# Where the document is in its life: uploaded ("stored"), already processed,
# or processed and failed. Only "stored" documents are extracted in a run.
DocStatus = Literal["stored", "extracted", "failed"]


class RunDocument(Strict):
    """One document as PHP sends it in a run request."""
    document_id: int = Field(ge=1)  # OpenEMR's id for the stored document
    doc_type: DocType
    status: DocStatus
    # SHA3-512 hex digest of the file: exactly 128 lowercase hex characters.
    # The pattern rejects a truncated or uppercase hash, which would otherwise
    # produce a wrong idempotency key in app.py.
    sha3_512: str = Field(pattern=r"^[0-9a-f]{128}$")
    # The file itself, base64-encoded (binary written as text so it fits in
    # JSON); null when the document need not be sent. The sidecar never
    # stores it.
    bytes_base64: str | None


class TriggerQuery(Strict):
    """One guideline topic the chart fired (brief mode): the rule id and its
    fixed retrieval query, whose vector the index build committed."""
    trigger_id: str = Field(min_length=1, max_length=40, pattern=r"^[a-z0-9_-]+$")
    query: str = Field(min_length=1, max_length=500)


class RunRequest(Strict):
    """What PHP posts to /run."""
    mode: Literal["extract", "answer", "brief"]  # which worker the supervisor may use
    # Ties this run to PHP's logs and the trace. min_length=8 rejects an empty
    # or throwaway id that would orphan the trace.
    correlation_id: str = Field(min_length=8)
    # SHA-256 of the assembled chart facts (64 hex chars). Carried in the run
    # state so a response can be matched to the chart it was computed for;
    # the sidecar itself does not read it.
    facts_hash: str = Field(pattern=r"^[0-9a-f]{64}$")
    # No default, so the key must be present even when null. max_length bounds
    # what is sent to the embeddings model.
    question: str | None = Field(max_length=2000)
    documents: list[RunDocument]
    queries: list[TriggerQuery] = Field(default_factory=list, max_length=20)  # brief mode: the chart's fired triggers

    @model_validator(mode="after")
    def _brief_shape(self) -> "RunRequest":
        if self.mode == "brief" and self.question is not None:
            raise ValueError("brief mode carries no question")
        if self.mode != "brief" and self.queries:
            raise ValueError("queries travel only with brief mode")
        return self


# Why a document could not be extracted, in the words the UI shows:
#   encrypted        password-protected PDF
#   unreadable       not a PDF, no pages, or no text even after OCR
#   too_many_pages   more than parse.MAX_PAGES pages
#   model_error      the model provider failed or refused
#   schema_mismatch  the reply (or a date in it) did not fit the contract
#   timeout          the model call timed out
FailureReason = Literal["encrypted", "unreadable", "too_many_pages", "model_error", "schema_mismatch", "timeout"]


class Extraction(Strict):
    """The outcome for one document: an extraction or a failure code. A
    failed document is reported, not raised, so one bad file never sinks
    the other documents in the same run."""
    document_id: int = Field(ge=1)
    status: Literal["extracted", "failed"]
    failure_reason: FailureReason | None  # set when status is "failed"
    # Either shape, or null on failure. The doc_type tag on each shape lets
    # only one of them validate a given document.
    extraction: LabReport | IntakeForm | None
    # Share of citations that were anchored, between 0 and 1 inclusive.
    confidence: float = Field(ge=0, le=1)
    # Model calls beyond one per page (the omission-driven re-ask), for the dashboard's retry count.
    retries: int = Field(ge=0, default=0)


class Chunk(Strict):
    """One guideline passage returned by retrieval, with the fields the
    answer's citation needs."""
    chunk_id: str
    source_id: str  # which guideline document
    section: str  # "Title > Heading" path inside it
    quote: str  # the passage text
    score: float  # rerank relevance (0..1) or fused rank score, 4 decimals


class TriggerEvidence(Strict):
    """Brief mode: the passages retrieved for one fired trigger, and the
    critic's verdict on whether they describe this patient's population
    (None until the critic has run or when it could not)."""
    trigger_id: str
    chunks: list[Chunk] = Field(default_factory=list, max_length=2)
    applicable: bool | None = None
    reason: str | None = Field(default=None, max_length=500)


class RunResponse(Strict):
    """What /run returns on success."""
    correlation_id: str
    extractions: list[Extraction]
    # At most five passages: the cap the answer model is given (retrieve.TOP).
    chunks: list[Chunk] = Field(max_length=5)
    evidence: list[TriggerEvidence] = Field(default_factory=list)  # brief mode only
    handoffs: list[Handoff]  # the route log, one entry per hop
    usage: list[Usage]  # every paid call the run made


class RunError(Strict):
    """What /run returns on failure: an id and a fixed code, never a message,
    so no internal detail (a path, a stack trace) reaches the caller."""
    correlation_id: str
    code: Literal["bad_request", "parse_failed", "encrypted", "unreadable", "model_error", "timeout", "internal"]


# ---- Operator endpoints (contracts sidecar.health.response, sidecar.ready.response) ----


class SidecarHealth(Strict):
    """/health body: the process is alive and this is what it is running."""
    status: Literal["ok"] = "ok"
    prompt_version: str = Field(min_length=1)
    model: str = Field(min_length=1)
    parser: str = Field(min_length=1)


class SidecarReadyDependencies(Strict):
    """One entry per dependency a run needs; "ok" or a fixed reason string."""
    contracts: str = Field(min_length=1)
    loinc_map: str = Field(min_length=1)
    corpus_index: str = Field(min_length=1)
    tesseract: str = Field(min_length=1)
    openai_key: str = Field(min_length=1)


class SidecarReadyOptional(Strict):
    """Dependencies whose absence changes behaviour but does not block a run."""
    cohere_rerank: Literal["configured", "not configured"]


class SidecarReady(Strict):
    """/ready body. status is "not_ready" when any dependency is not "ok"."""
    status: Literal["ready", "not_ready"]
    dependencies: SidecarReadyDependencies
    optional: SidecarReadyOptional
    time: str


# ---- Proposal models: what the model returns -------------------------------
# Every field is required (OpenAI strict mode); "unknown" is null. No
# citations, no coordinates: the model only ever proposes values it read.
#
# Each item carries a `page`: the 1-based page the model says it read the
# value from. anchor.py searches that page, so a value the model attributes
# to the wrong page comes back unverified.


class LabResultProposal(Strict):
    """One result row as the model read it: values only, as printed."""
    analyte: str = Field(min_length=1)
    value: str = Field(min_length=1)
    unit: str | None
    reference_range: str | None
    abnormal_flag: AbnormalFlag | None
    page: int = Field(ge=1)


class LabReportProposal(Strict):
    """The model's reading of a lab report. Dates are strings, not dates: the
    model must copy what is printed, and anchor.py parses and anchors them."""
    patient_name_on_report: str | None
    collection_date: str | None
    reported_date: str | None
    lab_name: str | None
    results: list[LabResultProposal]


class IntakeMedicationProposal(Strict):
    name: str = Field(min_length=1)
    dose: str | None
    frequency: str | None
    page: int = Field(ge=1)


class IntakeAllergyProposal(Strict):
    substance: str = Field(min_length=1)
    reaction: str | None
    page: int = Field(ge=1)


class IntakeFamilyHistoryProposal(Strict):
    relative: str = Field(min_length=1)
    condition: str = Field(min_length=1)
    page: int = Field(ge=1)


class IntakeFormProposal(Strict):
    """The model's reading of an intake form. Demographics arrive as flat
    strings; anchor.py turns each into a CitedString with its own citation.
    Demographic fields carry no page hint: they are searched on every page."""
    form_date: str | None
    name: str | None
    dob: str | None
    sex: str | None
    phone: str | None
    chief_concern: str | None
    medications: list[IntakeMedicationProposal]
    allergies: list[IntakeAllergyProposal]
    family_history: list[IntakeFamilyHistoryProposal]
