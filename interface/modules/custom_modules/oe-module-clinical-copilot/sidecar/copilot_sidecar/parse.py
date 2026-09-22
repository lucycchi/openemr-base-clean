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
"""

from __future__ import annotations

import io
from dataclasses import dataclass, field

import fitz  # PyMuPDF

OCR_DPI = 200
MAX_PAGES = 5


class ParseError(Exception):
    def __init__(self, code: str) -> None:
        super().__init__(code)
        self.code = code


@dataclass(frozen=True)
class Word:
    page: int  # 1-based
    x0: float
    y0: float
    x1: float
    y1: float
    text: str
    conf: float  # 100 for text-layer words, tesseract confidence for OCR


@dataclass
class Row:
    page: int
    words: list[Word] = field(default_factory=list)

    @property
    def text(self) -> str:
        return " ".join(w.text for w in sorted(self.words, key=lambda w: w.x0))

    def bbox(self) -> tuple[float, float, float, float]:
        return (
            min(w.x0 for w in self.words),
            min(w.y0 for w in self.words),
            max(w.x1 for w in self.words),
            max(w.y1 for w in self.words),
        )


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
        """Row-per-line rendering with page markers; what the model reads."""
        out = []
        for p in self.pages:
            if pages is not None and p.number not in pages:
                continue
            out.append(f"=== page {p.number} ===")
            out.extend(r.text for r in p.rows)
        return "\n".join(out)

    @property
    def ocr_pages(self) -> int:
        return sum(1 for p in self.pages if p.ocr)


def parse_pdf(data: bytes) -> ParsedDocument:
    try:
        doc = fitz.open(stream=data, filetype="pdf")
    except Exception as exc:  # fitz raises generic errors on garbage
        raise ParseError("parse_failed") from exc
    if doc.needs_pass:
        raise ParseError("encrypted")
    if doc.page_count == 0:
        raise ParseError("unreadable")
    if doc.page_count > MAX_PAGES:
        raise ParseError("too_many_pages")
    pages = []
    for index, page in enumerate(doc, start=1):
        words = [
            Word(index, float(x0), float(y0), float(x1), float(y1), str(text), 100.0)
            for x0, y0, x1, y1, text, *_ in page.get_text("words")
            if str(text).strip()
        ]
        ocr = False
        if not words:
            words = _ocr_words(page, index)
            ocr = True
        pages.append(Page(index, float(page.rect.width), float(page.rect.height), ocr, words, group_rows(words)))
    if all(not p.words for p in pages):
        raise ParseError("unreadable")
    return ParsedDocument(pages)


def _ocr_words(page: fitz.Page, index: int) -> list[Word]:
    import pytesseract
    from PIL import Image

    pix = page.get_pixmap(dpi=OCR_DPI)
    img = Image.open(io.BytesIO(pix.tobytes("png")))
    scale = 72.0 / OCR_DPI  # pixels -> points
    tsv = pytesseract.image_to_data(img, output_type=pytesseract.Output.DICT, config="--psm 6")
    words = []
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
    median word height of each other, then order rows top to bottom."""
    if not words:
        return []
    heights = sorted(w.y1 - w.y0 for w in words)
    unit = max(heights[len(heights) // 2], 1.0) * tolerance
    rows: list[Row] = []
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
