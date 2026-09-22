# W2_ARCHITECTURE.md — Clinical Co-Pilot, Week 2 (multimodal evidence agent)

Status: in progress. Plan and phased TODO list:
[docs/designs/week2-multimodal-evidence-agent.md](docs/designs/week2-multimodal-evidence-agent.md).
Week 1 architecture (unchanged, extended here): [ARCHITECTURE.md](ARCHITECTURE.md).

## Stack decision: PyMuPDF + tesseract, not Docling (spike, 2026-09-21)

The first task of Week 2 was to measure the document-extraction stack on the
deployed droplet before writing any sidecar code, because every later phase
depends on it. Droplet: DigitalOcean, 2 vCPU, 3.9 GB RAM, no swap, 70 GB
disk; OpenEMR + MariaDB use about 1.1 GB, leaving about 2.8 GB.

Fixtures: a synthetic 5-page lab report with a text layer (20 analytes in a
Test / Result / Flag / Units / Reference / Previous table, synthetic name),
and the same report rasterized at 150 dpi as JPEG pages with no text layer
(a scan).

| | Docling (`ghcr.io/docling-project/docling-serve:latest`) | PyMuPDF + tesseract 5.5 (`python:3.12-slim`) |
|---|---|---|
| Image size, pull time | 15.2 GB, 4 min 24 s | about 200 MB |
| Idle RSS | 1.7 GB | 18 MB |
| Text-layer PDF, 5 pages | 74 s cold, 68 s warm | 6 ms (text layer read, no OCR) |
| Scanned PDF, 5 pages | OOM-killed at a 2.5 GB cap (11 s in); 82 s with a 3 GB cap | 11.8 s (2.4 s/page at 200 dpi, `--psm 6`), under 100 MB |
| Words recovered from the scan | 5 pages, 5 tables | 256 of 256 words, per-word confidence 86-96 |
| Row structure | table cells | tesseract `(block, par, line)` groups give the row directly: `LDL Cholesterol 36.5 H mg/dL <100 33.7` |

Docling fails both thresholds set in the plan (more than 10 s per page; needs
more RAM than is free beside OpenEMR). Tesseract meets them with room to
spare. Decision for the week: **PyMuPDF for text-layer pages and the
canonical bounding boxes, tesseract (`image_to_data`, TSV) for pages with no
text layer**, row anchoring from tesseract line groups on scans and from
PyMuPDF word boxes on text pages. Docling remains the documented alternative
for a droplet with 8 GB or a GPU; nothing in the contracts depends on the
parser, so swapping it later is confined to `sidecar/extract.py`.

Consequences carried into the plan:

- The 5-page cap and 60 s synchronous extraction budget in the design doc are
  safe on this droplet: worst case measured 11.8 s for OCR plus one model
  call, well under 30 s, so the job/poll form is not needed in v1.
- The sidecar image is small enough to build on the droplet itself (`build:`
  context in `docker/vps/docker-compose.yml`), no registry needed.
- Rasterization at 200 dpi is the anchoring coordinate space for scans;
  `anchor.py` converts tesseract pixel boxes to PDF points using the page's
  rasterization scale.

## Reranker

Decision (2026-09-21): **Cohere Rerank via API** (`rerank-v3.5`). No key
existed on the host or the droplet at the spike; a trial key is created by
the developer and set as `COHERE_API_KEY` in both `.env` files. Rationale:
the droplet has no RAM to spare for a local cross-encoder, and the PRD names
Cohere first. `bge-reranker-base` is the documented equivalent for an
offline deployment; it is not built this week.

(The remaining sections, document ingestion flow, worker graph, RAG design,
eval gate, risks and tradeoffs, are written in Phase 8 of the plan.)
