/**
 * Click-to-source viewer (week 2): renders one page of an uploaded PDF with
 * pdf.js and draws the citation's bounding box over it. Coordinates arrive
 * as PDF points with a top-left origin (contracts/citation.schema.json);
 * pdf.js renders at scale s, so a box maps to canvas pixels by s alone.
 * Unverified citations (anchored=false) have no box; the viewer opens the
 * page and says so, it never draws a guessed rectangle.
 *
 * Loaded as an ES module (pdf.js ships ESM); exposes window.copilotSourceViewer
 * for panel.js, which is a classic script.
 *
 * The clinician's path through this file:
 *
 *   click "source p.3" in the panel -> open(docUrl, citation, title)
 *     -> ensureRoot()  build the overlay dialog once (title bar, note, page canvas)
 *     -> load(url)     fetch the PDF bytes through OpenEMR, parse with pdf.js
 *     -> go(page)      render that page onto the <canvas>, then
 *        -> drawBoxes  place the row box and the value box over the canvas
 *
 * Why a viewer at all: an extracted lab value is only trustworthy if a human
 * can see where it came from. The box is drawn from coordinates the server's
 * anchor step recorded when it found the value's text on the page; this file
 * only converts those coordinates to screen pixels. It never searches the
 * page itself, so it cannot "find" a value that was not really there.
 *
 * Two pieces of the page are stacked: a <canvas> (a bitmap pdf.js paints the
 * page onto) and, exactly on top of it, an empty <div> "overlay" where the
 * boxes are placed as absolutely positioned <div>s. Drawing boxes as HTML
 * rather than onto the canvas keeps the page image untouched.
 *
 * `async`/`await` appear throughout: `await x` means "wait here for x to
 * finish, without freezing the page", and a function marked `async` returns a
 * Promise (a result that arrives later) instead of a plain value.
 */
// `import` pulls in pdf.js (Mozilla's PDF renderer) from the vendored copy next to this file.
import * as pdfjsLib from './vendor/pdfjs/pdf.min.mjs';

// pdf.js does the heavy parsing in a "worker", a background thread with its own
// script file. `import.meta.url` is this file's own address, so the worker's URL
// is resolved relative to it and keeps working wherever the module is installed.
const workerUrl = new URL('./vendor/pdfjs/pdf.worker.min.mjs', import.meta.url).href;
pdfjsLib.GlobalWorkerOptions.workerSrc = workerUrl;

// The viewer's memory between clicks: the parsed PDF and the URL it came from
// (so re-opening the same document does not re-download it), the page being
// shown, the zoom (1.4 = 140% of the PDF's natural size), and the citation
// whose boxes to draw (null = none).
const state = { pdf: null, url: null, page: 1, scale: 1.4, citation: null, rotation: 0 };
// The dialog's outermost element, created on first use by ensureRoot().
let root = null;

// Same tiny DOM builder as in panel.js: every string becomes text, never markup.
function el(tag, attrs, children) {
    const node = document.createElement(tag);
    Object.entries(attrs || {}).forEach(([k, v]) => {
        if (k === 'text') node.textContent = v; else node.setAttribute(k, v);
    });
    (children || []).forEach(c => node.appendChild(typeof c === 'string' ? document.createTextNode(c) : c));
    return node;
}

/**
 * Build the viewer dialog the first time it is needed and attach it to the end
 * of the page, hidden. Later calls return the same element. Layout, outer to inner:
 *   #copilot-viewer (full-page backdrop; clicking it closes)
 *     .copilot-viewer-box (the dialog)
 *       .copilot-viewer-bar   title, previous / "n / N" / next, Close
 *       #copilot-viewer-note  one line saying whether a highlight is shown and why
 *       .copilot-viewer-stage the <canvas> with the overlay <div> on top
 * The `hidden: ''` attribute is the HTML `hidden` flag: present means not shown.
 */
function ensureRoot() {
    if (root) return root;
    root = el('div', { id: 'copilot-viewer', class: 'copilot-viewer', hidden: '' }, [
        el('div', { class: 'copilot-viewer-box' }, [
            el('div', { class: 'copilot-viewer-bar' }, [
                el('span', { id: 'copilot-viewer-title', text: 'Source document' }),
                el('span', { class: 'copilot-viewer-spacer' }),
                el('button', { type: 'button', class: 'btn btn-sm btn-light', id: 'copilot-viewer-prev', text: '‹' }),
                el('span', { id: 'copilot-viewer-page', class: 'copilot-viewer-pageno', text: '' }),
                el('button', { type: 'button', class: 'btn btn-sm btn-light', id: 'copilot-viewer-next', text: '›' }),
                el('button', { type: 'button', class: 'btn btn-sm btn-light', id: 'copilot-viewer-close', text: 'Close' }),
            ]),
            el('div', { id: 'copilot-viewer-note', class: 'copilot-viewer-note', text: '' }),
            el('div', { class: 'copilot-viewer-stage' }, [
                el('canvas', { id: 'copilot-viewer-canvas' }),
                el('div', { id: 'copilot-viewer-overlay', class: 'copilot-viewer-overlay' }),
            ]),
        ]),
    ]);
    document.body.appendChild(root);
    // Four ways to leave or move: the Close button, a click on the dark backdrop
    // (e.target === root means the click landed outside the dialog box), the
    // Escape key, and the previous/next page buttons.
    root.querySelector('#copilot-viewer-close').addEventListener('click', close);
    root.addEventListener('click', e => { if (e.target === root) close(); });
    root.querySelector('#copilot-viewer-prev').addEventListener('click', () => go(state.page - 1));
    root.querySelector('#copilot-viewer-next').addEventListener('click', () => go(state.page + 1));
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && !root.hidden) close(); });
    return root;
}

// Hide the dialog. The parsed PDF stays in memory so re-opening is instant.
function close() {
    if (root) root.hidden = true;
}

/**
 * Fetch and parse the PDF, unless the one already loaded came from the same URL.
 * `withCredentials: true` sends the OpenEMR login cookie with the request, which
 * the document retrieval URL needs to apply its access checks. pdf.js returns a
 * "loading task"; awaiting its `.promise` yields the parsed document object.
 */
async function load(url) {
    if (state.pdf && state.url === url) return state.pdf;
    const task = pdfjsLib.getDocument({ url, withCredentials: true });
    state.pdf = await task.promise;
    state.url = url;
    return state.pdf;
}

/**
 * Show page `pageNo` of the loaded PDF and then draw the citation's boxes on it.
 * The page number is clamped to 1..numPages, so the previous/next buttons simply
 * stop at the ends. Steps: get the page, ask for a viewport at our zoom (the
 * viewport is pdf.js's description of the page at that size: width, height and
 * the transform from PDF units to pixels), size the canvas and overlay to match,
 * clear old boxes, update the "n / N" label, paint the page, then draw boxes.
 */
async function go(pageNo) {
    if (!state.pdf) return;
    const n = Math.max(1, Math.min(state.pdf.numPages, pageNo));
    state.page = n;
    const page = await state.pdf.getPage(n);
    const viewport = page.getViewport({ scale: state.scale });
    const canvas = root.querySelector('#copilot-viewer-canvas');
    const overlay = root.querySelector('#copilot-viewer-overlay');
    // The canvas and the overlay must be exactly the same size, or the boxes drift.
    canvas.width = viewport.width;
    canvas.height = viewport.height;
    overlay.style.width = viewport.width + 'px';
    overlay.style.height = viewport.height + 'px';
    overlay.replaceChildren();
    root.querySelector('#copilot-viewer-page').textContent = n + ' / ' + state.pdf.numPages;
    // Paint the page onto the canvas; wait for the paint to finish before overlaying boxes.
    await page.render({ canvasContext: canvas.getContext('2d'), viewport }).promise;
    drawBoxes(overlay, viewport, n);
}

/**
 * Place the citation's boxes over the rendered page. A citation may carry two:
 * `row_bbox` (the whole table row, drawn as a light blue outline) and `bbox`
 * (the value's own cell, drawn in red). Boxes for other pages are skipped, so
 * paging away and back redraws them correctly. With no citation (the "open"
 * link, or a mismatch flag) nothing is drawn.
 *
 * The coordinate conversion, for a reader who has not met it: the server
 * measured the box in PDF points (1/72 inch) from the top-left corner of a
 * page that was page_w by page_h points. We rendered that page at some pixel
 * size, viewport.width by viewport.height. The ratio viewport.width / page_w is
 * therefore "pixels per point" horizontally (sx), and likewise sy vertically;
 * multiplying each coordinate by it lands the box on the same spot of the image.
 * Because the ratio is recomputed from the actual render, the box stays right
 * at any zoom. The extra 2 px on each side is padding so the outline does not
 * sit on top of the text it is pointing at.
 */
function drawBoxes(overlay, viewport, pageNo) {
    const c = state.citation;
    if (!c) return;
    // The stored page size is the unrotated PDF size; scale by the rendered
    // width so the box survives any viewer scale or a differently sized page.
    const place = (box, cls) => {
        if (!box || box.page !== pageNo) return;
        const sx = viewport.width / box.page_w;
        const sy = viewport.height / box.page_h;
        const div = el('div', { class: 'copilot-viewer-mark ' + cls });
        div.style.left = (box.x0 * sx - 2) + 'px';
        div.style.top = (box.y0 * sy - 2) + 'px';
        div.style.width = ((box.x1 - box.x0) * sx + 4) + 'px';
        div.style.height = ((box.y1 - box.y0) * sy + 4) + 'px';
        overlay.appendChild(div);
        // Scroll the dialog so the value box (the one the clinician clicked for) is
        // in the middle of the view. Only the value box triggers the scroll.
        if (cls === 'copilot-viewer-mark-value') {
            div.scrollIntoView({ block: 'center', inline: 'nearest' });
        }
    };
    place(c.row_bbox, 'copilot-viewer-mark-row');
    place(c.bbox, 'copilot-viewer-mark-value');
}

/**
 * Open the viewer on a citation. docUrl serves the PDF bytes (OpenEMR's
 * ACL-checked document retrieval); citation is the fact's citation object.
 *
 * The note line above the page is set before anything loads, from the
 * citation alone, so the clinician reads the verification status even if the
 * PDF is slow. Anchored: "Highlighted: the row and cell this value was read
 * from." Not anchored (or no citation): the red warning that no highlight is
 * shown and the document must be checked by eye. This is the browser half of
 * the unverified-value rule: an unplaced value is never dressed up as a
 * placed one.
 *
 * The page to open is the box's page when there is a box, else the citation's
 * page_or_section (a page number stored as text; parseInt reads the number and
 * `|| 1` falls back to page 1 if it is not a number), else page 1.
 *
 * `try { ... } catch { ... }` means: attempt the block, and if any step throws
 * an error (download refused, corrupt PDF, render failure) run the catch block
 * instead of stopping silently. Here that replaces the note with a plain
 * "could not be displayed" message; the dialog stays open so Close still works.
 */
async function open(docUrl, citation, title) {
    ensureRoot();
    state.citation = citation;
    root.hidden = false;
    root.querySelector('#copilot-viewer-title').textContent = title || 'Source document';
    const note = root.querySelector('#copilot-viewer-note');
    note.textContent = citation && citation.anchored
        ? 'Highlighted: the row and cell this value was read from.'
        : 'This value could not be verified against the page; no highlight is shown. Check the document yourself.';
    note.className = 'copilot-viewer-note' + (citation && citation.anchored ? '' : ' copilot-viewer-note-warn');
    try {
        await load(docUrl);
        const target = citation && citation.bbox ? citation.bbox.page : (citation && citation.page_or_section ? parseInt(citation.page_or_section, 10) || 1 : 1);
        await go(target);
    } catch {
        note.textContent = 'The document could not be displayed.';
        note.className = 'copilot-viewer-note copilot-viewer-note-warn';
    }
}

// An ES module's names are private by default. Attaching `open` and `close` to
// `window` (the browser's global object) is how panel.js, a classic script that
// cannot `import`, reaches them; panel.js checks the name exists before calling.
window.copilotSourceViewer = { open, close };
