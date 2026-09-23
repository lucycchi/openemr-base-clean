"""PDF parsing: words with bounding boxes, grouped into rows.

Text-layer pages use PyMuPDF's word boxes (exact, in PDF points). Pages
with no text layer are rasterized at OCR_DPI and read by tesseract, whose
pixel boxes are converted back to points with the rasterization scale, so
every word carries the same canonical box regardless of source. Rows are
formed by vertical overlap of word boxes rather than the parser's own
block/line ids, because table cells in text-layer PDFs are often separate
blocks while OCR lines are already rows; y-clustering treats both alike.

Chosen after the 2026-09-21 droplet spike (W2_ARCHITECTURE.md): Docling
needed more RAM than the droplet has beside OpenEMR; tesseract reads a
5-page scan in 12 s under 100 MB.

The pipeline:

  PDF bytes -> fitz.open (PyMuPDF) -> per page: words with boxes from the
      text layer, or rasterize + tesseract when there is none
      -> group_rows (cluster words by vertical position)
      -> ParsedDocument(pages); text_for_model renders rows as lines

In plain words: a PDF may carry its text as real characters (a "text
layer", as in a report exported from a lab system) or only as a picture
(a scan). For a picture, OCR (optical character recognition, tesseract
here) reads the characters back. Either way, every word ends up with a
bounding box: the rectangle it occupies on the page, in "points" (1/72 of
an inch) measured from the page's top-left corner. Those boxes are what
anchor.py uses to prove where a value was printed.
"""

from __future__ import annotations

import io
from dataclasses import dataclass, field

import fitz  # PyMuPDF

# Resolution used to turn a scanned page into an image for OCR: enough for
# small print, kept low to bound memory and time on the droplet.
OCR_DPI = 200
# Documents longer than this are refused ("too_many_pages") rather than
# processed slowly: OCR time and model cost grow with every page.
MAX_PAGES = 5


class ParseError(Exception):
    """A document that cannot be parsed, carrying a short code ("encrypted",
    "unreadable", "too_many_pages", or the generic "parse_failed") that
    extractor.py turns into a failure_reason."""
    def __init__(self, code: str) -> None:
        super().__init__(code)
        self.code = code


# One word on a page with its box. frozen=True makes instances immutable:
# once built, a word's box cannot be changed by accident.
@dataclass(frozen=True)
class Word:
    page: int  # 1-based
    x0: float
    y0: float
    x1: float
    y1: float
    text: str
    conf: float  # 100 for text-layer words, tesseract confidence for OCR


# A horizontal line of words. field(default_factory=list) gives every Row its
# own empty list rather than one list shared by all rows.
@dataclass
class Row:
    page: int
    words: list[Word] = field(default_factory=list)

    # `@property` makes row.text read like an attribute (no parentheses) but
    # compute its value each time: the words joined left to right.
    @property
    def text(self) -> str:
        return " ".join(w.text for w in sorted(self.words, key=lambda w: w.x0))

    def bbox(self) -> tuple[float, float, float, float]:
        """The rectangle enclosing every word in the row: (left, top, right, bottom)."""
        return (
            min(w.x0 for w in self.words),
            min(w.y0 for w in self.words),
            max(w.x1 for w in self.words),
            max(w.y1 for w in self.words),
        )


# One page: its size in points (needed to scale boxes in the viewer), whether
# OCR was used, and its words both flat and grouped into rows.
@dataclass
class Page:
    number: int
    width: float
    height: float
    ocr: bool
    words: list[Word]
    rows: list[Row]


@dataclass
class ParsedDocument:
    pages: list[Page]

    def text_for_model(self, pages: list[int] | None = None) -> str:
        """Row-per-line rendering with page markers; what the model reads.
        `pages` limits the rendering to those page numbers (the extractor
        asks for one page at a time); None renders every page."""
        out = []
        for p in self.pages:
            if pages is not None and p.number not in pages:
                continue
            out.append(f"=== page {p.number} ===")
            out.extend(r.text for r in p.rows)
        return "\n".join(out)

    @property
    def ocr_pages(self) -> int:
        """How many pages needed OCR; logged so scan-heavy documents stand out."""
        return sum(1 for p in self.pages if p.ocr)


def parse_pdf(data: bytes) -> ParsedDocument:
    """Bytes to a ParsedDocument, or a ParseError with a reason code."""
    # PyMuPDF opens from memory; anything that is not a PDF raises here.
    try:
        doc = fitz.open(stream=data, filetype="pdf")
    except Exception as exc:  # fitz raises generic errors on garbage
        raise ParseError("parse_failed") from exc
    # Three refusals in order: password-protected, empty, too long.
    if doc.needs_pass:
        raise ParseError("encrypted")
    if doc.page_count == 0:
        raise ParseError("unreadable")
    if doc.page_count > MAX_PAGES:
        raise ParseError("too_many_pages")
    pages = []
    # enumerate(..., start=1) numbers pages from 1, as people and the model do.
    for index, page in enumerate(doc, start=1):
        # get_text("words") yields one tuple per word: the four box edges, the
        # text, then block/line/word numbers, which `*_` collects and ignores.
        # Whitespace-only "words" are dropped.
        words = [
            Word(index, float(x0), float(y0), float(x1), float(y1), str(text), 100.0)
            for x0, y0, x1, y1, text, *_ in page.get_text("words")
            if str(text).strip()
        ]
        # No text layer on this page: fall back to OCR and remember that.
        ocr = False
        if not words:
            words = _ocr_words(page, index)
            ocr = True
        pages.append(Page(index, float(page.rect.width), float(page.rect.height), ocr, words, group_rows(words)))
    # A document where even OCR found nothing on every page has no content
    # to extract from.
    if all(not p.words for p in pages):
        raise ParseError("unreadable")
    return ParsedDocument(pages)


def _ocr_words(page: fitz.Page, index: int) -> list[Word]:
    """Rasterize one page and read it with tesseract, returning words whose
    boxes are already converted from pixels to points."""
    # Imported here so a text-layer-only deployment never loads them.
    import pytesseract
    from PIL import Image

    # Render the page to a bitmap at OCR_DPI, then hand it to tesseract as an
    # image. `--psm 6` tells tesseract to treat the page as one uniform block
    # of text, which suits printed forms and tables.
    pix = page.get_pixmap(dpi=OCR_DPI)
    img = Image.open(io.BytesIO(pix.tobytes("png")))
    scale = 72.0 / OCR_DPI  # pixels -> points
    tsv = pytesseract.image_to_data(img, output_type=pytesseract.Output.DICT, config="--psm 6")
    words = []
    # image_to_data returns one column per attribute (text, conf, left, top,
    # width, height, ...), each a list with one entry per detected item.
    # Items with a negative confidence are layout markers, not words.
    for i, text in enumerate(tsv["text"]):
        text = str(text).strip()
        conf = float(tsv["conf"][i])
        if not text or conf < 0:
            continue
        left, top, w, h = (float(tsv[k][i]) for k in ("left", "top", "width", "height"))
        words.append(Word(index, left * scale, top * scale, (left + w) * scale, (top + h) * scale, text, conf))
    return words


def group_rows(words: list[Word], tolerance: float = 0.5) -> list[Row]:
    """Cluster words whose vertical centres fall within `tolerance` x the
    median word height of each other, then order rows top to bottom.

    Why the median height: it adapts the "same line" threshold to the font
    size of the page, so a small-print lab table and a large-print form are
    both split correctly."""
    if not words:
        return []
    # The typical word height on this page (the median); half of it is how
    # far apart two words' centres may be and still count as one row.
    heights = sorted(w.y1 - w.y0 for w in words)
    unit = max(heights[len(heights) // 2], 1.0) * tolerance
    rows: list[Row] = []
    # Walk the words top to bottom (then left to right). Each word either
    # joins the row being built, if its centre is close enough to that row's
    # average centre, or starts a new row.
    for w in sorted(words, key=lambda w: ((w.y0 + w.y1) / 2, w.x0)):
        centre = (w.y0 + w.y1) / 2
        if rows:
            last = rows[-1]
            last_centre = sum((x.y0 + x.y1) / 2 for x in last.words) / len(last.words)
            if abs(centre - last_centre) <= unit:
                last.words.append(w)
                continue
        rows.append(Row(w.page, [w]))
    return rows
