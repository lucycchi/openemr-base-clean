"""Synthetic document fixtures for the eval suite. Fictional patient, fixed
seed, fully described by the truth.json written beside each PDF.

Usage: python -m tools.generate_fixtures <output dir>

lab-layout1.pdf        text layer; Test / Result / Flag / Units / Reference / Previous
lab-layout1-scan.pdf   the same report rasterized at 150 dpi as JPEG pages, no text layer
lab-layout2.pdf        two-column report where the string "100" appears in the LDL result
                       cell, in another row's reference range, and in a previous column
                       (the Codex "100 in three columns" case); different header wording
intake-full.pdf        a filled new-patient intake form: free-text fields, not a table
intake-full-scan.pdf   the same form as a 150 dpi scan, no text layer
corrupt.pdf            a truncated file the parser cannot open
lab-encrypted.pdf      a password-protected report
lab-6page.pdf          one page over the five-page cap
lab-blank-scan.pdf     a scanned page with nothing printed on it
Each has <name>.truth.json listing every clinical field with page and row text,
which anchor-mode eval cases compare against.

Why the fixtures are synthetic: no real patient document may enter the
repository, and the eval cases need to know exactly what is printed where.
"Test Zeta" is invented, and every value is drawn from a seeded random
generator, so the same PDFs and the same truth files come out on every run.

Two JSON files sit beside each good PDF in tests/evals/fixtures/docs/:

  <name>.truth.json  what the page really says: every result with its page
                     and the text of its row. Written by this script. The
                     anchor-mode eval cases compare the sidecar's output to it.
  <name>.model.json  a recorded model proposal (the raw reply of one real run,
                     captured through the /eval/extract endpoint). Not written
                     here. Anchoring cases feed it to /eval/anchor so they run
                     without calling the model: the same proposal, the same
                     answer, every time, at no cost.

The "malformed" set (corrupt, encrypted, six pages, blank scan) has no truth
file: those cases expect a failure code, not an extraction.

Reading the drawing code: every `p.insert_text((x, y), ...)` places a string
at a position on the page measured in PDF points (1/72 inch) from the top
left corner; `y += n` moves the cursor down for the next line. The x values
in the column lists are the left edges of the table columns. The row-level
anchoring in anchor.py depends on words sharing a row and on the header
words above them, so the column positions are part of what is being tested.
"""

from __future__ import annotations

import json
import random
import sys
from pathlib import Path

import fitz

# The one fictional patient every fixture is about.
PATIENT = "Test Zeta"
DOB = "1970-01-01"

# Twenty common analytes as (name, unit, reference range as printed). Twenty
# because lab-layout1 prints four per page over five pages, the page cap.
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
    """One plausible result for an analyte, with the H/L flag a lab would print.

    `rng` is a seeded random generator, so the "random" values repeat exactly
    on every run. The sampling ranges below are deliberately wider than the
    reference ranges in ANALYTES, so some values land outside the reference
    range and carry an abnormal flag; the fixtures then exercise flagged and
    unflagged rows alike. The flag is derived from the reference range the
    way a lab would: above an upper bound is H, below a lower bound is L.
    The value is returned as the string the report prints (no trailing ".0").
    """
    ranges = {"Glucose": (70, 180), "BUN": (7, 30), "Creatinine": (0.6, 1.8), "Sodium": (132, 146), "Potassium": (3.2, 5.6),
              "Chloride": (96, 110), "Calcium": (8.2, 10.8), "Total Cholesterol": (140, 280), "LDL Cholesterol": (60, 190),
              "HDL Cholesterol": (30, 80), "Triglycerides": (60, 320), "Hemoglobin A1c": (4.8, 9.5), "Hemoglobin": (9.5, 17.0),
              "Hematocrit": (30, 52), "WBC": (3.5, 14.0), "Platelets": (120, 420), "TSH": (0.2, 8.0), "ALT": (10, 90),
              "AST": (12, 70), "Vitamin D": (12, 60)}
    lo, hi = ranges[analyte]
    v = round(rng.uniform(lo, hi), 1)
    flag = ""
    # Look up the printed reference range for this analyte, then apply it.
    # Ranges come in three shapes: "<200", ">40" and "70-99".
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
    """The baseline lab report and its scanned twin.

    Five US-letter pages, four analytes per page, six columns including a
    "Previous" column, so every page has a header row for anchor.py to read
    and a prior-result column it must refuse to anchor to. The page count is
    exactly the parser's cap (MAX_PAGES = 5): a full-length report passes.

    Two PDFs come out with one truth file each:
      lab-layout1.pdf       with a text layer (the parser reads words directly)
      lab-layout1-scan.pdf  the same pages as JPEG images, so there is no text
                            layer and the parser has to run OCR (tesseract)
    The scan shares the same truth, because the content is identical; only
    the way the words reach the parser differs. That is the point: the eval
    cases show whether OCR loses anything the text layer keeps.
    """
    rng = random.Random(seed)
    # `fitz.open()` with no file creates an empty in-memory PDF to draw into.
    doc = fitz.open()
    truth = {"doc_type": "lab_pdf", "patient_name_on_report": PATIENT, "collection_date": "2026-09-15", "reported_date": "2026-09-16", "lab_name": "Synthetic Labs Inc", "results": []}
    per_page = 4
    for page_no in range(5):
        # 612 x 792 points is US letter. Each page repeats the lab header,
        # the patient line and the column headings, like a real multi-page report.
        p = doc.new_page(width=612, height=792)
        y = 60
        p.insert_text((50, y), "SYNTHETIC LABS INC", fontsize=13); y += 16
        p.insert_text((50, y), "Fixture for evals; fictional patient, not a real report", fontsize=8); y += 18
        p.insert_text((50, y), f"Patient: {PATIENT}    DOB: {DOB}    Collected: 2026-09-15    Reported: 2026-09-16", fontsize=9); y += 14
        p.insert_text((50, y), f"Page {page_no + 1} of 5", fontsize=9); y += 24
        # Column headings. These exact words matter: anchor.py recognises a
        # header row by them ("Result" marks the result column, "Previous" a
        # prior column) and uses their x positions as the column boundaries.
        for x, h in [(50, "Test"), (230, "Result"), (300, "Flag"), (350, "Units"), (430, "Reference"), (520, "Previous")]:
            p.insert_text((x, y), h, fontsize=10)
        y += 6; p.draw_line((50, y), (560, y)); y += 16
        # This page's slice of the analyte list: four rows, one analyte each.
        for analyte, unit, ref in ANALYTES[page_no * per_page:(page_no + 1) * per_page]:
            value, flag = _value(rng, analyte)
            # A made-up previous result near the current one, so the
            # "Previous" column holds a number that could be mistaken for
            # the result if the anchoring ignored columns.
            prev = f"{float(value) * rng.uniform(0.85, 1.15):.1f}"
            for x, t in [(50, analyte), (230, value), (300, flag), (350, unit), (430, ref), (520, prev)]:
                p.insert_text((x, y), t, fontsize=10)
            # Record what was just printed: the values, the page, and the
            # row's text as a reader would see it. An empty flag becomes
            # null, matching the contract's abnormal_flag field.
            truth["results"].append({"analyte": analyte, "value": value, "unit": unit, "reference_range": ref, "abnormal_flag": flag or None, "page": page_no + 1, "row_text": f"{analyte} {value} {flag} {unit} {ref} {prev}".replace("  ", " ")})
            y += 18
    # garbage=4 and deflate=True compact the file; they do not change the content.
    doc.save(out / "lab-layout1.pdf", garbage=4, deflate=True)
    (out / "lab-layout1.truth.json").write_text(json.dumps(truth, indent=2) + "\n")

    # The scanned twin: render each finished page to a 150 dpi bitmap, JPEG
    # compress it at moderate quality (a real office scanner's output), and
    # place that picture on a fresh page. The picture has no text layer, so
    # parse.py finds no words and falls back to OCR for every page.
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
    and Total Cholesterol previous value 100. Only the LDL 100 is a result.

    This is the fixture behind the row-anchoring rule. An earlier design
    anchored a value if it appeared anywhere on the page; the Codex review
    pointed out that "100" on this page proves nothing about which analyte
    it belongs to. The eval case requires the LDL 100 to anchor to the LDL
    row only, and a proposal that swaps it onto Glucose or Total Cholesterol
    to come back unverified. For a clinician the difference is an LDL of 100
    shown as verified versus a glucose of 100 that was never measured.

    The header wording also differs from layout 1 ("Analyte / Current / F /
    Unit / Range / Prior") to show anchor.py reads header vocabulary, not one
    fixed layout. Two panels means two header rows on one page: each row of
    results is judged against the header nearest above it.
    """
    doc = fitz.open()
    p = doc.new_page(width=612, height=792)
    y = 60
    p.insert_text((50, y), "REGIONAL REFERENCE LABORATORY", fontsize=13); y += 16
    p.insert_text((50, y), "Fixture for evals; fictional patient", fontsize=8); y += 18
    p.insert_text((50, y), f"Name: {PATIENT}   Date of birth: {DOB}   Specimen collected 09/18/2026   Final report 09/19/2026", fontsize=9); y += 28
    p.insert_text((50, y), "LIPID PANEL", fontsize=11); y += 16
    # First panel. Each row is (analyte, current, flag, unit, range, prior).
    # Total Cholesterol's prior is 100 and LDL's current is 100: same digits,
    # different columns, and only the LDL one is a result.
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
    # Second panel, with its own header row. Glucose's range "70-100" is the
    # third "100" on the page; it is one printed token, not a value.
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


def intake_full(out: Path) -> None:
    """A filled new-patient intake form: demographics block, reason for visit,
    a medications table, allergies, family history, a signature line. Written
    with invented answers for the fictional patient; includes 'stopped
    lisinopril' so the intake-vs-chart discrepancy case has something to find.
    Also saved as a 150 dpi JPEG scan (no text layer).

    An intake form is anchored differently from a lab report: there is no
    result column, so anchor.py looks for each proposed phrase (a medication
    name, an allergy, a family-history condition, the chief concern) as a run
    of words inside one row. The truth file therefore records fields and the
    page they are on, not row text. The medications table is the one place
    the form looks like a lab table, which checks that the phrase search
    still works across drawn columns.
    """
    doc = fitz.open()
    p = doc.new_page(width=612, height=792)
    y = 50
    p.insert_text((50, y), "RIVERSIDE FAMILY MEDICINE  -  NEW PATIENT INTAKE FORM", fontsize=12); y += 14
    p.insert_text((50, y), "Fixture for evals; fictional patient, invented answers", fontsize=8); y += 22
    p.insert_text((50, y), f"Patient name: {PATIENT}          Date of birth: 01/01/1970          Sex: F", fontsize=10); y += 16
    p.insert_text((50, y), "Phone: (555) 010-2233          Today's date: 09/20/2026", fontsize=10); y += 24
    p.insert_text((50, y), "Reason for today's visit:", fontsize=10); y += 14
    p.insert_text((60, y), "chest tightness when climbing stairs for the past 2 weeks", fontsize=10); y += 24
    p.insert_text((50, y), "Current medications (name, dose, how often):", fontsize=10); y += 14
    # The lisinopril line says it was stopped: if the chart still lists it as
    # active, that is the discrepancy the intake-vs-chart case must surface.
    meds = [("metformin", "500 mg", "twice daily"), ("atorvastatin", "20 mg", "at bedtime"), ("lisinopril", "10 mg", "STOPPED in August, dizziness"), ("aspirin", "81 mg", "daily")]
    for x, h in [(60, "Medication"), (230, "Dose"), (330, "How often")]:
        p.insert_text((x, y), h, fontsize=9)
    y += 4; p.draw_line((60, y), (540, y)); y += 14
    for name, dose, freq in meds:
        for x, t in [(60, name), (230, dose), (330, freq)]:
            p.insert_text((x, y), t, fontsize=10)
        y += 16
    y += 10
    p.insert_text((50, y), "Allergies (substance and reaction):", fontsize=10); y += 14
    allergies = [("penicillin", "rash"), ("sulfa drugs", "hives")]
    for sub, rx in allergies:
        p.insert_text((60, y), f"{sub}  -  {rx}", fontsize=10); y += 16
    y += 10
    p.insert_text((50, y), "Family history:", fontsize=10); y += 14
    fam = [("father", "heart attack at 55"), ("mother", "type 2 diabetes"), ("sister", "breast cancer")]
    for rel, cond in fam:
        p.insert_text((60, y), f"{rel}: {cond}", fontsize=10); y += 16
    y += 20
    p.insert_text((50, y), "Patient signature: ______________________     Date: 09/20/2026", fontsize=10)
    doc.save(out / "intake-full.pdf", garbage=4, deflate=True)
    # The truth file mirrors the intake-form contract: demographics as a
    # block, then lists of medications, allergies and family history, each
    # entry tagged with the page it was printed on (all page 1 here).
    truth = {
        "doc_type": "intake_form", "form_date": "2026-09-20",
        "demographics": {"name": PATIENT, "dob": "01/01/1970", "sex": "F", "phone": "(555) 010-2233"},
        "chief_concern": "chest tightness when climbing stairs for the past 2 weeks",
        "medications": [{"name": n, "dose": d, "frequency": f, "page": 1} for n, d, f in meds],
        "allergies": [{"substance": s, "reaction": r, "page": 1} for s, r in allergies],
        "family_history": [{"relative": r, "condition": c, "page": 1} for r, c in fam],
    }
    (out / "intake-full.truth.json").write_text(json.dumps(truth, indent=2) + "\n")
    # Scanned twin, made the same way as lab-layout1-scan: a JPEG of the page
    # with no text layer, so the intake path is exercised through OCR too.
    scan = fitz.open()
    for pg in doc:
        pix = pg.get_pixmap(dpi=150)
        np_ = scan.new_page(width=612, height=792)
        np_.insert_image(np_.rect, stream=pix.tobytes("jpeg", jpg_quality=70), rotate=0)
    scan.save(out / "intake-full-scan.pdf", garbage=4, deflate=True)
    (out / "intake-full-scan.truth.json").write_text(json.dumps(truth, indent=2) + "\n")


def malformed(out: Path) -> None:
    """Inputs the ingestion path must refuse, one per failure reason:
    corrupt.pdf        a truncated file: the parser cannot open it (unreadable)
    lab-encrypted.pdf  password-protected (encrypted)
    lab-6page.pdf      six pages, one over the cap (too_many_pages)
    lab-blank-scan.pdf a scanned page with nothing on it (unreadable after OCR)

    Each is a document a clinic could really receive, and each must fail as
    a single document with a reason code, never as a whole run and never
    silently. The parenthesised names are the failure_reason values that
    parse.py raises and the extraction carries back to PHP; the eval cases
    expect exactly those codes. None of these has a truth file.
    """
    # A tiny but well-formed one-page report to derive the broken variants from.
    good = fitz.open()
    p = good.new_page(width=612, height=792)
    p.insert_text((50, 60), "SYNTHETIC LABS INC", fontsize=13)
    p.insert_text((50, 80), f"Patient: {PATIENT}  Collected: 2026-09-15", fontsize=9)
    p.insert_text((50, 110), "Glucose 92 mg/dL 70-99", fontsize=10)
    # Corrupt: keep only the first third of the bytes. The file starts like a
    # PDF but ends mid-structure, the way an interrupted upload does; the
    # parser cannot open it (parse_failed, reported to PHP as "unreadable").
    (out / "corrupt.pdf").write_bytes(good.tobytes()[: len(good.tobytes()) // 3])
    # Encrypted: the same page saved with a password. The parser can open the
    # file but not read it, and must say "encrypted" rather than "unreadable":
    # a different problem with a different fix (an unlocked copy is needed).
    (out / "lab-encrypted.pdf").write_bytes(good.tobytes(encryption=fitz.PDF_ENCRYPT_AES_256, user_pw="secret", owner_pw="secret"))
    # Six pages: one over the parser's cap of five (MAX_PAGES in parse.py).
    # The whole document is refused, not truncated, so no result can be
    # lost without the clinician knowing.
    long_doc = fitz.open()
    for i in range(6):
        pg = long_doc.new_page(width=612, height=792)
        pg.insert_text((50, 60), f"SYNTHETIC LABS INC page {i + 1} of 6", fontsize=11)
        pg.insert_text((50, 100), "Glucose 92 mg/dL 70-99", fontsize=10)
    long_doc.save(out / "lab-6page.pdf", garbage=4, deflate=True)
    # Blank scan: a picture of an empty page. There is no text layer, OCR
    # finds no words, and a document with no words on any page is
    # "unreadable" (a scanner fed a blank sheet, or a page scanned face down).
    blank = fitz.open()
    page = blank.new_page(width=612, height=792)
    pix = page.get_pixmap(dpi=100)
    scan = fitz.open()
    sp = scan.new_page(width=612, height=792)
    sp.insert_image(sp.rect, stream=pix.tobytes("jpeg", jpg_quality=60))
    scan.save(out / "lab-blank-scan.pdf", garbage=4, deflate=True)


def main() -> None:
    """Writes every fixture into the directory named on the command line
    (the current directory when none is given), creating it if needed. The
    tests do not go through here: they call the generator functions directly
    into a temporary directory, so the committed fixtures are never touched
    by a test run."""
    out = Path(sys.argv[1] if len(sys.argv) > 1 else ".")
    out.mkdir(parents=True, exist_ok=True)
    lab_layout1(out)
    lab_layout2(out)
    intake_full(out)
    malformed(out)
    print("wrote", sorted(p.name for p in out.glob("*.pdf")))


if __name__ == "__main__":
    main()
