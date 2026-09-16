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
(function () {
    'use strict';

    const panel = document.getElementById('copilot-panel');
    if (!panel) {
        return;
    }
    const endpoint = panel.dataset.endpoint;
    const csrf = panel.dataset.csrf;
    const els = {
        status: document.getElementById('copilot-status'),
        narration: document.getElementById('copilot-narration'),
        facts: document.getElementById('copilot-facts'),
        form: document.getElementById('copilot-ask'),
        question: document.getElementById('copilot-question'),
        button: document.querySelector('#copilot-ask button'),
        thread: document.getElementById('copilot-thread'),
    };

    const CATEGORY_LABELS = {
        allergy_medication_hit: 'Allergy / medication matches',
        lab_abnormal: 'Abnormal labs since last visit',
        medication_new: 'New medications',
        medication_changed: 'Changed medications',
        allergy_new: 'New allergies',
        problem_new: 'New problems',
        encounter: 'Visits since last visit',
        lab_delta: 'Lab changes vs prior result',
        medication_active: 'Active medications',
        allergy_active: 'Allergies on file',
        truncation: 'Not shown',
    };
    const CATEGORY_ORDER = Object.keys(CATEGORY_LABELS);

    const state = { factsHash: null, factsById: {}, transcript: [] };

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
                ul.appendChild(el('li', { class: f.must_surface ? 'copilot-must' : '', 'data-fact': f.id }, [f.value, chip(f.id)]));
            });
            els.facts.appendChild(ul);
        });
    }

    function sentenceNode(s) {
        const p = el('p');
        p.appendChild(document.createTextNode(s.text.replace(/\s*\[[0-9a-f]{8}(?:\s*,\s*[0-9a-f]{8})*\]/g, '') + ' '));
        s.fact_ids.forEach(id => p.appendChild(chip(id, 'copilot-chip-ref')));
        return p;
    }

    function renderNarration(n) {
        els.narration.innerHTML = '';
        if (n.status) {
            els.narration.appendChild(el('div', { class: 'copilot-alert copilot-error', text: n.status + '. The fact table below is complete and verified.' }));
        } else if (n.total_failure) {
            els.narration.appendChild(el('div', { class: 'copilot-alert copilot-error', text: 'Unable to verify the AI summary for this patient; showing verified chart facts only.' }));
        } else {
            els.narration.appendChild(el('div', { class: 'copilot-label', text: 'AI summary (every sentence cites verified facts)' + (n.from_cache ? ' · cached' : '') }));
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

    function post(fields) {
        const body = new URLSearchParams(Object.assign({ csrf_token_form: csrf }, fields));
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), 20000);
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

    els.form.addEventListener('submit', ev => {
        ev.preventDefault();
        const question = els.question.value.trim();
        if (!question) return;
        enableAsk(false);
        addTurn('user', question);
        els.question.value = '';
        post({ action: 'ask', question, facts_hash: state.factsHash, transcript: JSON.stringify(state.transcript) })
            .then(({ ok, json }) => {
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

    brief();
})();
