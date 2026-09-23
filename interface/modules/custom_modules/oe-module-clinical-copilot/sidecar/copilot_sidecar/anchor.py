"""Row-level anchoring: the model proposes values, this module proves them.

A lab result is anchored only when its analyte, value and unit are found
in the same row of the same page (design premise 2, revised after the
Codex review: finding "100" somewhere on the page proves nothing about
which analyte it belongs to). The collection date is anchored once per
document. Anything that cannot be anchored is kept, marked
anchored=false, and surfaces to the physician as unverified; it is never
silently dropped and never silently trusted.

Normalisation rules (design doc, "Anchoring rules"): case-folded, Unicode
NFKC, whitespace collapsed; numbers compared after stripping thousands
separators and trailing zeros; units through an alias table; analyte words
allow Levenshtein distance 1 (OCR tolerance), values must match exactly; a
value matching in more than one candidate row is unanchored.

In plain words: a "bbox" (bounding box) is the rectangle on the page where
some words sit, in PDF points from the top-left corner (see parse.py).
"Anchoring" a proposed value means searching the page the model says it
read the value from, finding the analyte name and the value together in
one row, and returning that row's rectangle as the proof. When no such row
exists, or more than one would fit, the value keeps its citation but with
anchored=false and no box; the PHP side shows it as "unverified", which
tells the clinician: the model claims this, the code could not confirm it
on the page, look at the original before acting on it. An unverified value
is a prompt to check, not a fact.

  proposal -> for each result:
                candidate rows = rows on the hinted page containing the
                                 analyte name (fuzzy, in order)
                -> value words in that row (numbers compared after
                   normalising; "7.80" and "7.8" are the same)
                -> unit words (alias table, then OCR-tolerant)
                -> column check: never a value under a "previous" header
                -> exactly one hit -> value bbox + row bbox
                   otherwise -> unanchored
           -> LOINC code lookup and unit-mismatch flag
           -> unextracted rows: result-like rows no result anchored to
           -> LabReport / IntakeForm with a Citation on every value
"""

from __future__ import annotations

import json
import re
import unicodedata
from datetime import date, datetime
from pathlib import Path

from .parse import Page, ParsedDocument, Row, Word
from .schemas import (
    BBox,
    Citation,
    IntakeAllergy,
    IntakeFamilyHistory,
    IntakeForm,
    IntakeFormProposal,
    IntakeMedication,
    CitedString,
    Demographics,
    LabReport,
    LabReportProposal,
    LabResult,
    UnextractedRow,
)

import os

# The analyte-name -> LOINC code table ships with the contracts directory.
LOINC_MAP_PATH = Path(os.environ.get("COPILOT_CONTRACTS_DIR") or Path(__file__).resolve().parents[2] / "contracts") / "loinc_map.json"

# Unit spellings as they appear on reports (lower-cased) -> the canonical
# spelling the contract uses. Both the proposed unit and the words on the
# page go through this table, so "MG/DL" on the page matches "mg/dL" in the
# proposal. Entries mapping to themselves ("%", "pg") mark units that are
# valid as printed.
UNIT_ALIASES = {
    "mg/dl": "mg/dL",
    "mmol/l": "mmol/L",
    "g/dl": "g/dL",
    "u/l": "U/L",
    "iu/l": "IU/L",
    "uiu/ml": "uIU/mL",
    "µiu/ml": "uIU/mL",
    "miu/l": "mIU/L",
    "ng/ml": "ng/mL",
    "pg/ml": "pg/mL",
    "ng/dl": "ng/dL",
    "10^3/ul": "10^3/uL",
    "10*3/ul": "10^3/uL",
    "k/ul": "10^3/uL",
    "x10^3/ul": "10^3/uL",
    "10^6/ul": "10^6/uL",
    "m/ul": "10^6/uL",
    "%": "%",
    "fl": "fL",
    "pg": "pg",
    "ml/min/1.73m2": "mL/min/1.73m2",
    "ml/min/1.73": "mL/min/1.73m2",
}


def norm(text: str) -> str:
    """Text normalisation used for every comparison in this module. NFKC
    folds look-alike Unicode characters to one form (a full-width digit to
    a plain one, the two ways of writing a micro sign to one); casefold is
    a thorough lower-casing; runs of whitespace collapse to one space."""
    text = unicodedata.normalize("NFKC", text).casefold()
    return re.sub(r"\s+", " ", text).strip()


def norm_number(text: str) -> str | None:
    """'7.80' -> '7.8', '1,234' -> '1234', '<5' -> '<5'; None when not numeric.

    Thousands separators go, then an optional comparison prefix (<, >, <=,
    >=) and a signed decimal number are recognised; trailing zeros after the
    point are dropped so a report's "7.80" equals the model's "7.8". Values
    still compare as text afterwards, never as floating-point numbers."""
    t = norm(text).replace(",", "")
    m = re.fullmatch(r"([<>]=?)?\s*(-?\d+(?:\.\d+)?)", t)
    if not m:
        return None
    prefix, num = m.group(1) or "", m.group(2)
    if "." in num:
        num = num.rstrip("0").rstrip(".")
    return prefix + num


def norm_unit(text: str | None) -> str | None:
    """The canonical spelling of a unit through the alias table; a unit the
    table does not know is returned as printed (trimmed, case kept)."""
    if text is None:
        return None
    t = norm(text)
    return UNIT_ALIASES.get(t, text.strip())


def levenshtein(a: str, b: str) -> int:
    """Edit distance: the fewest single-character insertions, deletions or
    substitutions that turn a into b. Distance 1 is the OCR tolerance used
    for analyte names (one misread character). Computed row by row, keeping
    only the previous row of the classic dynamic-programming table."""
    if a == b:
        return 0
    prev = list(range(len(b) + 1))
    for i, ca in enumerate(a, 1):
        cur = [i]
        for j, cb in enumerate(b, 1):
            cur.append(min(prev[j] + 1, cur[j - 1] + 1, prev[j - 1] + (ca != cb)))
        prev = cur
    return prev[-1]


# Characters OCR commonly confuses, folded to one form before comparing:
# l, i and | all become 1, o becomes 0. str.maketrans builds the
# character-by-character translation table that str.translate applies.
OCR_FOLD = str.maketrans({"l": "1", "i": "1", "|": "1", "o": "0"})


def words_match(a: str, b: str) -> bool:
    """Two words are the same if equal after normalisation, or equal after
    the OCR fold, or (for words longer than three characters) one edit
    apart. The length rule keeps short tokens exact: "na" must not match
    "ca" by a single edit."""
    a, b = norm(a), norm(b)
    if a == b or a.translate(OCR_FOLD) == b.translate(OCR_FOLD):
        return True
    return len(a) > 3 and len(b) > 3 and levenshtein(a, b) <= 1


def _bbox(page: Page, words: list[Word]) -> BBox:
    """The contract BBox enclosing the given words, with the page size the
    viewer needs to scale it."""
    return BBox(
        page=page.number,
        x0=min(w.x0 for w in words),
        y0=min(w.y0 for w in words),
        x1=max(w.x1 for w in words),
        y1=max(w.y1 for w in words),
        page_w=page.width,
        page_h=page.height,
    )


# ---- LOINC map -------------------------------------------------------------
# LOINC is the standard vocabulary for lab tests. The map turns a printed
# analyte name into its code (and the unit usually reported with it), so PHP
# can line the result up with the chart's own lab history. Missing map: no
# codes, no failure.


def load_loinc_map() -> dict[str, dict[str, str]]:
    """Reads loinc_map.json, keyed by the normalised analyte name. Called
    per extraction (it is small) and by /ready as the loinc_map check."""
    if not LOINC_MAP_PATH.exists():
        return {}
    raw = json.loads(LOINC_MAP_PATH.read_text())
    return {norm(k): v for k, v in raw.get("analytes", {}).items()}


def lookup_loinc(loinc_map: dict[str, dict[str, str]], analyte: str) -> dict[str, str] | None:
    """Exact match on the normalised name first, then any map key one edit
    away (the OCR tolerance); None when nothing matches."""
    key = norm(analyte)
    if key in loinc_map:
        return loinc_map[key]
    for k, v in loinc_map.items():
        if levenshtein(k, key) <= 1:
            return v
    return None


# ---- Row search for lab results --------------------------------------------
# Lab reports are tables. The words below are the column headings reports
# print; recognising a header row tells the code which column holds the
# current result and which columns hold earlier results that must never be
# read as the current one.

RESULT_HEADERS = {"result", "results", "current", "value", "values", "finding"}
PRIOR_HEADERS = {"previous", "prior", "last", "history", "baseline", "earlier"}
OTHER_HEADERS = {"test", "analyte", "name", "flag", "f", "units", "unit", "reference", "range", "ref", "interval"}


def column_bounds(page: Page) -> tuple[tuple[float, float] | None, list[tuple[float, float]]]:
    """(result column x-span, prior column x-spans) from the page's header
    rows, or (None, []) when no header row is recognised. A header row is a
    row whose words are mostly header vocabulary and that contains a result
    header; each header word owns the span from its x0 to the next header's x0.
    Several header rows on a page (two panels) each apply to the rows below
    them until the next header row; this function returns the first, and
    anchor_lab_result re-evaluates per candidate row through header_for()."""
    return header_for(page, None)


def header_for(page: Page, row: Row | None) -> tuple[tuple[float, float] | None, list[tuple[float, float]]]:
    """Header spans in force for `row` (the nearest header row above it)."""
    # Rows are ordered top to bottom. Walk them, stopping at the first row
    # that starts below the target; the last qualifying header row seen is
    # the nearest one above. With row=None the whole page is scanned and the
    # last header row on the page wins.
    best = None
    for r in page.rows:
        if row is not None and r.words[0].y0 > min(w.y0 for w in row.words):
            break
        words = sorted(r.words, key=lambda w: w.x0)
        vocab = [norm(w.text) for w in words]
        # A header row must name a result column, and at least half of its
        # words (minimum two) must be header vocabulary, so a data row that
        # happens to contain the word "result" does not qualify.
        if not any(v in RESULT_HEADERS for v in vocab):
            continue
        known = sum(1 for v in vocab if v in RESULT_HEADERS | PRIOR_HEADERS | OTHER_HEADERS)
        if known < max(2, len(vocab) // 2):
            continue
        best = words
    if best is None:
        return None, []
    # Each header word owns the horizontal span from its own left edge to the
    # next header word's left edge (the last one runs to the page edge). The
    # first result header is the result column; every prior header is a
    # column to avoid.
    result, priors = None, []
    for i, w in enumerate(best):
        span = (w.x0, best[i + 1].x0 if i + 1 < len(best) else page.width)
        v = norm(w.text)
        if v in RESULT_HEADERS and result is None:
            result = span
        elif v in PRIOR_HEADERS:
            priors.append(span)
    return result, priors


def _in_span(w: Word, span: tuple[float, float]) -> bool:
    """Whether a word's horizontal centre falls inside a column span, with a
    two-point allowance on the left for values printed flush with the header."""
    centre = (w.x0 + w.x1) / 2
    return span[0] - 2 <= centre < span[1]


def _row_has_analyte(row: Row, analyte: str) -> bool:
    """Every word of the analyte name appears in the row (fuzzy), in order."""
    # Walk the row's words left to right, consuming one for each analyte
    # word in turn; other words may sit in between ("Glucose, fasting" still
    # matches "glucose fasting"). Running out of row words means no match.
    tokens = [t for t in norm(analyte).split(" ") if t]
    row_words = [norm(w.text) for w in sorted(row.words, key=lambda w: w.x0)]
    pos = 0
    for t in tokens:
        while pos < len(row_words) and not words_match(row_words[pos], t):
            pos += 1
        if pos == len(row_words):
            return False
        pos += 1
    return True


def _value_words(row: Row, value: str) -> list[Word]:
    """Words in the row whose numeric normalisation equals the proposed value."""
    # A non-numeric value ("Positive", "Negative") must match as exact text.
    target = norm_number(value)
    if target is None:
        return [w for w in row.words if norm(w.text) == norm(value)]
    return [w for w in row.words if norm_number(w.text) == target]


def _unit_words(row: Row, unit: str | None) -> list[Word]:
    """Exact (alias-normalised) unit matches; failing that, an OCR-tolerant
    match: same first character, Levenshtein <= 2 after the confusable fold,
    units of five or more characters only. The first-character rule keeps
    mmol/L and umol/L apart; the length rule keeps % and pg exact."""
    if unit is None:
        return []
    want = norm_unit(unit)
    exact = [w for w in row.words if norm_unit(w.text) == want]
    if exact or want is None or len(want) < 5:
        return exact
    wf = norm(want).translate(OCR_FOLD)
    out = []
    for w in row.words:
        t = norm(w.text).translate(OCR_FOLD)
        if t and t[0] == wf[0] and abs(len(t) - len(wf)) <= 2 and levenshtein(t, wf) <= 2:
            out.append(w)
    return out


def anchor_lab_result(parsed: ParsedDocument, analyte: str, value: str, unit: str | None, page_hint: int | None) -> tuple[BBox | None, BBox | None, str | None]:
    """Returns (value bbox, row bbox, page-as-string) or (None, None, None).

    Candidate rows: rows containing the analyte name. Anchored only when
    exactly one candidate row also contains the value (and the unit, when
    one was proposed); a value that appears in the analyte's row more than
    once (result column vs previous column) is anchored to the first
    occurrence left of the unit when a unit exists, else unanchored.
    """
    # Only the page the model named is searched, so a value the model read
    # from the wrong page comes back unverified. The `or parsed.pages` fallback
    # covers a hint that names a page the document does not have.
    pages = [p for p in parsed.pages if page_hint is None or p.number == page_hint] or parsed.pages
    hits: list[tuple[Page, Row, Word]] = []
    for page in pages:
        for row in page.rows:
            # The row must contain the analyte name, the value, and (when one
            # was proposed) the unit. Any of the three missing: not this row.
            if not _row_has_analyte(row, analyte):
                continue
            values = sorted(_value_words(row, value), key=lambda w: w.x0)
            if not values:
                continue
            units = sorted(_unit_words(row, unit), key=lambda w: w.x0)
            if unit is not None and not units:
                continue
            result_span, prior_spans = header_for(page, row)
            # Never accept a value that sits in a previous/prior column.
            values = [v for v in values if not any(_in_span(v, sp) for sp in prior_spans)]
            # With a recognised header, the value must sit in the result column.
            if result_span is not None:
                values = [v for v in values if _in_span(v, result_span)]
            elif len(values) > 1 and units:
                # No header on the page: the result is the value immediately left of the unit.
                left = [v for v in values if v.x1 <= units[0].x0 + 1]
                values = left[-1:] if left else values
            if len(values) == 1:
                hits.append((page, row, values[0]))
                continue
            if values:
                return None, None, None  # ambiguous inside the row
    # Exactly one row may fit. Two rows that both contain the analyte and
    # the value (the same test in two panels, say) are refused rather than
    # guessed: an unverified value is safer than a wrongly placed box.
    if len(hits) != 1:
        return None, None, None
    page, row, word = hits[0]
    return _bbox(page, [word]), _bbox(page, row.words), str(page.number)


def unextracted_rows(parsed: ParsedDocument, anchored_rows: set[tuple[int, float]]) -> list[UnextractedRow]:
    """Rows that look like results but were not anchored by any extracted
    result. A candidate row: below a recognised header row, has a numeric
    word inside the result column and a unit-like word, and is not itself
    a header. Keyed by (page, row y0) against the rows results anchored to.

    This is the deterministic omission detector: it never asks the model
    what it missed, it looks at the table. extractor.py feeds these rows
    to the retry call, and whatever is still unanchored afterwards is
    shown to the clinician as an unextracted row."""
    out = []
    for page in parsed.pages:
        for row in page.rows:
            result_span, _ = header_for(page, row)
            if result_span is None:
                continue
            words = sorted(row.words, key=lambda w: w.x0)
            vocab = {norm(w.text) for w in words}
            if vocab & RESULT_HEADERS:
                continue  # the header row itself
            has_value = any(norm_number(w.text) is not None and _in_span(w, result_span) for w in words)
            has_unit = any(norm_unit(w.text) in UNIT_ALIASES.values() or _looks_like_unit(w.text) for w in words)
            if not (has_value and has_unit):
                continue
            # The row's identity is (page, top edge rounded to a tenth of a
            # point); build_lab_report records anchored rows the same way so
            # the two sets line up exactly.
            key = (page.number, round(min(w.y0 for w in words), 1))
            if key in anchored_rows:
                continue
            out.append(UnextractedRow(page=page.number, text=row.text, row_bbox=_bbox(page, words)))
    return out


def _looks_like_unit(text: str) -> bool:
    """A unit the alias table does not know: short, contains a slash and at
    least one letter, like "mcg/dL"."""
    t = norm(text)
    return "/" in t and any(ch.isalpha() for ch in t) and len(t) <= 12


# ---- Text anchoring for dates and intake fields ----------------------------

# The date spellings reports and forms use, in strptime notation (%Y four-
# digit year, %m month number, %d day, %B full month name, %b short name).
DATE_FORMATS = ["%Y-%m-%d", "%m/%d/%Y", "%m/%d/%y", "%d/%m/%Y", "%B %d, %Y", "%b %d, %Y", "%d %b %Y", "%d %B %Y", "%Y/%m/%d", "%m-%d-%Y"]


def parse_date(text: str | None) -> date | None:
    """The printed date as a real date, trying each known spelling in turn;
    None when none fits. Month names are title-cased first ("sep" ->
    "Sep") because strptime's month names are case-sensitive, and a comma
    is given a following space so "Sep 21,2026" is still recognised."""
    if not text:
        return None
    t = norm(text).replace(",", ", ").replace("  ", " ")
    for fmt in DATE_FORMATS:
        try:
            return datetime.strptime(t.title() if "%b" in fmt or "%B" in fmt else t, fmt).date()
        except ValueError:
            continue
    return None


def date_variants(d: date) -> set[str]:
    """Every spelling of one date the page might use, normalised, plus the
    form without zero padding ("9/3/2026"), so a date the model wrote as
    2026-09-03 can still be found printed as 09/03/2026."""
    return {norm(d.strftime(f)) for f in DATE_FORMATS} | {norm(d.strftime("%-m/%-d/%Y"))}


def anchor_text(parsed: ParsedDocument, text: str, page_hint: int | None = None, try_dates: bool = True) -> tuple[BBox | None, BBox | None, str | None]:
    """Anchor a phrase: the sequence of its words must appear in one row.

    Used for dates, names, medications, allergies and the chief concern.
    Unlike lab values, a phrase found in several rows anchors to the first
    row found (top of the earliest searched page); there is no column
    logic because forms are not tables."""
    tokens = [t for t in norm(text).split(" ") if t]
    if not tokens:
        return None, None, None
    pages = [p for p in parsed.pages if page_hint is None or p.number == page_hint] or parsed.pages
    hits: list[tuple[Page, Row, list[Word]]] = []
    for page in pages:
        for row in page.rows:
            # Slide a window the width of the phrase along the row's words and
            # accept the first position where every word matches (fuzzily).
            ws = sorted(row.words, key=lambda w: w.x0)
            for start in range(len(ws) - len(tokens) + 1):
                if all(words_match(ws[start + i].text, tok) for i, tok in enumerate(tokens)):
                    hits.append((page, row, ws[start : start + len(tokens)]))
                    break
    if not hits:
        # Dates: try every printed form (once; no recursion past this level).
        # A phrase that parses as a date is re-searched under each other
        # spelling of that date; try_dates=False on the inner call stops the
        # search from repeating itself.
        d = parse_date(text) if try_dates else None
        if d is not None:
            for variant in date_variants(d):
                if variant != norm(text):
                    r = anchor_text(parsed, variant, page_hint, try_dates=False)
                    if r[0] is not None:
                        return r
        return None, None, None
    page, row, words = hits[0]
    return _bbox(page, words), _bbox(page, row.words), str(page.number)


# ---- Build contract objects from proposals --------------------------------


def _citation(document_id: int, pointer: str, value: str, bbox: BBox | None, row_bbox: BBox | None, page: str | None) -> Citation:
    """A document Citation for one value. anchored is derived from the box:
    a value with no box is unverified, by construction."""
    return Citation(
        source_type="document",
        source_id=str(document_id),
        page_or_section=page or "",
        field_or_chunk_id=pointer,
        quote_or_value=value,
        bbox=bbox,
        row_bbox=row_bbox,
        anchored=bbox is not None,
    )


def build_lab_report(document_id: int, parsed: ParsedDocument, proposal: LabReportProposal, loinc_map: dict[str, dict[str, str]]) -> tuple[LabReport | None, str | None]:
    """Returns (report, failure_reason). A report needs an anchorable
    collection date and at least one result; the results themselves may be
    unanchored (they are then unverified, not absent).

    More precisely: the collection date must parse as a date (a report
    without one is refused as schema_mismatch); its citation may still be
    unanchored if the printed form was not found."""
    collection = parse_date(proposal.collection_date)
    if collection is None:
        return None, "schema_mismatch"
    cb, crb, cp = anchor_text(parsed, proposal.collection_date or "")
    results = []
    # The rows results anchored to, keyed the same way unextracted_rows keys
    # its candidates, so the two can be compared.
    anchored_rows: set[tuple[int, float]] = set()
    for i, r in enumerate(proposal.results):
        # A result with a blank name or value is dropped here; the contract
        # requires both non-empty.
        if not r.analyte.strip() or not r.value.strip():
            continue
        bbox, row_bbox, page = anchor_lab_result(parsed, r.analyte, r.value, r.unit, r.page)
        if row_bbox is not None:
            anchored_rows.add((row_bbox.page, round(row_bbox.y0, 1)))
        # Code and expected unit from the LOINC map; the mismatch flag is set
        # when the printed unit differs from the map's unit for this analyte.
        mapped = lookup_loinc(loinc_map, r.analyte)
        unit = norm_unit(r.unit)
        mismatch = bool(mapped and unit and mapped.get("unit") and norm(mapped["unit"]) != norm(unit))
        results.append(
            LabResult(
                analyte=r.analyte.strip(),
                loinc=mapped["loinc"] if mapped else None,
                value=r.value.strip(),
                unit=unit,
                reference_range=r.reference_range.strip() if r.reference_range else None,
                abnormal_flag=r.abnormal_flag,
                unit_mismatch=mismatch,
                # The pointer names the field inside the report, e.g. "/results/3/value".
                citation=_citation(document_id, f"/results/{i}/value", r.value.strip(), bbox, row_bbox, page),
            )
        )
    if not results:
        return None, "schema_mismatch"
    # The report date is optional: cited when present and parseable.
    reported = parse_date(proposal.reported_date)
    rep_cit = None
    if reported is not None:
        rb, rrb, rp = anchor_text(parsed, proposal.reported_date or "")
        rep_cit = _citation(document_id, "/reported_date", proposal.reported_date or "", rb, rrb, rp)
    # Patient name and lab name are carried as proposed, without citations;
    # PHP compares the name to the chart.
    return (
        LabReport(
            patient_name_on_report=proposal.patient_name_on_report,
            collection_date=collection,
            collection_date_citation=_citation(document_id, "/collection_date", proposal.collection_date or "", cb, crb, cp),
            reported_date=reported,
            reported_date_citation=rep_cit,
            lab_name=proposal.lab_name,
            results=results,
            unextracted=unextracted_rows(parsed, anchored_rows),
        ),
        None,
    )


def _cited(document_id: int, pointer: str, parsed: ParsedDocument, value: str | None, page: int | None = None) -> CitedString | None:
    """A CitedString for one intake phrase, or None when the model proposed
    nothing (a blank form field stays absent rather than unverified)."""
    if not value or not value.strip():
        return None
    b, rb, p = anchor_text(parsed, value, page)
    return CitedString(value=value.strip(), citation=_citation(document_id, pointer, value.strip(), b, rb, p))


def build_intake_form(document_id: int, parsed: ParsedDocument, proposal: IntakeFormProposal) -> tuple[IntakeForm | None, str | None]:
    """Builds the IntakeForm. Nothing is required, so this never returns a
    failure: a form with blank fields is a valid (mostly empty) extraction.
    One phrase per entry is anchored: the medication name, the allergy
    substance, the family-history condition. Dose, frequency, reaction and
    relative are carried as proposed."""
    form_date = parse_date(proposal.form_date)
    fd_cit = None
    if form_date is not None:
        b, rb, p = anchor_text(parsed, proposal.form_date or "")
        fd_cit = _citation(document_id, "/form_date", proposal.form_date or "", b, rb, p)
    meds, allergies, fam = [], [], []
    # Each list entry is searched on the page the model named for it; blank
    # names are dropped, as the contract requires them non-empty.
    for i, m in enumerate(proposal.medications):
        if not m.name.strip():
            continue
        b, rb, p = anchor_text(parsed, m.name, m.page)
        meds.append(IntakeMedication(name=m.name.strip(), dose=m.dose, frequency=m.frequency, citation=_citation(document_id, f"/medications/{i}/name", m.name.strip(), b, rb, p)))
    for i, a in enumerate(proposal.allergies):
        if not a.substance.strip():
            continue
        b, rb, p = anchor_text(parsed, a.substance, a.page)
        allergies.append(IntakeAllergy(substance=a.substance.strip(), reaction=a.reaction, citation=_citation(document_id, f"/allergies/{i}/substance", a.substance.strip(), b, rb, p)))
    for i, f in enumerate(proposal.family_history):
        if not f.relative.strip() or not f.condition.strip():
            continue
        b, rb, p = anchor_text(parsed, f.condition, f.page)
        fam.append(IntakeFamilyHistory(relative=f.relative.strip(), condition=f.condition.strip(), citation=_citation(document_id, f"/family_history/{i}/condition", f.condition.strip(), b, rb, p)))
    # Demographics carry no page hint from the model and are searched on
    # every page.
    return (
        IntakeForm(
            form_date=form_date,
            form_date_citation=fd_cit,
            demographics=Demographics(
                name=_cited(document_id, "/demographics/name", parsed, proposal.name),
                dob=_cited(document_id, "/demographics/dob", parsed, proposal.dob),
                sex=_cited(document_id, "/demographics/sex", parsed, proposal.sex),
                phone=_cited(document_id, "/demographics/phone", parsed, proposal.phone),
            ),
            chief_concern=_cited(document_id, "/chief_concern", parsed, proposal.chief_concern),
            medications=meds,
            allergies=allergies,
            family_history=fam,
        ),
        None,
    )


def confidence(citations: list[Citation]) -> float:
    """The share of citations that anchored, 0.0 to 1.0, three decimals.
    Not a model's self-reported confidence: it is a count of what the code
    could prove on the page."""
    if not citations:
        return 0.0
    return round(sum(1 for c in citations if c.anchored) / len(citations), 3)


def citations_of(extraction: LabReport | IntakeForm) -> list[Citation]:
    """Every Citation anywhere inside an extraction, in document order."""
    out: list[Citation] = []

    # walk visits the object tree: a Citation is collected; any other object
    # with attributes (a Pydantic model; vars() is its attribute dictionary)
    # has each attribute visited; a list has each item visited. Strings are
    # excluded because they have attributes too but contain no citations.
    def walk(node: object) -> None:
        if isinstance(node, Citation):
            out.append(node)
        elif hasattr(node, "__dict__") and not isinstance(node, (str, bytes)):
            for v in vars(node).values():
                walk(v)
        elif isinstance(node, list):
            for v in node:
                walk(v)

    walk(extraction)
    return out
