/**
 * Clinical Co-Pilot panel.
 *
 * Renders the deterministic fact table first (no model involved), then the
 * verified AI summary above it. The transcript lives here in the browser
 * and is re-sent on every turn; the server re-verifies everything.
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
    'use strict';

    // The root <div> is server-rendered by Bootstrap::panelHtml(); it carries
    // the chat.php URL and the CSRF token as data-* attributes.
    const panel = document.getElementById('copilot-panel');
    if (!panel) {
        return;
    }
    const endpoint = panel.dataset.endpoint;
    const documentsEndpoint = panel.dataset.documentsEndpoint;
    const docUrlTemplate = panel.dataset.docUrl;
    const pid = panel.dataset.pid;
    const csrf = panel.dataset.csrf;
    const els = {
        documentList: document.getElementById('copilot-document-list'),
        uploadForm: document.getElementById('copilot-upload'),
        uploadStatus: document.getElementById('copilot-upload-status'),
        docType: document.getElementById('copilot-doc-type'),
        file: document.getElementById('copilot-file'),
        status: document.getElementById('copilot-status'),
        narration: document.getElementById('copilot-narration'),
        facts: document.getElementById('copilot-facts'),
        form: document.getElementById('copilot-ask'),
        question: document.getElementById('copilot-question'),
        button: document.querySelector('#copilot-ask button'),
        thread: document.getElementById('copilot-thread'),
    };

    // Display heading for each FactCategory value, in the order the sections
    // are shown (most clinically urgent first). Must match FactCategory.php.
    const CATEGORY_LABELS = {
        allergy_medication_hit: 'Allergy / medication matches',
        document_mismatch: 'Document does not match the chart',
        intake_chief_concern: 'Reason for visit (intake form)',
        intake_med: 'Medications listed on the intake form',
        intake_allergy: 'Allergies listed on the intake form',
        extraction_unverified: 'Unverified values from uploaded documents',
        lab_abnormal: 'Abnormal labs since last visit',
        medication_new: 'New medications',
        medication_changed: 'Changed medications',
        allergy_new: 'New allergies',
        problem_new: 'New problems',
        encounter: 'Visits since last visit',
        prior_visit: 'Prior visit',
        lab_delta: 'Lab changes vs prior result',
        medication_active: 'Active medications',
        allergy_active: 'Allergies on file',
        intake_family_history: 'Family history (intake form)',
        truncation: 'Not shown',
    };
    const CATEGORY_ORDER = Object.keys(CATEGORY_LABELS);

    // Per-page state. factsHash is echoed back on every question so the server
    // can detect that the chart changed; transcript is the chat history the
    // server is stateless about (it is re-sent on each turn).
    const state = { factsHash: null, factsById: {}, transcript: [] };

    // Tiny DOM builder. All text goes through textContent / createTextNode,
    // never innerHTML with data, so chart text cannot inject markup.
    function el(tag, attrs, children) {
        const node = document.createElement(tag);
        Object.entries(attrs || {}).forEach(([k, v]) => {
            if (k === 'class') node.className = v;
            else if (k === 'text') node.textContent = v;
            else node.setAttribute(k, v);
        });
        (children || []).forEach(c => node.appendChild(typeof c === 'string' ? document.createTextNode(c) : c));
        return node;
    }

    // A small clickable badge showing a fact id. Hovering it highlights every
    // place that fact appears (in the summary and in the table) so a clinician
    // can see exactly which chart row a sentence is citing.
    function chip(id, extraClass) {
        const fact = state.factsById[id];
        const c = el('span', { class: 'copilot-chip ' + (extraClass || ''), text: id, 'data-fact': id });
        c.title = fact ? (fact.value + '\n' + fact.source) : 'unknown fact';
        c.addEventListener('mouseenter', () => highlight(id, true));
        c.addEventListener('mouseleave', () => highlight(id, false));
        return c;
    }

    function highlight(id, on) {
        panel.querySelectorAll('[data-fact="' + id + '"]').forEach(n => n.classList.toggle('copilot-chip-hit', on));
    }

    function setStatus(text, isError) {
        els.status.textContent = text;
        els.status.className = 'small ' + (isError ? 'text-danger' : 'text-muted');
    }

    // Builds the fact table from the response. This is rendered before (and
    // independently of) the AI summary — it is the deterministic, verified
    // baseline the clinician can always rely on.
    function renderFacts(payload) {
        state.factsHash = payload.facts_hash;
        state.factsById = {};
        payload.facts.forEach(f => { state.factsById[f.id] = f; });
        els.facts.innerHTML = '';
        if (payload.facts.length === 0) {
            els.facts.appendChild(el('p', { class: 'copilot-muted', text: payload.prior_visit ? 'No changes on file since the prior visit on ' + payload.prior_visit + '.' : 'First visit on record; no comparison available.' }));
            return;
        }
        els.facts.appendChild(el('p', { class: 'copilot-muted', text: payload.prior_visit ? 'Compared with prior visit ' + payload.prior_visit + '.' : 'First visit on record; showing what is on file.' }));
        CATEGORY_ORDER.forEach(cat => {
            const items = payload.facts.filter(f => f.category === cat);
            if (items.length === 0) return;
            els.facts.appendChild(el('h6', { text: CATEGORY_LABELS[cat] }));
            const ul = el('ul');
            items.forEach(f => {
                const children = [f.value, chip(f.id)];
                if (f.citation && f.citation.source_type === 'document') {
                    children.push(sourceLink(f));
                }
                ul.appendChild(el('li', { class: f.must_surface ? 'copilot-must' : '', 'data-fact': f.id }, children));
            });
            els.facts.appendChild(ul);
        });
    }

    // Week 2: a fact that came from an uploaded document links to the page and
    // cell it was read from (source-viewer.js draws the box). Unverified values
    // get a dashed link and the viewer says why there is no highlight.
    function sourceLink(f) {
        const c = f.citation;
        const anchored = c.anchored === true;
        const a = el('a', { href: '#', class: 'copilot-source' + (anchored ? '' : ' copilot-source-unverified'), title: anchored ? 'Show where this was read on the document' : 'Could not be verified against the page; open the document' }, [anchored ? 'source p.' + (c.page_or_section || '?') : 'unverified, open source']);
        a.addEventListener('click', e => {
            e.preventDefault();
            if (!window.copilotSourceViewer) { setStatus('The document viewer is not available.', true); return; }
            window.copilotSourceViewer.open(docUrl(c.source_id), c, 'Document ' + c.source_id + ', page ' + (c.page_or_section || '?'));
        });
        return a;
    }

    function docUrl(documentId) {
        return docUrlTemplate.replace('{pid}', encodeURIComponent(pid)).replace('{id}', encodeURIComponent(documentId));
    }

    // ---- Documents: list, upload, extract ---------------------------------

    function postDocuments(fields, file) {
        const body = new FormData();
        body.append('csrf_token_form', csrf);
        Object.entries(fields).forEach(([k, v]) => body.append(k, v));
        if (file) body.append('file', file, file.name);
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), 120000);
        return fetch(documentsEndpoint, { method: 'POST', body, credentials: 'same-origin', signal: controller.signal })
            .then(r => r.json().then(j => ({ ok: r.ok, status: r.status, json: j })))
            .finally(() => clearTimeout(timer));
    }

    const STATUS_LABEL = { stored: 'stored, not extracted', extracted: 'extracted', failed: 'extraction failed' };

    function renderDocuments(docs) {
        els.documentList.replaceChildren();
        if (docs.length === 0) {
            els.documentList.appendChild(el('li', { class: 'copilot-muted', text: 'No documents uploaded for this patient.' }));
            return;
        }
        docs.forEach(d => {
            const parts = [d.filename + ' (' + (d.doc_type === 'lab_pdf' ? 'lab report' : 'intake form') + '): ' + (STATUS_LABEL[d.status] || d.status)];
            if (d.status === 'extracted' && typeof d.confidence === 'number') parts.push(', ' + Math.round(d.confidence * 100) + '% of values verified');
            if (d.status === 'failed' && d.failure_reason) parts.push(' (' + d.failure_reason.replace(/_/g, ' ') + ')');
            const li = el('li', { class: 'copilot-doc copilot-doc-' + d.status }, [parts.join('')]);
            if (d.status !== 'extracted') {
                const btn = el('button', { type: 'button', class: 'btn btn-link btn-sm p-0 ml-2', text: d.status === 'failed' ? 'Retry extraction' : 'Extract' });
                btn.addEventListener('click', () => extractDocument(d.document_id));
                li.appendChild(btn);
            }
            const view = el('a', { href: '#', class: 'copilot-source ml-2', text: 'open' });
            view.addEventListener('click', e => { e.preventDefault(); if (window.copilotSourceViewer) window.copilotSourceViewer.open(docUrl(d.document_id), null, d.filename); });
            li.appendChild(view);
            els.documentList.appendChild(li);
        });
    }

    // "Why this answer": the supervisor's routing decisions for the last run,
    // one line per hop, straight from the handoff log the sidecar returned.
    function renderHandoffs(handoffs) {
        let box = document.getElementById('copilot-handoffs');
        if (!box) {
            box = el('details', { id: 'copilot-handoffs', class: 'copilot-handoffs' }, [el('summary', { text: 'Why this result: routing decisions' })]);
            els.uploadStatus.insertAdjacentElement('afterend', box);
        }
        Array.from(box.querySelectorAll('ol')).forEach(n => n.remove());
        if (!Array.isArray(handoffs) || handoffs.length === 0) return;
        const ol = el('ol');
        handoffs.forEach(h => {
            ol.appendChild(el('li', { text: h.from + ' → ' + h.to + ' because ' + String(h.reason).replace(/_/g, ' ') + ' (' + h.ms + ' ms' + (h.state_keys_changed && h.state_keys_changed.length ? ', changed ' + h.state_keys_changed.join(', ') : '') + ')' }));
        });
        box.appendChild(ol);
    }

    function loadDocuments() {
        return postDocuments({ action: 'list' }).then(r => {
            if (r.ok && Array.isArray(r.json.documents)) renderDocuments(r.json.documents);
        }).catch(() => { /* the list is informational; the briefing does not depend on it */ });
    }

    function extractDocument(documentId) {
        els.uploadStatus.textContent = 'Extracting… (reading the pages and checking every value against the document)';
        return postDocuments({ action: 'extract', document_id: String(documentId) }).then(r => {
            if (!r.ok) {
                els.uploadStatus.textContent = (r.json && r.json.error) || 'Extraction failed.';
                return loadDocuments();
            }
            const j = r.json;
            if (j.status === 'failed') {
                els.uploadStatus.textContent = 'Extraction failed: ' + (j.failure_reason || 'unknown').replace(/_/g, ' ') + '. The file is stored; you can retry.';
            } else {
                els.uploadStatus.textContent = 'Extracted ' + j.results_persisted + ' value(s), ' + Math.round(j.confidence * 100) + '% verified against the page'
                    + (j.unverified ? ', ' + j.unverified + ' unverified' : '') + (j.unextracted ? ', ' + j.unextracted + ' row(s) not extracted' : '') + '. Refreshing the briefing…';
            }
            renderHandoffs(j.handoffs);
            return loadDocuments().then(brief);
        }).catch(() => { els.uploadStatus.textContent = 'Extraction timed out; the file is stored, retry from the list.'; });
    }

    if (els.uploadForm) {
        els.uploadForm.addEventListener('submit', e => {
            e.preventDefault();
            const file = els.file.files && els.file.files[0];
            if (!file) { els.uploadStatus.textContent = 'Choose a PDF first.'; return; }
            els.uploadStatus.textContent = 'Uploading…';
            postDocuments({ action: 'upload', doc_type: els.docType.value }, file).then(r => {
                if (!r.ok) { els.uploadStatus.textContent = (r.json && r.json.error) || 'Upload failed.'; return null; }
                els.file.value = '';
                if (r.json.status === 'extracted') { els.uploadStatus.textContent = 'This file was already uploaded and extracted.'; return loadDocuments(); }
                return extractDocument(r.json.document_id);
            }).catch(() => { els.uploadStatus.textContent = 'Upload failed.'; });
        });
    }

    // One summary sentence followed by chips for the facts it cites. The regex
    // strips any "[abcd1234]" the model left inline (belt-and-braces; the
    // server already scrubs these).
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
        const at = new Date(n.generated_at);
        if (isNaN(at.getTime())) return 'cached';
        const now = new Date();
        const sameDay = at.toDateString() === now.toDateString();
        const time = at.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
        const when = sameDay ? time + ' today' : at.toLocaleDateString([], { month: 'short', day: 'numeric' }) + ' ' + time;
        return 'generated ' + when + ' · matches chart as of now';
    }

    // Renders the AI summary block. Three branches: the model failed (show the
    // status label), everything was stripped (say so), or normal. In every
    // case the "Also on file" list appends must-surface facts the model
    // skipped, so nothing important is hidden by a bad summary.
    function renderNarration(n) {
        els.narration.innerHTML = '';
        if (n.status) {
            els.narration.appendChild(el('div', { class: 'copilot-alert copilot-error', text: n.status + '. The fact table below is complete and verified.' }));
        } else if (n.total_failure) {
            els.narration.appendChild(el('div', { class: 'copilot-alert copilot-error', text: 'Unable to verify the AI summary for this patient; showing verified chart facts only.' }));
        } else {
            els.narration.appendChild(el('div', { class: 'copilot-label', text: 'AI summary (every sentence cites verified facts) · ' + generatedLabel(n) }));
            if (n.sentences.length === 0) {
                els.narration.appendChild(el('p', { class: 'copilot-muted', text: 'Nothing to summarize.' }));
            }
            n.sentences.forEach(s => els.narration.appendChild(sentenceNode(s)));
            if (n.stripped > 0) {
                els.narration.appendChild(el('div', { class: 'copilot-alert', text: n.stripped + ' unverified sentence' + (n.stripped > 1 ? 's were' : ' was') + ' removed.' }));
            }
        }
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
    function post(fields) {
        const body = new URLSearchParams(Object.assign({ csrf_token_form: csrf }, fields));
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), 30000);
        return fetch(endpoint, { method: 'POST', body, credentials: 'same-origin', signal: controller.signal })
            .then(r => r.json().then(j => ({ ok: r.ok, status: r.status, json: j })))
            .finally(() => clearTimeout(timer));
    }

    function enableAsk(on) {
        els.question.disabled = !on;
        els.button.disabled = !on;
    }

    function addTurn(role, node) {
        const t = el('div', { class: 'copilot-turn copilot-' + role });
        if (typeof node === 'string') t.textContent = node; else t.appendChild(node);
        els.thread.appendChild(t);
    }

    // Initial load (and re-load after a chart change): fetch facts + summary.
    function brief() {
        setStatus('loading…');
        post({ action: 'brief' }).then(({ ok, json }) => {
            if (!ok) {
                setStatus(json.error || 'unavailable', true);
                els.facts.innerHTML = '';
                els.facts.appendChild(el('p', { class: 'copilot-muted', text: json.error || 'The Co-Pilot is unavailable for this chart.' }));
                return;
            }
            renderFacts(json);
            renderNarration(json.narration);
            setStatus('ref ' + json.correlation_id.slice(0, 8));
            enableAsk(true);
        }).catch(err => {
            setStatus(err.name === 'AbortError' ? 'timed out' : 'unavailable', true);
            els.facts.innerHTML = '';
            els.facts.appendChild(el('p', { class: 'copilot-muted', text: 'The Co-Pilot could not be reached. The chart below is unaffected.' }));
        });
    }

    // Follow-up question flow.
    els.form.addEventListener('submit', ev => {
        ev.preventDefault();
        const question = els.question.value.trim();
        if (!question) return;
        enableAsk(false);
        addTurn('user', question);
        els.question.value = '';
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
                    node = el('span', { text: a.status });
                } else if (a.type === 'not_in_facts') {
                    node = el('span', { text: 'Not in the facts for this briefing window. Check the chart tabs for older records.' });
                } else if (a.sentences.length === 0) {
                    node = el('span', { text: a.stripped > 0
                        ? 'The answer was withheld: it contained ' + a.stripped + ' claim' + (a.stripped > 1 ? 's' : '') + ' not supported by the facts on file (for example a computed number). Try asking for the recorded values.'
                        : 'No verifiable answer could be given from the facts on file.' });
                } else {
                    node = el('div');
                    a.sentences.forEach(s => node.appendChild(sentenceNode(s)));
                    if (a.stripped > 0) node.appendChild(el('div', { class: 'copilot-muted', text: a.stripped + ' unverified sentence(s) removed.' }));
                }
                addTurn('assistant', node);
                state.transcript.push({ role: 'user', text: question });
                state.transcript.push({ role: 'assistant', text: node.textContent });
            })
            .catch(() => addTurn('assistant', 'The Co-Pilot could not be reached.'))
            .finally(() => enableAsk(true));
    });

    // Kick off the first briefing as soon as the script runs.
    brief();
    loadDocuments();
})();
