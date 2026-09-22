"""Synthetic document fixtures for the eval suite. Fictional patient, fixed
seed, fully described by the truth.json written beside each PDF.

Usage: python -m tools.generate_fixtures <output dir>

lab-layout1.pdf        text layer; Test / Result / Flag / Units / Reference / Previous
lab-layout1-scan.pdf   the same report rasterized at 150 dpi as JPEG pages, no text layer
lab-layout2.pdf        two-column report where the string "100" appears in the LDL result
                       cell, in another row's reference range, and in a previous column
                       (the Codex "100 in three columns" case); different header wording
Each has <name>.truth.json listing every clinical field with page and row text,
which anchor-mode eval cases compare against.
"""

from __future__ import annotations

import json
import random
import sys
from pathlib import Path

import fitz

PATIENT = "Test Zeta"
DOB = "1970-01-01"

ANALYTES = [
    ("Glucose", "mg/dL", "70-99"), ("BUN", "mg/dL", "7-20"), ("Creatinine", "mg/dL", "0.6-1.2"),
    ("Sodium", "mmol/L", "136-145"), ("Potassium", "mmol/L", "3.5-5.1"), ("Chloride", "mmol/L", "98-107"),
    ("Calcium", "mg/dL", "8.6-10.3"), ("Total Cholesterol", "mg/dL", "<200"), ("LDL Cholesterol", "mg/dL", "<100"),
    ("HDL Cholesterol", "mg/dL", ">40"), ("Triglycerides", "mg/dL", "<150"), ("Hemoglobin A1c", "%", "4.0-5.6"),
    ("Hemoglobin", "g/dL", "13.5-17.5"), ("Hematocrit", "%", "41-53"), ("WBC", "10^3/uL", "4.5-11.0"),
    ("Platelets", "10^3/uL", "150-400"), ("TSH", "uIU/mL", "0.4-4.0"), ("ALT", "U/L", "7-56"),
    ("AST", "U/L", "10-40"), ("Vitamin D", "ng/mL", "30-100"),
]


def _value(rng: random.Random, analyte: str) -> tuple[str, str]:
    ranges = {"Glucose": (70, 180), "BUN": (7, 30), "Creatinine": (0.6, 1.8), "Sodium": (132, 146), "Potassium": (3.2, 5.6),
              "Chloride": (96, 110), "Calcium": (8.2, 10.8), "Total Cholesterol": (140, 280), "LDL Cholesterol": (60, 190),
              "HDL Cholesterol": (30, 80), "Triglycerides": (60, 320), "Hemoglobin A1c": (4.8, 9.5), "Hemoglobin": (9.5, 17.0),
              "Hematocrit": (30, 52), "WBC": (3.5, 14.0), "Platelets": (120, 420), "TSH": (0.2, 8.0), "ALT": (10, 90),
              "AST": (12, 70), "Vitamin D": (12, 60)}
    lo, hi = ranges[analyte]
    v = round(rng.uniform(lo, hi), 1)
    flag = ""
    ref = dict((a, r) for a, _, r in ANALYTES)[analyte]
    if ref.startswith("<") and v >= float(ref[1:]):
        flag = "H"
    elif ref.startswith(">") and v <= float(ref[1:]):
        flag = "L"
    elif "-" in ref:
        a, b = (float(x) for x in ref.split("-"))
        flag = "H" if v > b else ("L" if v < a else "")
    return (f"{v:g}" if v != int(v) else str(int(v)), flag)


def lab_layout1(out: Path, seed: int = 7) -> None:
    rng = random.Random(seed)
    doc = fitz.open()
    truth = {"doc_type": "lab_pdf", "patient_name_on_report": PATIENT, "collection_date": "2026-09-15", "reported_date": "2026-09-16", "lab_name": "Synthetic Labs Inc", "results": []}
    per_page = 4
    for page_no in range(5):
        p = doc.new_page(width=612, height=792)
        y = 60
        p.insert_text((50, y), "SYNTHETIC LABS INC", fontsize=13); y += 16
        p.insert_text((50, y), "Fixture for evals; fictional patient, not a real report", fontsize=8); y += 18
        p.insert_text((50, y), f"Patient: {PATIENT}    DOB: {DOB}    Collected: 2026-09-15    Reported: 2026-09-16", fontsize=9); y += 14
        p.insert_text((50, y), f"Page {page_no + 1} of 5", fontsize=9); y += 24
        for x, h in [(50, "Test"), (230, "Result"), (300, "Flag"), (350, "Units"), (430, "Reference"), (520, "Previous")]:
            p.insert_text((x, y), h, fontsize=10)
        y += 6; p.draw_line((50, y), (560, y)); y += 16
        for analyte, unit, ref in ANALYTES[page_no * per_page:(page_no + 1) * per_page]:
            value, flag = _value(rng, analyte)
            prev = f"{float(value) * rng.uniform(0.85, 1.15):.1f}"
            for x, t in [(50, analyte), (230, value), (300, flag), (350, unit), (430, ref), (520, prev)]:
                p.insert_text((x, y), t, fontsize=10)
            truth["results"].append({"analyte": analyte, "value": value, "unit": unit, "reference_range": ref, "abnormal_flag": flag or None, "page": page_no + 1, "row_text": f"{analyte} {value} {flag} {unit} {ref} {prev}".replace("  ", " ")})
            y += 18
    doc.save(out / "lab-layout1.pdf", garbage=4, deflate=True)
    (out / "lab-layout1.truth.json").write_text(json.dumps(truth, indent=2) + "\n")

    scan = fitz.open()
    for pg in doc:
        pix = pg.get_pixmap(dpi=150)
        np_ = scan.new_page(width=612, height=792)
        np_.insert_image(np_.rect, stream=pix.tobytes("jpeg", jpg_quality=70), rotate=0)
    scan.save(out / "lab-layout1-scan.pdf", garbage=4, deflate=True)
    (out / "lab-layout1-scan.truth.json").write_text(json.dumps(truth, indent=2) + "\n")


def lab_layout2(out: Path) -> None:
    """Two panels on one page, no 'Previous' header word, and '100' three times:
    LDL result = 100, Glucose reference range upper bound 100 printed as '70-100',
    and Total Cholesterol previous value 100. Only the LDL 100 is a result."""
    doc = fitz.open()
    p = doc.new_page(width=612, height=792)
    y = 60
    p.insert_text((50, y), "REGIONAL REFERENCE LABORATORY", fontsize=13); y += 16
    p.insert_text((50, y), "Fixture for evals; fictional patient", fontsize=8); y += 18
    p.insert_text((50, y), f"Name: {PATIENT}   Date of birth: {DOB}   Specimen collected 09/18/2026   Final report 09/19/2026", fontsize=9); y += 28
    p.insert_text((50, y), "LIPID PANEL", fontsize=11); y += 16
    rows = [("Total Cholesterol", "212", "H", "mg/dL", "<200", "100"), ("LDL Cholesterol", "100", "H", "mg/dL", "<100", "118"),
            ("HDL Cholesterol", "48", "", "mg/dL", ">40", "45"), ("Triglycerides", "160", "H", "mg/dL", "<150", "140")]
    truth = {"doc_type": "lab_pdf", "patient_name_on_report": PATIENT, "collection_date": "2026-09-18", "reported_date": "2026-09-19", "lab_name": "Regional Reference Laboratory", "results": []}
    for x, h in [(50, "Analyte"), (220, "Current"), (280, "F"), (320, "Unit"), (400, "Range"), (500, "Prior")]:
        p.insert_text((x, y), h, fontsize=9)
    y += 14
    for analyte, val, flag, unit, ref, prior in rows:
        for x, t in [(50, analyte), (220, val), (280, flag), (320, unit), (400, ref), (500, prior)]:
            p.insert_text((x, y), t, fontsize=10)
        truth["results"].append({"analyte": analyte, "value": val, "unit": unit, "reference_range": ref, "abnormal_flag": flag or None, "page": 1, "row_text": f"{analyte} {val} {flag} {unit} {ref} {prior}".replace("  ", " ")})
        y += 18
    y += 20
    p.insert_text((50, y), "METABOLIC PANEL", fontsize=11); y += 16
    rows2 = [("Glucose", "92", "", "mg/dL", "70-100", "95"), ("Creatinine", "1.1", "", "mg/dL", "0.6-1.2", "1.0"), ("Potassium", "4.4", "", "mmol/L", "3.5-5.1", "4.2")]
    for x, h in [(50, "Analyte"), (220, "Current"), (280, "F"), (320, "Unit"), (400, "Range"), (500, "Prior")]:
        p.insert_text((x, y), h, fontsize=9)
    y += 14
    for analyte, val, flag, unit, ref, prior in rows2:
        for x, t in [(50, analyte), (220, val), (280, flag), (320, unit), (400, ref), (500, prior)]:
            p.insert_text((x, y), t, fontsize=10)
        truth["results"].append({"analyte": analyte, "value": val, "unit": unit, "reference_range": ref, "abnormal_flag": flag or None, "page": 1, "row_text": f"{analyte} {val} {flag} {unit} {ref} {prior}".replace("  ", " ")})
        y += 18
    doc.save(out / "lab-layout2.pdf", garbage=4, deflate=True)
    (out / "lab-layout2.truth.json").write_text(json.dumps(truth, indent=2) + "\n")


def main() -> None:
    out = Path(sys.argv[1] if len(sys.argv) > 1 else ".")
    out.mkdir(parents=True, exist_ok=True)
    lab_layout1(out)
    lab_layout2(out)
    print("wrote", sorted(p.name for p in out.glob("lab-*")))


if __name__ == "__main__":
    main()
