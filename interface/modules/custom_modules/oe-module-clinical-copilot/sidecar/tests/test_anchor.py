"""Anchoring against the generated fixtures. No model: proposals are built
from truth.json (the ideal model output) and from deliberately wrong
variants, so these tests pin the rule "verified means found in the row",
independent of what any model returns."""

from __future__ import annotations

import json
from pathlib import Path

import pytest

from copilot_sidecar import anchor, parse
from copilot_sidecar.schemas import LabReportProposal, LabResultProposal
from tools import generate_fixtures


@pytest.fixture(scope="session")
def fixtures(tmp_path_factory) -> Path:
    out = tmp_path_factory.mktemp("docs")
    generate_fixtures.lab_layout1(out)
    generate_fixtures.lab_layout2(out)
    return out


def proposal_from_truth(truth: dict) -> LabReportProposal:
    return LabReportProposal(
        patient_name_on_report=truth["patient_name_on_report"],
        collection_date=truth["collection_date"],
        reported_date=truth["reported_date"],
        lab_name=truth["lab_name"],
        results=[LabResultProposal(analyte=r["analyte"], value=r["value"], unit=r["unit"], reference_range=r["reference_range"], abnormal_flag=r["abnormal_flag"], page=r["page"]) for r in truth["results"]],
    )


@pytest.mark.parametrize("name", ["lab-layout1", "lab-layout1-scan", "lab-layout2"])
def test_every_true_result_anchors_to_its_own_row(fixtures: Path, name: str) -> None:
    parsed = parse.parse_pdf((fixtures / f"{name}.pdf").read_bytes())
    truth = json.loads((fixtures / f"{name}.truth.json").read_text())
    report, reason = anchor.build_lab_report(1, parsed, proposal_from_truth(truth), anchor.load_loinc_map())
    assert report is not None, reason
    assert report.collection_date_citation.anchored, "collection date must anchor"
    for want, got in zip(truth["results"], report.results):
        assert got.citation.anchored, f"{want['analyte']} not anchored"
        assert got.citation.bbox.page == want["page"]
        # The row box must contain the analyte word and the value box.
        row = got.citation.row_bbox
        assert row.x0 <= got.citation.bbox.x0 and row.x1 >= got.citation.bbox.x1
        assert got.loinc is not None or want["analyte"] not in ("Glucose", "LDL Cholesterol")
        assert not got.unit_mismatch
    assert anchor.confidence(anchor.citations_of(report)) == 1.0


def test_scan_is_ocr(fixtures: Path) -> None:
    parsed = parse.parse_pdf((fixtures / "lab-layout1-scan.pdf").read_bytes())
    assert parsed.ocr_pages == 5
    text = parse.parse_pdf((fixtures / "lab-layout1.pdf").read_bytes())
    assert text.ocr_pages == 0


def test_hundred_in_three_columns_anchors_only_the_ldl_result(fixtures: Path) -> None:
    parsed = parse.parse_pdf((fixtures / "lab-layout2.pdf").read_bytes())
    b, row, page = anchor.anchor_lab_result(parsed, "LDL Cholesterol", "100", "mg/dL", 1)
    assert b is not None and page == "1"
    # The box must be the LDL row's result cell, left of the unit column.
    assert b.x0 < 300
    # A swapped proposal: Glucose reported as 100 (its range upper bound) must not anchor.
    assert anchor.anchor_lab_result(parsed, "Glucose", "100", "mg/dL", 1)[0] is None
    # Total Cholesterol 100 is the prior column value, not the current result: the row
    # has 212 and 100; the value 100 sits right of the unit, so it is not the result.
    assert anchor.anchor_lab_result(parsed, "Total Cholesterol", "100", "mg/dL", 1)[0] is None


def test_wrong_value_or_unit_is_unverified_not_dropped(fixtures: Path) -> None:
    parsed = parse.parse_pdf((fixtures / "lab-layout1.pdf").read_bytes())
    truth = json.loads((fixtures / "lab-layout1.truth.json").read_text())
    prop = proposal_from_truth(truth)
    prop.results[0].value = "999"          # wrong number
    prop.results[1].unit = "mmol/L"        # wrong unit for BUN
    prop.results[2].analyte = "Creatinin"  # OCR-style typo, within tolerance
    report, _ = anchor.build_lab_report(1, parsed, prop, anchor.load_loinc_map())
    assert report is not None
    assert not report.results[0].citation.anchored
    assert not report.results[1].citation.anchored
    assert report.results[2].citation.anchored
    assert len(report.results) == len(truth["results"])
    assert 0 < anchor.confidence(anchor.citations_of(report)) < 1


def test_unit_mismatch_flag(fixtures: Path) -> None:
    parsed = parse.parse_pdf((fixtures / "lab-layout1.pdf").read_bytes())
    truth = json.loads((fixtures / "lab-layout1.truth.json").read_text())
    prop = proposal_from_truth(truth)
    # Glucose printed in mg/dL; pretend the report said mmol/L: unanchored AND mismatched.
    prop.results[0].unit = "mmol/L"
    report, _ = anchor.build_lab_report(1, parsed, prop, anchor.load_loinc_map())
    assert report is not None
    assert report.results[0].unit_mismatch is True


def test_normalisation() -> None:
    assert anchor.norm_number("7.80") == "7.8"
    assert anchor.norm_number("1,234") == "1234"
    assert anchor.norm_number("<5") == "<5"
    assert anchor.norm_number("H") is None
    assert anchor.norm_unit("MG/DL") == "mg/dL"
    assert anchor.words_match("Creatinine", "Creatinin")
    assert not anchor.words_match("BUN", "BUM")  # short words must match exactly
    assert anchor.parse_date("09/18/2026").isoformat() == "2026-09-18"
    assert anchor.parse_date("September 18, 2026").isoformat() == "2026-09-18"


def test_parse_errors() -> None:
    with pytest.raises(parse.ParseError) as e:
        parse.parse_pdf(b"%PDF-1.4 garbage")
    assert e.value.code in ("parse_failed", "unreadable")
    import fitz

    doc = fitz.open()
    for _ in range(6):
        doc.new_page()
    with pytest.raises(parse.ParseError) as e:
        parse.parse_pdf(doc.tobytes())
    assert e.value.code == "too_many_pages"
    enc = fitz.open()
    enc.new_page().insert_text((50, 50), "secret")
    with pytest.raises(parse.ParseError) as e:
        parse.parse_pdf(enc.tobytes(encryption=fitz.PDF_ENCRYPT_AES_256, user_pw="x", owner_pw="x"))
    assert e.value.code == "encrypted"
