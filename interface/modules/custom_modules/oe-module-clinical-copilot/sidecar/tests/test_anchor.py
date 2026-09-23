"""Anchoring against the generated fixtures. No model: proposals are built
from truth.json (the ideal model output) and from deliberately wrong
variants, so these tests pin the rule "verified means found in the row",
independent of what any model returns.

Why this matters clinically: the model only proposes values; anchor.py is
what decides whether a value is shown to the clinician as verified. These
tests are the proof that a correct proposal is accepted, a wrong one is
kept but marked unverified, and a value is never attributed to the wrong
row. If they fail, either real results would show as unverified (lost
trust, extra work) or invented ones would show as verified (unsafe).

Reading the test code: a pytest "fixture" is a setup function; any test
that names it as an argument receives its return value. "parametrize" runs
one test function once per listed input. `assert x, "message"` fails the
test with that message when x is false."""

from __future__ import annotations

import json
from pathlib import Path

import pytest

from copilot_sidecar import anchor, parse
from copilot_sidecar.schemas import LabReportProposal, LabResultProposal
from tools import generate_fixtures


# Generates the lab PDFs once per test run into a throwaway directory
# (tmp_path_factory is pytest's temporary-directory maker). scope="session"
# means every test shares the one directory instead of regenerating; the
# committed fixtures under tests/evals are never read or written here.
@pytest.fixture(scope="session")
def fixtures(tmp_path_factory) -> Path:
    out = tmp_path_factory.mktemp("docs")
    generate_fixtures.lab_layout1(out)
    generate_fixtures.lab_layout2(out)
    return out


# Builds the proposal a perfect model would return: every field copied from
# truth.json, values only, no coordinates. Tests then either anchor it as is
# or corrupt one field to see the anchoring notice.
def proposal_from_truth(truth: dict) -> LabReportProposal:
    return LabReportProposal(
        patient_name_on_report=truth["patient_name_on_report"],
        collection_date=truth["collection_date"],
        reported_date=truth["reported_date"],
        lab_name=truth["lab_name"],
        results=[LabResultProposal(analyte=r["analyte"], value=r["value"], unit=r["unit"], reference_range=r["reference_range"], abnormal_flag=r["abnormal_flag"], page=r["page"]) for r in truth["results"]],
    )


# Pins: with a correct proposal, every result on every fixture (text layer,
# scan, and the two-panel layout) anchors to its own page and row, with a
# LOINC code where the map has one, no unit mismatch, and confidence 1.0.
# A failure means true results would reach the clinician marked unverified.
@pytest.mark.parametrize("name", ["lab-layout1", "lab-layout1-scan", "lab-layout2"])
def test_every_true_result_anchors_to_its_own_row(fixtures: Path, name: str) -> None:
    parsed = parse.parse_pdf((fixtures / f"{name}.pdf").read_bytes())
    truth = json.loads((fixtures / f"{name}.truth.json").read_text())
    report, reason = anchor.build_lab_report(1, parsed, proposal_from_truth(truth), anchor.load_loinc_map())
    assert report is not None, reason
    assert report.collection_date_citation.anchored, "collection date must anchor"
    # `zip` walks the truth rows and the built results side by side, in order.
    for want, got in zip(truth["results"], report.results):
        assert got.citation.anchored, f"{want['analyte']} not anchored"
        assert got.citation.bbox.page == want["page"]
        # The row box must contain the analyte word and the value box.
        row = got.citation.row_bbox
        assert row.x0 <= got.citation.bbox.x0 and row.x1 >= got.citation.bbox.x1
        # Glucose and LDL are in loinc_map.json, so they must carry a code;
        # other analytes may or may not, and this line does not judge them.
        assert got.loinc is not None or want["analyte"] not in ("Glucose", "LDL Cholesterol")
        assert not got.unit_mismatch
    # Confidence is the anchored fraction; all anchored means exactly 1.0.
    assert anchor.confidence(anchor.citations_of(report)) == 1.0


# Pins: the scan has no text layer, so all five pages go through OCR, and
# the text-layer twin needs none. A failure means either OCR is not running
# on scans (blank extractions) or is running on text PDFs (slow and lossy).
def test_scan_is_ocr(fixtures: Path) -> None:
    parsed = parse.parse_pdf((fixtures / "lab-layout1-scan.pdf").read_bytes())
    assert parsed.ocr_pages == 5
    text = parse.parse_pdf((fixtures / "lab-layout1.pdf").read_bytes())
    assert text.ocr_pages == 0


# Pins the Codex "100 in three columns" case: "100" is on the page as an LDL
# result, a glucose range bound and a prior cholesterol value. Only the LDL
# one may anchor. A failure means a value could be shown as verified for
# the wrong analyte, which is the one error a clinician cannot spot.
def test_hundred_in_three_columns_anchors_only_the_ldl_result(fixtures: Path) -> None:
    parsed = parse.parse_pdf((fixtures / "lab-layout2.pdf").read_bytes())
    b, row, page = anchor.anchor_lab_result(parsed, "LDL Cholesterol", "100", "mg/dL", 1)
    assert b is not None and page == "1"
    # The box must be the LDL row's result cell, left of the unit column.
    assert b.x0 < 300
    # A swapped proposal: Glucose reported as 100 (its range upper bound) must not anchor.
    # "70-100" is one printed token, so no word in the Glucose row equals 100.
    assert anchor.anchor_lab_result(parsed, "Glucose", "100", "mg/dL", 1)[0] is None
    # Total Cholesterol 100 is the prior column value, not the current result: the row
    # has 212 and 100; the value 100 sits right of the unit, so it is not the result.
    # The page's header row names that column "Prior", and anchor.py refuses
    # any value that sits under a prior-column heading.
    assert anchor.anchor_lab_result(parsed, "Total Cholesterol", "100", "mg/dL", 1)[0] is None


# Pins the "unverified, not absent" rule: a wrong value or wrong unit is
# kept in the report with anchored=false, an OCR-style one-letter typo in an
# analyte name is tolerated, and the result count is unchanged. A failure
# means the sidecar either hid a model error (the clinician never sees a
# flag) or dropped a result the clinician needed to review.
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
    # Some anchored, some not: confidence must be strictly between 0 and 1.
    assert 0 < anchor.confidence(anchor.citations_of(report)) < 1


# Pins the unit_mismatch flag: when the proposed unit differs from the unit
# the LOINC map expects for that analyte, the result is flagged. Glucose in
# mmol/L versus mg/dL differs by a factor of 18; an unflagged mix-up would
# read as a dangerously wrong number.
def test_unit_mismatch_flag(fixtures: Path) -> None:
    parsed = parse.parse_pdf((fixtures / "lab-layout1.pdf").read_bytes())
    truth = json.loads((fixtures / "lab-layout1.truth.json").read_text())
    prop = proposal_from_truth(truth)
    # Glucose printed in mg/dL; pretend the report said mmol/L: unanchored AND mismatched.
    prop.results[0].unit = "mmol/L"
    report, _ = anchor.build_lab_report(1, parsed, prop, anchor.load_loinc_map())
    assert report is not None
    assert report.results[0].unit_mismatch is True


# Pins the comparison rules from the design doc: trailing zeros and thousands
# separators are ignored, "<5" style values keep their sign, unit spelling is
# case-insensitive through the alias table, a one-letter difference is
# tolerated in long words but not short ones, and dates parse in the
# printed forms labs use. A change here changes what counts as "found".
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


# Pins the parser's failure codes: garbage bytes, a six-page document and a
# password-protected file each raise ParseError with the code PHP shows the
# clinician. `with pytest.raises(...) as e:` means "the code inside this
# block must raise this error; capture it as e".
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


# Pins the intake path, text layer and scan: the date parses, the chief
# concern, every medication, allergy, family-history entry and the name
# anchor to the form, and a medication the form never mentions does not.
# A failure means intake fields would show as unverified, or an invented
# medication could appear verified on the reconciliation screen.
def test_intake_form_anchors_every_field(fixtures: Path) -> None:
    from copilot_sidecar.schemas import IntakeAllergyProposal, IntakeFamilyHistoryProposal, IntakeFormProposal, IntakeMedicationProposal

    # The session fixture only made the lab PDFs; add the intake pair here.
    generate_fixtures.intake_full(fixtures)
    for name in ["intake-full", "intake-full-scan"]:
        parsed = parse.parse_pdf((fixtures / f"{name}.pdf").read_bytes())
        truth = json.loads((fixtures / f"{name}.truth.json").read_text())
        prop = IntakeFormProposal(
            form_date="09/20/2026", name=truth["demographics"]["name"], dob=truth["demographics"]["dob"], sex="F", phone=truth["demographics"]["phone"],
            chief_concern=truth["chief_concern"],
            medications=[IntakeMedicationProposal(name=m["name"], dose=m["dose"], frequency=m["frequency"], page=1) for m in truth["medications"]],
            allergies=[IntakeAllergyProposal(substance=a["substance"], reaction=a["reaction"], page=1) for a in truth["allergies"]],
            family_history=[IntakeFamilyHistoryProposal(relative=f["relative"], condition=f["condition"], page=1) for f in truth["family_history"]],
        )
        form, reason = anchor.build_intake_form(1, parsed, prop)
        assert form is not None, reason
        assert form.form_date.isoformat() == "2026-09-20"
        assert form.chief_concern is not None and form.chief_concern.citation.anchored, name
        assert [m.name for m in form.medications] == [m["name"] for m in truth["medications"]]
        assert all(m.citation.anchored for m in form.medications), name
        assert all(a.citation.anchored for a in form.allergies), name
        assert all(f.citation.anchored for f in form.family_history), name
        assert form.demographics.name is not None and form.demographics.name.citation.anchored
        # A medication the form does not mention must not anchor.
        # `model_copy(update=...)` makes a changed copy and leaves `prop` intact.
        prop2 = prop.model_copy(update={"medications": [IntakeMedicationProposal(name="warfarin", dose=None, frequency=None, page=1)]})
        form2, _ = anchor.build_intake_form(1, parsed, prop2)
        assert form2 is not None and not form2.medications[0].citation.anchored
