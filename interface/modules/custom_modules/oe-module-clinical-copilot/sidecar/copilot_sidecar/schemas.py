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
"""

from __future__ import annotations

from datetime import date
from typing import Literal

from pydantic import BaseModel, ConfigDict, Field


class Strict(BaseModel):
    model_config = ConfigDict(extra="forbid")


# ---- Contract models -------------------------------------------------------


class BBox(Strict):
    page: int = Field(ge=1)
    x0: float
    y0: float
    x1: float
    y1: float
    origin: Literal["top-left"] = "top-left"
    units: Literal["pt"] = "pt"
    page_w: float
    page_h: float


SourceType = Literal["chart", "document", "guideline"]


class Citation(Strict):
    source_type: SourceType
    source_id: str = Field(min_length=1)
    page_or_section: str
    field_or_chunk_id: str = Field(min_length=1)
    quote_or_value: str
    bbox: BBox | None = None
    row_bbox: BBox | None = None
    anchored: bool

    def model_post_init(self, __context: object) -> None:
        if self.source_type == "document" and self.anchored and self.bbox is None:
            raise ValueError("an anchored document citation requires a bbox")


AbnormalFlag = Literal["H", "L", "HH", "LL", "A", "N"]


class LabResult(Strict):
    analyte: str = Field(min_length=1)
    loinc: str | None
    value: str = Field(min_length=1)
    unit: str | None
    reference_range: str | None
    abnormal_flag: AbnormalFlag | None
    unit_mismatch: bool
    citation: Citation


class UnextractedRow(Strict):
    page: int = Field(ge=1)
    text: str
    row_bbox: BBox


class LabReport(Strict):
    doc_type: Literal["lab_pdf"] = "lab_pdf"
    patient_name_on_report: str | None
    collection_date: date
    collection_date_citation: Citation
    reported_date: date | None
    reported_date_citation: Citation | None
    lab_name: str | None
    results: list[LabResult] = Field(min_length=1)
    unextracted: list[UnextractedRow] = Field(default_factory=list)


class CitedString(Strict):
    value: str = Field(min_length=1)
    citation: Citation


class Demographics(Strict):
    name: CitedString | None
    dob: CitedString | None
    sex: CitedString | None
    phone: CitedString | None


class IntakeMedication(Strict):
    name: str = Field(min_length=1)
    dose: str | None
    frequency: str | None
    citation: Citation


class IntakeAllergy(Strict):
    substance: str = Field(min_length=1)
    reaction: str | None
    citation: Citation


class IntakeFamilyHistory(Strict):
    relative: str = Field(min_length=1)
    condition: str = Field(min_length=1)
    citation: Citation


class IntakeForm(Strict):
    doc_type: Literal["intake_form"] = "intake_form"
    form_date: date | None
    form_date_citation: Citation | None
    demographics: Demographics
    chief_concern: CitedString | None
    medications: list[IntakeMedication]
    allergies: list[IntakeAllergy]
    family_history: list[IntakeFamilyHistory]


Node = Literal["supervisor", "intake_extractor", "evidence_retriever", "critic"]
HandoffReason = Literal[
    "stored_document",
    "question_present",
    "no_documents",
    "no_question",
    "already_extracted",
    "unsupported_doc_type",
    "worker_finished",
    "worker_failed",
]


class Handoff(Strict):
    from_: Node = Field(alias="from")
    to: Literal["supervisor", "intake_extractor", "evidence_retriever", "critic", "done"]
    reason: HandoffReason
    state_keys_changed: list[str]
    ms: int = Field(ge=0)

    model_config = ConfigDict(extra="forbid", populate_by_name=True)


class Usage(Strict):
    model: str
    kind: Literal["chat", "embedding", "rerank"]
    input: int = Field(ge=0)
    output: int = Field(ge=0)


DocType = Literal["lab_pdf", "intake_form"]
DocStatus = Literal["stored", "extracted", "failed"]


class RunDocument(Strict):
    document_id: int = Field(ge=1)
    doc_type: DocType
    status: DocStatus
    sha3_512: str = Field(pattern=r"^[0-9a-f]{128}$")
    bytes_base64: str | None


class RunRequest(Strict):
    mode: Literal["extract", "answer"]
    correlation_id: str = Field(min_length=8)
    facts_hash: str = Field(pattern=r"^[0-9a-f]{64}$")
    question: str | None = Field(max_length=2000)
    documents: list[RunDocument]


FailureReason = Literal["encrypted", "unreadable", "too_many_pages", "model_error", "schema_mismatch", "timeout"]


class Extraction(Strict):
    document_id: int = Field(ge=1)
    status: Literal["extracted", "failed"]
    failure_reason: FailureReason | None
    extraction: LabReport | IntakeForm | None
    confidence: float = Field(ge=0, le=1)
    # Model calls beyond one per page (the omission-driven re-ask), for the dashboard's retry count.
    retries: int = Field(ge=0, default=0)


class Chunk(Strict):
    chunk_id: str
    source_id: str
    section: str
    quote: str
    score: float


class RunResponse(Strict):
    correlation_id: str
    extractions: list[Extraction]
    chunks: list[Chunk] = Field(max_length=5)
    handoffs: list[Handoff]
    usage: list[Usage]


class RunError(Strict):
    correlation_id: str
    code: Literal["bad_request", "parse_failed", "encrypted", "unreadable", "model_error", "timeout", "internal"]


# ---- Proposal models: what the model returns -------------------------------
# Every field is required (OpenAI strict mode); "unknown" is null. No
# citations, no coordinates: the model only ever proposes values it read.


class LabResultProposal(Strict):
    analyte: str = Field(min_length=1)
    value: str = Field(min_length=1)
    unit: str | None
    reference_range: str | None
    abnormal_flag: AbnormalFlag | None
    page: int = Field(ge=1)


class LabReportProposal(Strict):
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
    form_date: str | None
    name: str | None
    dob: str | None
    sex: str | None
    phone: str | None
    chief_concern: str | None
    medications: list[IntakeMedicationProposal]
    allergies: list[IntakeAllergyProposal]
    family_history: list[IntakeFamilyHistoryProposal]
