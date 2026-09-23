/**
 * Clinical Co-Pilot panel: the browser side of the chart-open briefing.
 *
 * Renders the deterministic fact table first (no model involved), then the
 * verified AI summary above it. The transcript lives here in the browser
 * and is re-sent on every turn; the server re-verifies everything.
 *
 * This file runs inside the clinician's browser, on the patient summary
 * page. It never decides what is clinically true: every fact, sentence and
 * citation it shows was produced and verified on the server. Its job is to
 * ask the server for those results, draw them, and send the clinician's
 * actions (a question, an uploaded PDF) back to the server.
 *
 *   page loads -> brief()            -> POST chat.php {action: brief}
 *                                       -> renderFacts + renderNarration
 *              -> loadDocuments()    -> POST documents.php {action: list}
 *                                       -> renderDocuments
 *   upload form submit -> postDocuments(upload, file) -> extractDocument(id)
 *                      -> POST documents.php {action: extract}
 *                      -> renderHandoffs -> loadDocuments -> brief()
 *   a fact with a document citation -> sourceLink -> source-viewer.js
 *   ask form submit -> POST chat.php {action: ask} -> addTurn(answer)
 *
 * Three ideas a non-programmer meets repeatedly below:
 *   - `fetch` is the browser's way of calling the server. It returns a
 *     Promise: a placeholder for a result that arrives later. `.then(fn)`
 *     says "when the result arrives, run fn with it"; `.catch(fn)` says
 *     "if it failed, run fn instead"; `.finally(fn)` runs either way. The
 *     page keeps responding while the Promise is pending.
 *   - The CSRF token is a secret string the server printed into the page.
 *     We send it with every request so the server can tell "this came from
 *     the real chart page" apart from a forged request by another website.
 *   - `el(...)` builds pieces of the page from plain text, so anything that
 *     came out of the chart (or a PDF) is shown as text, never as markup.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */
// Wrapped in an IIFE (immediately-invoked function) so nothing leaks into the
// global scope of the chart page, which already has plenty of legacy JS.
(function () {
    // 'use strict' makes the browser reject sloppy JavaScript (undeclared variables etc.).
    'use strict';

    // The root <div> is server-rendered by Bootstrap::panelHtml(); it carries
    // the chat.php URL and the CSRF token as data-* attributes.
    // `document.getElementById` finds one element on the page by its id.
    // If the panel is not on this page there is nothing to do, so stop early.
    const panel = document.getElementById('copilot-panel');
    if (!panel) {
        return;
    }
    // `const` declares a name whose value is set once and never reassigned.
    // `panel.dataset.x` reads the HTML attribute `data-x` from the root div.
    const endpoint = panel.dataset.endpoint;                 // chat.php: briefing + questions
    const documentsEndpoint = panel.dataset.documentsEndpoint; // documents.php: list/upload/extract
    const docUrlTemplate = panel.dataset.docUrl;             // OpenEMR's PDF retrieval URL with {pid}/{id} slots
    const pid = panel.dataset.pid;                           // the patient id of the open chart
    const csrf = panel.dataset.csrf;                         // the CSRF token (see file-top comment)
    // Look up every page element the script writes into, once, by id. Each
    // id matches an empty container in the server-rendered skeleton.
    const els = {
        documentList: document.getElementById('copilot-document-list'),
        uploadForm: document.getElementById('copilot-upload'),
        uploadStatus: document.getElementById('copilot-upload-status'),
        docType: document.getElementById('copilot-doc-type'),
        file: document.getElementById('copilot-file'),
        status: document.getElementById('copilot-status'),
        narration: document.getElementById('copilot-narration'),
        guidelines: document.getElementById('copilot-guidelines'),
        facts: document.getElementById('copilot-facts'),
        form: document.getElementById('copilot-ask'),
        question: document.getElementById('copilot-question'),
        button: document.querySelector('#copilot-ask button'),
        thread: document.getElementById('copilot-thread'),
    };

    // Display heading for each FactCategory value, in the order the sections
    // are shown (most clinically urgent first). Must match FactCategory.php.
    // The left side of each line is the machine name the server sends; the
    // right side is what the clinician reads as a section heading.
    const CATEGORY_LABELS = {
        lab_critical: 'Critical lab values',
        allergy_medication_hit: 'Allergy / medication matches',
        document_mismatch: 'Document does not match the chart',
        intake_chief_concern: 'Reason for visit (intake form)',
        intake_med: 'Medications listed on the intake form',
        intake_allergy: 'Allergies listed on the intake form',
        extraction_unverified: 'Unverified values from uploaded documents',
        lab_abnormal: 'Abnormal labs since last visit',
        vital_abnormal: 'Abnormal vital signs',
        lab_pending: 'Labs ordered, no result on file',
        medication_stopped: 'Stopped medications',
        medication_new: 'New medications',
        medication_changed: 'Changed medications',
        allergy_new: 'New allergies',
        problem_new: 'New problems',
        encounter: 'Visits since last visit',
        prior_visit: 'Prior visit',
        lab_delta: 'Lab changes vs prior result',
        vital_delta: 'Vital sign changes vs prior reading',
        problem_resolved: 'Problems resolved since last visit',
        medication_active: 'Active medications',
        allergy_active: 'Allergies on file',
        lab_normal: 'Normal labs since last visit',
        prior_visit_plan: 'Plan from the prior visit',
        prior_visit_assessment: 'Assessment from the prior visit',
        intake_family_history: 'Family history (intake form)',
        truncation: 'Not shown',
    };
    // The keys of the table above, in the order written, become the section order.
    const CATEGORY_ORDER = Object.keys(CATEGORY_LABELS);

    // Per-page state. factsHash is echoed back on every question so the server
    // can detect that the chart changed; transcript is the chat history the
    // server is stateless about (it is re-sent on each turn).
    // factsById and guidelinesById let a fact id (or guideline chunk id) be
    // turned back into its text when a sentence cites it.
    const state = { factsHash: null, factsById: {}, guidelinesById: {}, transcript: [] };

    // Tiny DOM builder. All text goes through textContent / createTextNode,
    // never innerHTML with data, so chart text cannot inject markup.
    // Usage: el('p', { class: 'x', text: 'hello' }, [childNode, 'plain text']).
    // `attrs` is a bag of attributes; `class` and `text` are treated specially,
    // everything else becomes an HTML attribute of the same name.
    function el(tag, attrs, children) {
        const node = document.createElement(tag);
        // `Object.entries` turns {a: 1, b: 2} into [['a', 1], ['b', 2]] so we can loop over pairs.
        Object.entries(attrs || {}).forEach(([k, v]) => {
            if (k === 'class') node.className = v;
            else if (k === 'text') node.textContent = v;
            else node.setAttribute(k, v);
        });
        // Children may be ready-made elements or plain strings; strings become text nodes.
        (children || []).forEach(c => node.appendChild(typeof c === 'string' ? document.createTextNode(c) : c));
        return node;
    }

    // A small clickable badge showing a fact id. Hovering it highlights every
    // place that fact appears (in the summary and in the table) so a clinician
    // can see exactly which chart row a sentence is citing.
    // The same badge is reused for guideline citations (Week 2): those read
    // "guideline" instead of an id and get their own colour, so a sentence
    // backed by a guideline passage is never mistaken for one backed by the chart.
    function chip(id, extraClass) {
        const fact = state.factsById[id];
        const guideline = state.guidelinesById[id];
        const c = el('span', { class: 'copilot-chip ' + (extraClass || '') + (guideline ? ' copilot-chip-guideline' : ''), text: guideline ? 'guideline' : id, 'data-fact': id });
        // The tooltip (shown on hover) is the fact's value and where it came from,
        // or the guideline's title and section, or a plain "unknown fact" if the
        // id matches nothing we were sent (that should not happen after verification).
        c.title = fact ? (fact.value + '\n' + fact.source) : (guideline ? (guideline.title + ' > ' + guideline.section) : 'unknown fact');
        // mouseenter/mouseleave fire when the pointer moves onto/off the badge.
        c.addEventListener('mouseenter', () => highlight(id, true));
        c.addEventListener('mouseleave', () => highlight(id, false));
        return c;
    }

    // Switch the highlight class on (or off) for every element tagged with this fact id.
    function highlight(id, on) {
        panel.querySelectorAll('[data-fact="' + id + '"]').forEach(n => n.classList.toggle('copilot-chip-hit', on));
    }

    // The small status text in the card header ("loading…", "ref 1a2b3c4d", "timed out").
    // Errors are shown in red (Bootstrap's text-danger), everything else in grey.
    function setStatus(text, isError) {
        els.status.textContent = text;
        els.status.className = 'small ' + (isError ? 'text-danger' : 'text-muted');
    }

    // Builds the fact table from the response. This is rendered before (and
    // independently of) the AI summary — it is the deterministic, verified
    // baseline the clinician can always rely on.
    // `payload` is the JSON the server returned for the briefing: facts,
    // facts_hash and prior_visit (contracts/chat.briefing.response.schema.json).
    function renderFacts(payload) {
        // Remember the hash and index the facts by id for chips and "Also on file".
        state.factsHash = payload.facts_hash;
        state.factsById = {};
        payload.facts.forEach(f => { state.factsById[f.id] = f; });
        // Setting innerHTML to an empty string clears the container (no data involved).
        els.facts.innerHTML = '';
        // No facts: say so, and say whether that is "nothing changed" or "first visit".
        if (payload.facts.length === 0) {
            els.facts.appendChild(el('p', { class: 'copilot-muted', text: payload.prior_visit ? 'No changes on file since the prior visit on ' + payload.prior_visit + '.' : 'First visit on record; no comparison available.' }));
            return;
        }
        els.facts.appendChild(el('p', { class: 'copilot-muted', text: payload.prior_visit ? 'Compared with prior visit ' + payload.prior_visit + '.' : 'First visit on record; showing what is on file.' }));
        // One section per category, in urgency order; categories with no facts are skipped.
        CATEGORY_ORDER.forEach(cat => {
            // `filter` keeps only the facts whose category matches this section.
            const items = payload.facts.filter(f => f.category === cat);
            if (items.length === 0) return;
            els.facts.appendChild(el('h6', { text: CATEGORY_LABELS[cat] }));
            const ul = el('ul');
            items.forEach(f => {
                // Each row: the fact's text, then its id chip, then (Week 2) a link to
                // the PDF page it was read from when the fact came from a document.
                const children = [f.value, chip(f.id)];
                if (f.citation && f.citation.source_type === 'document') {
                    children.push(sourceLink(f));
                }
                // must_surface facts (e.g. an allergy hit) get a stronger style so they stand out.
                ul.appendChild(el('li', { class: f.must_surface ? 'copilot-must' : '', 'data-fact': f.id }, children));
            });
            els.facts.appendChild(ul);
        });
    }

    // Week 2: a fact that came from an uploaded document links to the page and
    // cell it was read from (source-viewer.js draws the box). Unverified values
    // get a dashed link and the viewer says why there is no highlight.
    // "anchored" is set by the server only when deterministic code found the
    // value's text on the page and stored its bounding box; a value the model
    // proposed but the anchor step could not place is anchored=false. That is
    // the safety line: the panel never implies a highlight exists when it does not.
    function sourceLink(f) {
        const c = f.citation;
        const anchored = c.anchored === true;
        // A mismatch flag is about the whole document, not a cell: link to the document, no verification badge.
        const flag = f.category === 'document_mismatch';
        // Three looks for the link (text and tooltip differ):
        //   flag      -> "open document"           (plain link)
        //   anchored  -> "source p.N"               (plain link; viewer will draw the box)
        //   otherwise -> "unverified, open source"  (dashed red link, class copilot-source-unverified)
        const a = el('a', { href: '#', class: 'copilot-source' + (anchored || flag ? '' : ' copilot-source-unverified'), title: flag ? 'Open the document' : (anchored ? 'Show where this was read on the document' : 'Could not be verified against the page; open the document') }, [flag ? 'open document' : (anchored ? 'source p.' + (c.page_or_section || '?') : 'unverified, open source')]);
        a.addEventListener('click', e => {
            // preventDefault stops the browser from following href="#" (which would jump to the top of the page).
            e.preventDefault();
            // The viewer is a separate ES module that registers itself on `window`; if it
            // failed to load (old browser, blocked script) say so instead of doing nothing.
            if (!window.copilotSourceViewer) { setStatus('The document viewer is not available.', true); return; }
            // For a flag, pass no citation so the viewer opens the document without a box.
            window.copilotSourceViewer.open(docUrl(c.source_id), flag ? null : c, 'Document ' + c.source_id + (flag ? '' : ', page ' + (c.page_or_section || '?')));
        });
        return a;
    }

    // Fill the {pid} and {id} slots of the server-supplied URL template. That
    // URL is OpenEMR's own document retrieval, which applies its access checks;
    // encodeURIComponent makes the values safe to place inside a URL.
    function docUrl(documentId) {
        return docUrlTemplate.replace('{pid}', encodeURIComponent(pid)).replace('{id}', encodeURIComponent(documentId));
    }

    // ---- Documents: list, upload, extract ---------------------------------

    /**
     * Send one request to documents.php. `fields` is a bag of form fields such as
     * {action: 'upload', doc_type: 'lab_pdf'}; `file` is the chosen PDF, if any.
     *
     * FormData is the browser's container for a multipart form submission: the
     * only encoding that can carry a file alongside ordinary text fields. The
     * CSRF token goes in first. The request is given 120 seconds because an
     * extraction reads every page, may OCR scans and calls the model; an
     * AbortController lets us cancel it if that budget runs out.
     *
     * Resolves to {ok, status, json} even for 4xx/5xx responses, so the caller
     * can show the server's own error message rather than a generic one.
     */
    function postDocuments(fields, file) {
        const body = new FormData();
        body.append('csrf_token_form', csrf);
        Object.entries(fields).forEach(([k, v]) => body.append(k, v));
        if (file) body.append('file', file, file.name);
        // setTimeout runs the abort after 120000 ms; clearTimeout cancels that if the reply arrives first.
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), 120000);
        // credentials: 'same-origin' sends the OpenEMR login cookie so the server knows who is asking.
        return fetch(documentsEndpoint, { method: 'POST', body, credentials: 'same-origin', signal: controller.signal })
            .then(r => r.json().then(j => ({ ok: r.ok, status: r.status, json: j })))
            .finally(() => clearTimeout(timer));
    }

    // How each server-side document status reads in the list. "stored" means the PDF
    // is saved but nothing has been read from it yet; "failed" means a read was attempted.
    const STATUS_LABEL = { stored: 'stored, not extracted', extracted: 'extracted', failed: 'extraction failed' };

    /**
     * Draw the "Uploaded documents" list. Each row shows the file name, its type,
     * its status, and for an extracted file the share of values that were verified
     * against the page. Files that are not yet extracted get an "Extract" button;
     * failed ones get "Retry extraction". Every row has an "open" link to view the PDF.
     */
    function renderDocuments(docs) {
        // replaceChildren() with no arguments empties the list.
        els.documentList.replaceChildren();
        if (docs.length === 0) {
            els.documentList.appendChild(el('li', { class: 'copilot-muted', text: 'No documents uploaded for this patient.' }));
            return;
        }
        docs.forEach(d => {
            // Build the row's text as a list of pieces, then join them into one string.
            const parts = [d.filename + ' (' + (d.doc_type === 'lab_pdf' ? 'lab report' : 'intake form') + '): ' + (STATUS_LABEL[d.status] || d.status)];
            // confidence is the fraction of extracted values the anchor step found on the page (0..1).
            if (d.status === 'extracted' && typeof d.confidence === 'number') parts.push(', ' + Math.round(d.confidence * 100) + '% of values verified');
            // failure_reason is a machine label like "no_text_layer"; underscores become spaces for reading.
            if (d.status === 'failed' && d.failure_reason) parts.push(' (' + d.failure_reason.replace(/_/g, ' ') + ')');
            const li = el('li', { class: 'copilot-doc copilot-doc-' + d.status }, [parts.join('')]);
            if (d.status !== 'extracted') {
                const btn = el('button', { type: 'button', class: 'btn btn-link btn-sm p-0 ml-2', text: d.status === 'failed' ? 'Retry extraction' : 'Extract' });
                btn.addEventListener('click', () => extractDocument(d.document_id));
                li.appendChild(btn);
            }
            // "open" shows the PDF in the viewer with no citation, so no box is drawn.
            const view = el('a', { href: '#', class: 'copilot-source ml-2', text: 'open' });
            view.addEventListener('click', e => { e.preventDefault(); if (window.copilotSourceViewer) window.copilotSourceViewer.open(docUrl(d.document_id), null, d.filename); });
            li.appendChild(view);
            els.documentList.appendChild(li);
        });
    }

    // "Why this answer": the supervisor's routing decisions for the last run,
    // one line per hop, straight from the handoff log the sidecar returned.
    // Each handoff (contracts/handoff.schema.json) says which worker handed to
    // which, why, how long it took, and which parts of the shared state changed.
    function renderHandoffs(handoffs) {
        // The box is a collapsible <details> element created on first use and placed
        // right after the upload status line; later calls reuse it.
        let box = document.getElementById('copilot-handoffs');
        if (!box) {
            box = el('details', { id: 'copilot-handoffs', class: 'copilot-handoffs' }, [el('summary', { text: 'Why this result: routing decisions' })]);
            els.uploadStatus.insertAdjacentElement('afterend', box);
        }
        // Drop the previous run's list before drawing the new one.
        Array.from(box.querySelectorAll('ol')).forEach(n => n.remove());
        if (!Array.isArray(handoffs) || handoffs.length === 0) return;
        const ol = el('ol');
        handoffs.forEach(h => {
            // e.g. "supervisor → lab_extractor because doc_type lab pdf (812 ms, changed extraction)".
            ol.appendChild(el('li', { text: h.from + ' → ' + h.to + ' because ' + String(h.reason).replace(/_/g, ' ') + ' (' + h.ms + ' ms' + (h.state_keys_changed && h.state_keys_changed.length ? ', changed ' + h.state_keys_changed.join(', ') : '') + ')' }));
        });
        box.appendChild(ol);
    }

    // Ask the server for this patient's documents and redraw the list.
    function loadDocuments() {
        return postDocuments({ action: 'list' }).then(r => {
            if (r.ok && Array.isArray(r.json.documents)) renderDocuments(r.json.documents);
        }).catch(() => { /* the list is informational; the briefing does not depend on it */ });
    }

    /**
     * Run extraction on a stored document and report the outcome in the upload
     * status line. This is the "extracting" state of the upload control; the
     * clinician sees, in order:
     *
     *   "Extracting… (reading the pages and checking every value against the document)"
     *   then one of:
     *   - "Extracted N value(s), P% verified against the page[, U unverified][, R row(s)
     *     not extracted]. Refreshing the briefing…"  -> list and briefing reload
     *   - "Extraction failed: <reason>. The file is stored; you can retry."  -> the list
     *     shows a "Retry extraction" button next to the file
     *   - the server's own error text (e.g. the document service is unavailable)
     *   - "Extraction timed out; the file is stored, retry from the list." if no
     *     usable reply came back within the 120 s budget
     *
     * The briefing is refreshed after a successful extraction because extracted
     * values become new chart facts (labs, intake meds) and the fact table must
     * show them; the server's facts hash changes, so any open chat is stale.
     */
    function extractDocument(documentId) {
        els.uploadStatus.textContent = 'Extracting… (reading the pages and checking every value against the document)';
        return postDocuments({ action: 'extract', document_id: String(documentId) }).then(r => {
            // Not ok (4xx/5xx): show the server's message and refresh the list so the
            // retry button appears if the file is still stored.
            if (!r.ok) {
                els.uploadStatus.textContent = (r.json && r.json.error) || 'Extraction failed.';
                return loadDocuments();
            }
            const j = r.json;
            // A 200 reply can still carry status "failed" (the sidecar ran but could not
            // read the file); that is a clean failure the clinician can retry.
            if (j.status === 'failed') {
                els.uploadStatus.textContent = 'Extraction failed: ' + (j.failure_reason || 'unknown').replace(/_/g, ' ') + '. The file is stored; you can retry.';
            } else {
                els.uploadStatus.textContent = 'Extracted ' + j.results_persisted + ' value(s), ' + Math.round(j.confidence * 100) + '% verified against the page'
                    + (j.unverified ? ', ' + j.unverified + ' unverified' : '') + (j.unextracted ? ', ' + j.unextracted + ' row(s) not extracted' : '') + '. Refreshing the briefing…';
            }
            renderHandoffs(j.handoffs);
            // Reload the document list, and only then (the .then) the briefing.
            return loadDocuments().then(brief);
        }).catch(() => { els.uploadStatus.textContent = 'Extraction timed out; the file is stored, retry from the list.'; });
    }

    // The upload form: choose a type, choose a PDF, press "Upload and extract".
    // States of the control as the clinician sees them:
    //   idle       -> status line empty (or showing the last outcome)
    //   no file    -> "Choose a PDF first."
    //   uploading  -> "Uploading…"
    //   extracting -> handled by extractDocument() above
    //   duplicate  -> "This file was already uploaded and extracted." (list refreshes)
    //   failed     -> the server's reason (too large, not a PDF) or "Upload failed."
    if (els.uploadForm) {
        els.uploadForm.addEventListener('submit', e => {
            // Stop the browser's default form submission, which would reload the whole chart page.
            e.preventDefault();
            // `files[0]` is the first (only) file picked in the <input type="file">.
            const file = els.file.files && els.file.files[0];
            if (!file) { els.uploadStatus.textContent = 'Choose a PDF first.'; return; }
            els.uploadStatus.textContent = 'Uploading…';
            postDocuments({ action: 'upload', doc_type: els.docType.value }, file).then(r => {
                if (!r.ok) { els.uploadStatus.textContent = (r.json && r.json.error) || 'Upload failed.'; return null; }
                // Clear the file picker so the same file is not re-sent by accident.
                els.file.value = '';
                // The server de-duplicates by content: an identical PDF that was already
                // extracted is not extracted again (no second model call, no duplicate facts).
                if (r.json.status === 'extracted') { els.uploadStatus.textContent = 'This file was already uploaded and extracted.'; return loadDocuments(); }
                // Otherwise go straight on to extraction with the new document's id.
                return extractDocument(r.json.document_id);
            }).catch(() => { els.uploadStatus.textContent = 'Upload failed.'; });
        });
    }

    // One summary sentence followed by chips for the facts it cites. The regex
    // strips any "[abcd1234]" the model left inline (belt-and-braces; the
    // server already scrubs these).
    // A regex (regular expression) is a pattern for matching text; this one
    // matches a square-bracketed list of 8-character hex ids and deletes it.
    function sentenceNode(s) {
        const p = el('p');
        p.appendChild(document.createTextNode(s.text.replace(/\s*\[[0-9a-f]{8}(?:\s*,\s*[0-9a-f]{8})*\]/g, '') + ' '));
        s.fact_ids.forEach(id => p.appendChild(chip(id, 'copilot-chip-ref')));
        return p;
    }

    // "Generated 6:02 AM today · matches chart as of now": a served narration is
    // always re-verified against the live facts, so the time dates the wording,
    // never the facts. A narration made in this request says so.
    function generatedLabel(n) {
        if (!n.from_cache || !n.generated_at) return 'generated just now';
        // `new Date(text)` parses the server's timestamp; getTime() is NaN if it could not.
        const at = new Date(n.generated_at);
        if (isNaN(at.getTime())) return 'cached';
        const now = new Date();
        const sameDay = at.toDateString() === now.toDateString();
        // toLocaleTimeString/DateString format in the clinician's own locale and time zone.
        const time = at.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
        const when = sameDay ? time + ' today' : at.toLocaleDateString([], { month: 'short', day: 'numeric' }) + ' ' + time;
        return 'generated ' + when + ' · matches chart as of now';
    }

    // Renders "What the guidelines say about this chart": one card per fired
    // trigger, quoting the retrieved passages and naming the facts that raised
    // the topic. Cards are guideline text, never facts about the patient, so
    // their chips read "guideline" and their check label says what the critic
    // did (or did not) verify. renderFacts() must run first: the "Because:"
    // line looks facts up by id.
    function renderGuidelines(g) {
        els.guidelines.innerHTML = '';
        if (!g || g.status === 'not_configured') return;
        els.guidelines.appendChild(el('div', { class: 'copilot-label', text: 'What the guidelines say about this chart' }));
        if (g.status === 'unavailable') {
            els.guidelines.appendChild(el('div', { class: 'copilot-alert', text: 'Guideline evidence is unavailable right now. The chart facts above are complete.' }));
            return;
        }
        if (g.status === 'no_triggers' || g.cards.length === 0) {
            els.guidelines.appendChild(el('p', { class: 'copilot-muted', text: g.status === 'no_triggers' ? 'No guideline topic in the corpus matches this chart.' : 'No guideline passage matched the topics this chart raised.' }));
            return;
        }
        g.cards.forEach(card => {
            card.chunks.forEach(c => { state.guidelinesById[c.chunk_id] = c; });
            const because = el('p', { class: 'copilot-because' }, ['Because: ']);
            let parts = 0;
            card.reasons.forEach(r => { because.appendChild(document.createTextNode((parts++ ? '; ' : '') + r)); });
            card.because_fact_ids.forEach(id => {
                const f = state.factsById[id];
                if (!f) return;
                because.appendChild(document.createTextNode((parts++ ? '; ' : '') + f.value + ' '));
                because.appendChild(chip(id));
            });
            const node = el('div', { class: 'copilot-card', 'data-trigger': card.trigger_id }, [el('h6', { text: card.label }), because]);
            card.chunks.forEach(c => {
                node.appendChild(el('blockquote', { class: 'copilot-quote' }, [c.quote + ' ', chip(c.chunk_id, 'copilot-chip-ref')]));
                const label = c.title + ' › ' + c.section;
                node.appendChild(el('div', { class: 'copilot-source' }, [c.url ? el('a', { href: c.url, target: '_blank', rel: 'noopener', text: label }) : el('span', { text: label })]));
            });
            node.appendChild(el('div', { class: 'copilot-checked', text: card.checked_label + (card.reason ? ' · ' + card.reason : '') }));
            els.guidelines.appendChild(node);
        });
    }

    // Renders the AI summary block. Three branches: the model failed (show the
    // status label), everything was stripped (say so), or normal. In every
    // case the "Also on file" list appends must-surface facts the model
    // skipped, so nothing important is hidden by a bad summary.
    // `n` is the narration part of the briefing response: status, total_failure,
    // sentences (each with text + fact_ids), stripped count, omitted_fact_ids.
    function renderNarration(n) {
        els.narration.innerHTML = '';
        if (n.status) {
            // The model was down, timed out or refused. Say so plainly and point at the table.
            els.narration.appendChild(el('div', { class: 'copilot-alert copilot-error', text: n.status + '. The fact table below is complete and verified.' }));
        } else if (n.total_failure) {
            // The model answered but the verifier could not keep any of it.
            els.narration.appendChild(el('div', { class: 'copilot-alert copilot-error', text: 'Unable to verify the AI summary for this patient; showing verified chart facts only.' }));
        } else {
            els.narration.appendChild(el('div', { class: 'copilot-label', text: 'AI summary (every sentence cites verified facts) · ' + generatedLabel(n) }));
            if (n.sentences.length === 0) {
                els.narration.appendChild(el('p', { class: 'copilot-muted', text: 'Nothing to summarize.' }));
            }
            n.sentences.forEach(s => els.narration.appendChild(sentenceNode(s)));
            // Be honest about what was removed: the clinician should know the summary is partial.
            if (n.stripped > 0) {
                els.narration.appendChild(el('div', { class: 'copilot-alert', text: n.stripped + ' unverified sentence' + (n.stripped > 1 ? 's were' : ' was') + ' removed.' }));
            }
        }
        // Facts the omission guard found missing from the summary, listed by their recorded value.
        if (n.omitted_fact_ids.length > 0) {
            const wrap = el('div', {}, [el('div', { class: 'copilot-label', text: 'Also on file since last visit' })]);
            n.omitted_fact_ids.forEach(id => {
                const f = state.factsById[id];
                if (f) wrap.appendChild(el('p', {}, [f.value + ' ', chip(id, 'copilot-chip-ref')]));
            });
            els.narration.appendChild(wrap);
        }
    }

    // POST form-encoded fields to chat.php with the CSRF token and a 30s
    // client-side timeout. Resolves to {ok, status, json} even for 4xx/5xx so
    // callers can show the server's error message.
    // URLSearchParams encodes plain text fields the way an HTML form does
    // (no files here, unlike postDocuments). Object.assign merges the CSRF
    // token into the caller's fields. The AbortController/setTimeout pair works
    // exactly as in postDocuments, with a shorter budget because there is no PDF to read.
    function post(fields) {
        const body = new URLSearchParams(Object.assign({ csrf_token_form: csrf }, fields));
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), 30000);
        return fetch(endpoint, { method: 'POST', body, credentials: 'same-origin', signal: controller.signal })
            .then(r => r.json().then(j => ({ ok: r.ok, status: r.status, json: j })))
            .finally(() => clearTimeout(timer));
    }

    // Grey out (or re-enable) the question box and Ask button. It starts disabled
    // in the HTML and is only enabled once a briefing has loaded, so a question can
    // never be sent without a facts hash to check it against.
    function enableAsk(on) {
        els.question.disabled = !on;
        els.button.disabled = !on;
    }

    // Append one message to the chat thread, styled by who said it ("user" or "assistant").
    function addTurn(role, node) {
        const t = el('div', { class: 'copilot-turn copilot-' + role });
        if (typeof node === 'string') t.textContent = node; else t.appendChild(node);
        els.thread.appendChild(t);
    }

    // Initial load (and re-load after a chart change): fetch facts + summary.
    // The destructuring `({ ok, json })` pulls those two fields out of the
    // {ok, status, json} object that post() resolves with.
    function brief() {
        setStatus('loading…');
        post({ action: 'brief' }).then(({ ok, json }) => {
            // Server refused or errored: show its message in the header and in the fact area.
            if (!ok) {
                setStatus(json.error || 'unavailable', true);
                els.facts.innerHTML = '';
                els.facts.appendChild(el('p', { class: 'copilot-muted', text: json.error || 'The Co-Pilot is unavailable for this chart.' }));
                return;
            }
            renderFacts(json);
            renderNarration(json.narration);
            renderGuidelines(json.guidelines);
            // The first 8 characters of the correlation id, so a clinician reporting a
            // problem can quote a reference that matches the server logs and trace.
            setStatus('ref ' + json.correlation_id.slice(0, 8));
            enableAsk(true);
        }).catch(err => {
            // No usable reply at all: the network failed, the 30 s timer aborted the
            // request (err.name is 'AbortError'), or the body was not JSON.
            setStatus(err.name === 'AbortError' ? 'timed out' : 'unavailable', true);
            els.facts.innerHTML = '';
            els.facts.appendChild(el('p', { class: 'copilot-muted', text: 'The Co-Pilot could not be reached. The chart below is unaffected.' }));
        });
    }

    // Follow-up question flow.
    // Runs when the clinician presses Ask (or Enter in the question box).
    els.form.addEventListener('submit', ev => {
        ev.preventDefault();
        // trim() removes leading/trailing spaces; an empty question is ignored.
        const question = els.question.value.trim();
        if (!question) return;
        // Lock the box while the request is in flight so two questions cannot overlap.
        enableAsk(false);
        addTurn('user', question);
        els.question.value = '';
        // The whole transcript so far travels with the question as a JSON string;
        // the server keeps no conversation state of its own.
        post({ action: 'ask', question, facts_hash: state.factsHash, transcript: JSON.stringify(state.transcript) })
            .then(({ ok, json }) => {
                // Server says the chart moved since the briefing: wipe the
                // conversation (its context is stale) and start over.
                if (json.chart_changed) {
                    renderFacts(json);
                    state.transcript = [];
                    els.thread.innerHTML = '';
                    addTurn('assistant', 'The chart changed since the briefing was generated. Facts were refreshed; please ask again.');
                    brief();
                    return;
                }
                if (!ok) {
                    addTurn('assistant', json.error || 'Unavailable.');
                    return;
                }
                // Map the answer to a message. "not_in_facts" and "all
                // sentences stripped" are distinct outcomes with distinct advice.
                const a = json.answer;
                let node;
                if (a.status) {
                    // The model failed (down / timed out / refused); show the short status label.
                    node = el('span', { text: a.status });
                } else if (a.type === 'not_in_facts') {
                    // The server decided the question is outside the briefing window and
                    // no guideline passage covers it, so no model answer was attempted.
                    node = el('span', { text: 'Not in the facts for this briefing window, and no guideline passage in the corpus answers it. Check the chart tabs for older records.' });
                } else if (a.sentences.length === 0) {
                    // The model answered but every sentence was dropped by the verifier.
                    // A stripped count > 0 usually means it computed or invented a number.
                    node = el('span', { text: a.stripped > 0
                        ? 'The answer was withheld: it contained ' + a.stripped + ' claim' + (a.stripped > 1 ? 's' : '') + ' not supported by the facts on file (for example a computed number). Try asking for the recorded values.'
                        : 'No verifiable answer could be given from the facts on file.' });
                } else {
                    // Week 2: sentences citing only guideline chunks are shown apart from
                    // sentences about the patient, so the two are never read as one claim.
                    // First remember the guideline chunks by id so chips can label them.
                    (a.guidelines || []).forEach(g => { state.guidelinesById[g.chunk_id] = g; });
                    // A sentence is "guideline" when every id it cites is a guideline chunk.
                    // `every` returns true only if the test holds for all items.
                    const isGuideline = s => s.fact_ids.length > 0 && s.fact_ids.every(id => state.guidelinesById[id]);
                    const record = a.sentences.filter(s => !isGuideline(s));
                    const guide = a.sentences.filter(isGuideline);
                    node = el('div');
                    if (record.length) {
                        node.appendChild(el('div', { class: 'copilot-label', text: 'From the record' }));
                        record.forEach(s => node.appendChild(sentenceNode(s)));
                    }
                    if (guide.length) {
                        // The heading itself says these lines are not about this patient.
                        node.appendChild(el('div', { class: 'copilot-label', text: 'From guidelines (not this patient\'s record)' }));
                        guide.forEach(s => node.appendChild(sentenceNode(s)));
                        // Below the sentences, the quoted passages themselves: title, the last
                        // heading of the section path, the verbatim quote, and a link if one exists.
                        const list = el('ul', { class: 'copilot-guidelines' });
                        (a.guidelines || []).forEach(g => {
                            const li = el('li', {}, [el('strong', { text: g.title }), ' — ' + String(g.section).split(' > ').pop() + ': ', el('span', { class: 'copilot-quote', text: g.quote })]);
                            // target=_blank opens a new tab; rel=noopener stops that tab reaching back into this page.
                            if (g.url) li.appendChild(el('a', { href: g.url, target: '_blank', rel: 'noopener', text: ' source' }));
                            list.appendChild(li);
                        });
                        node.appendChild(list);
                    }
                    if (a.stripped > 0) node.appendChild(el('div', { class: 'copilot-muted', text: a.stripped + ' unverified sentence(s) removed.' }));
                }
                addTurn('assistant', node);
                // Record both turns so the next question carries the full conversation.
                // The assistant turn is stored as plain text (textContent), chips and all.
                state.transcript.push({ role: 'user', text: question });
                state.transcript.push({ role: 'assistant', text: node.textContent });
            })
            .catch(() => addTurn('assistant', 'The Co-Pilot could not be reached.'))
            .finally(() => enableAsk(true));
    });

    // Kick off the first briefing as soon as the script runs.
    // The two requests run side by side; neither waits for the other.
    brief();
    loadDocuments();
})();
