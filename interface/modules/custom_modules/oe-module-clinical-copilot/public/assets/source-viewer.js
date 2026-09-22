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
 */
import * as pdfjsLib from './vendor/pdfjs/pdf.min.mjs';

const workerUrl = new URL('./vendor/pdfjs/pdf.worker.min.mjs', import.meta.url).href;
pdfjsLib.GlobalWorkerOptions.workerSrc = workerUrl;

const state = { pdf: null, url: null, page: 1, scale: 1.4, citation: null, rotation: 0 };
let root = null;

function el(tag, attrs, children) {
    const node = document.createElement(tag);
    Object.entries(attrs || {}).forEach(([k, v]) => {
        if (k === 'text') node.textContent = v; else node.setAttribute(k, v);
    });
    (children || []).forEach(c => node.appendChild(typeof c === 'string' ? document.createTextNode(c) : c));
    return node;
}

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
    root.querySelector('#copilot-viewer-close').addEventListener('click', close);
    root.addEventListener('click', e => { if (e.target === root) close(); });
    root.querySelector('#copilot-viewer-prev').addEventListener('click', () => go(state.page - 1));
    root.querySelector('#copilot-viewer-next').addEventListener('click', () => go(state.page + 1));
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && !root.hidden) close(); });
    return root;
}

function close() {
    if (root) root.hidden = true;
}

async function load(url) {
    if (state.pdf && state.url === url) return state.pdf;
    const task = pdfjsLib.getDocument({ url, withCredentials: true });
    state.pdf = await task.promise;
    state.url = url;
    return state.pdf;
}

async function go(pageNo) {
    if (!state.pdf) return;
    const n = Math.max(1, Math.min(state.pdf.numPages, pageNo));
    state.page = n;
    const page = await state.pdf.getPage(n);
    const viewport = page.getViewport({ scale: state.scale });
    const canvas = root.querySelector('#copilot-viewer-canvas');
    const overlay = root.querySelector('#copilot-viewer-overlay');
    canvas.width = viewport.width;
    canvas.height = viewport.height;
    overlay.style.width = viewport.width + 'px';
    overlay.style.height = viewport.height + 'px';
    overlay.replaceChildren();
    root.querySelector('#copilot-viewer-page').textContent = n + ' / ' + state.pdf.numPages;
    await page.render({ canvasContext: canvas.getContext('2d'), viewport }).promise;
    drawBoxes(overlay, viewport, n);
}

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

window.copilotSourceViewer = { open, close };
