/* global __ENV, __VU, __ITER */
// k6 load test for the Clinical Co-Pilot.
//
// Each virtual user is a physician: logs in once, then repeatedly opens a
// seed chart (which sets the session patient and yields the panel's CSRF
// token), requests the briefing, and — depending on the scenario — asks a
// follow-up. Nothing is mocked: the deployed OpenEMR, its database, the
// module's ACL and verifier, and the real model provider are all on the
// path. Responses bodies are not recorded (they contain chart facts).
//
// Usage (see tests/load/README.md):
//   k6 run -e BASE_URL=https://host -e LOGIN_PASS=... -e SCENARIO=mixed -e VUS=10 -e DURATION=2m tests/load/copilot.js
//
// Scenarios (SCENARIO):
//   brief  open chart + brief. After the first pass every brief is a cache hit,
//          so this measures OpenEMR login/session, fact assembly (DB + ACL),
//          verification and the omission guard — the deterministic path.
//   ask    open chart + brief + one follow-up question. Follow-ups are never
//          cached, so every iteration makes one real model call.
//   mixed  open chart + brief, then a follow-up on ASK_SHARE of iterations
//          (default 30%) — the realistic clinic pattern from USERS.md.

import http from 'k6/http';
import { check, sleep } from 'k6';
import { Counter, Rate, Trend } from 'k6/metrics';

const BASE = (__ENV.BASE_URL || 'http://localhost:8300').replace(/\/$/, '');
// Prefixed names: k6 exposes the host environment in __ENV, so USER/PASS would collide.
const USER = __ENV.LOGIN_USER || 'admin';
const PASS = __ENV.LOGIN_PASS || 'pass';
const SCENARIO = __ENV.SCENARIO || 'mixed';
const ASK_SHARE = Number(__ENV.ASK_SHARE || 0.3);
const THINK_SECONDS = Number(__ENV.THINK || 1);
const PIDS = (__ENV.PIDS || '1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,22,23,24,25,26,27,28,29,30')
    .split(',').map((s) => Number(s.trim())).filter((n) => n > 0);
const LABEL = __ENV.LABEL || `${SCENARIO}-${__ENV.VUS || 'x'}vu`;

const MODULE = `${BASE}/interface/modules/custom_modules/oe-module-clinical-copilot/public`;

const QUESTIONS = [
    'Which lab result was out of range and what is its reference range?',
    'What medications were started since the last visit?',
    'Is there any allergy that matches a current medication?',
    'What new problems were recorded?',
    'When was the prior visit and what was it for?',
];

export const options = {
    vus: Number(__ENV.VUS || 10),
    duration: __ENV.DURATION || '2m',
    // Keep the OpenEMR session cookie across iterations (k6 clears it by default).
    noCookiesReset: true,
    // Do not abort on thresholds: this run records a baseline, it does not gate one.
    thresholds: {
        copilot_request_errors: [{ threshold: 'rate<1', abortOnFail: false }],
    },
    summaryTrendStats: ['avg', 'min', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
    discardResponseBodies: false,
    tags: { scenario: SCENARIO },
};

// Per-endpoint latency, so the summary separates "time to verified facts"
// from "time to summary" (KEY_METRICS.md § 3).
const chartOpenMs = new Trend('copilot_chart_open_ms', true);
const briefMs = new Trend('copilot_brief_ms', true);
const briefCacheHitMs = new Trend('copilot_brief_cache_hit_ms', true);
const briefColdMs = new Trend('copilot_brief_cold_ms', true);
const askMs = new Trend('copilot_ask_ms', true);
const loginMs = new Trend('copilot_login_ms', true);
const chartOpenN = new Counter('copilot_chart_open_ms_n');
const briefCacheHitN = new Counter('copilot_brief_cache_hit_ms_n');
const briefColdN = new Counter('copilot_brief_cold_ms_n');
const loginN = new Counter('copilot_login_ms_n');

const requestErrors = new Rate('copilot_request_errors');          // any non-2xx or unparseable copilot response
const summaryUnavailable = new Rate('copilot_summary_unavailable'); // 200 but narration/answer status non-null
const verificationFail = new Rate('copilot_verification_fail');    // total_failure true
const cacheHits = new Rate('copilot_brief_cache_hit');
const briefs = new Counter('copilot_briefs');
const asks = new Counter('copilot_asks');
const modelCalls = new Counter('copilot_model_calls');
const stripped = new Counter('copilot_sentences_stripped');
const kept = new Counter('copilot_sentences_kept');

function login() {
    const t0 = Date.now();
    const page = http.get(`${BASE}/interface/login/login.php?site=default`, { tags: { name: 'login_page' } });
    check(page, { 'login page 200': (r) => r.status === 200 });
    const res = http.post(
        `${BASE}/interface/main/main_screen.php?auth=login&site=default`,
        { authUser: USER, clearPass: PASS, new_login_session_management: '1', languageChoice: '1' },
        { redirects: 0, tags: { name: 'login' } },
    );
    loginMs.add(Date.now() - t0);
    loginN.add(1);
    const ok = check(res, { 'login 302': (r) => r.status === 302 });
    if (!ok) {
        throw new Error(`login failed for VU ${__VU}: HTTP ${res.status}`);
    }
}

function openChart(pid) {
    const res = http.get(`${BASE}/interface/patient_file/summary/demographics.php?set_pid=${pid}`, { tags: { name: 'open_chart' } });
    chartOpenMs.add(res.timings.duration);
    chartOpenN.add(1);
    const m = typeof res.body === 'string' ? res.body.match(/data-csrf="([^"]+)"/) : null;
    check(res, { 'chart 200': (r) => r.status === 200, 'panel present': () => m !== null });
    if (!m && __ENV.DEBUG) {
        console.log(`open_chart pid=${pid} vu=${__VU} iter=${__ITER} status=${res.status} url=${res.url} body=${String(res.body).slice(0, 160).replace(/\s+/g, ' ')}`);
    }
    return m ? m[1] : null;
}

function parse(res) {
    try {
        return JSON.parse(res.body);
    } catch {
        return null;
    }
}

function brief(csrf) {
    const res = http.post(`${MODULE}/chat.php`, { csrf_token_form: csrf, action: 'brief' }, { tags: { name: 'brief' } });
    briefs.add(1);
    briefMs.add(res.timings.duration);
    const body = parse(res);
    const ok = res.status === 200 && body && body.narration;
    requestErrors.add(!ok);
    if (!ok) {
        return null;
    }
    const n = body.narration;
    cacheHits.add(n.from_cache === true);
    (n.from_cache ? briefCacheHitMs : briefColdMs).add(res.timings.duration);
    (n.from_cache ? briefCacheHitN : briefColdN).add(1);
    summaryUnavailable.add(n.status !== null);
    verificationFail.add(n.total_failure === true);
    if (!n.from_cache) {
        modelCalls.add(1);
    }
    stripped.add(n.stripped || 0);
    kept.add((n.sentences || []).length);
    check(res, { 'brief 200': () => true, 'brief verified': () => n.total_failure !== true });
    return body.facts_hash;
}

function ask(csrf, factsHash) {
    const question = QUESTIONS[Math.floor(Math.random() * QUESTIONS.length)];
    const res = http.post(
        `${MODULE}/chat.php`,
        { csrf_token_form: csrf, action: 'ask', facts_hash: factsHash, question, transcript: '[]' },
        { tags: { name: 'ask' } },
    );
    asks.add(1);
    askMs.add(res.timings.duration);
    const body = parse(res);
    const ok = res.status === 200 && body && (body.answer || body.chart_changed === true);
    requestErrors.add(!ok);
    if (!ok || !body.answer) {
        return;
    }
    modelCalls.add(1);
    summaryUnavailable.add(body.answer.status !== null);
    stripped.add(body.answer.stripped || 0);
    kept.add((body.answer.sentences || []).length);
    check(res, { 'ask 200': () => true, 'ask answered or declined': () => body.answer.type !== 'error' });
}

export default function () {
    if (__ITER === 0) {
        login();
    }
    const pid = PIDS[(__VU + __ITER) % PIDS.length];
    const csrf = openChart(pid);
    if (!csrf) {
        requestErrors.add(true);
        sleep(THINK_SECONDS);
        return;
    }
    const factsHash = brief(csrf);
    if (factsHash && (SCENARIO === 'ask' || (SCENARIO === 'mixed' && Math.random() < ASK_SHARE))) {
        ask(csrf, factsHash);
    }
    sleep(THINK_SECONDS);
}

function pct(m, k) {
    return m && m.values && m.values[k] !== undefined ? Math.round(m.values[k]) : null;
}

export function handleSummary(data) {
    const m = data.metrics;
    const row = (name) => ({
        count: m[name] && m[name].values.count !== undefined ? m[name].values.count : (m[`${name}_n`] ? m[`${name}_n`].values.count : null),
        p50: pct(m[name], 'med'),
        p95: pct(m[name], 'p(95)'),
        p99: pct(m[name], 'p(99)'),
        max: pct(m[name], 'max'),
    });
    const rate = (name) => (m[name] ? Number((m[name].values.rate * 100).toFixed(2)) : null);
    const count = (name) => (m[name] ? m[name].values.count : 0);
    const summary = {
        label: LABEL,
        base_url: BASE,
        scenario: SCENARIO,
        vus: options.vus,
        duration: options.duration,
        finished_at: new Date().toISOString(),
        requests_total: count('http_reqs'),
        throughput_rps: m.http_reqs ? Number(m.http_reqs.values.rate.toFixed(2)) : null,
        http_failed_pct: rate('http_req_failed'),
        copilot_request_error_pct: rate('copilot_request_errors'),
        summary_unavailable_pct: rate('copilot_summary_unavailable'),
        verification_fail_pct: rate('copilot_verification_fail'),
        brief_cache_hit_pct: rate('copilot_brief_cache_hit'),
        briefs: count('copilot_briefs'),
        asks: count('copilot_asks'),
        model_calls: count('copilot_model_calls'),
        sentences_kept: count('copilot_sentences_kept'),
        sentences_stripped: count('copilot_sentences_stripped'),
        latency_ms: {
            all_http: { ...row('http_req_duration'), count: count('http_reqs') },
            login: row('copilot_login_ms'),
            chart_open: row('copilot_chart_open_ms'),
            brief: { ...row('copilot_brief_ms'), count: count('copilot_briefs') },
            brief_cache_hit: row('copilot_brief_cache_hit_ms'),
            brief_cold: row('copilot_brief_cold_ms'),
            ask: { ...row('copilot_ask_ms'), count: count('copilot_asks') },
        },
    };
    const lines = [
        `# ${LABEL}  ${summary.finished_at}  ${BASE}`,
        `scenario=${SCENARIO} vus=${options.vus} duration=${options.duration}`,
        `requests=${summary.requests_total} throughput=${summary.throughput_rps} req/s  http_failed=${summary.http_failed_pct}%  copilot_errors=${summary.copilot_request_error_pct}%`,
        `briefs=${summary.briefs} (cache hit ${summary.brief_cache_hit_pct}%)  asks=${summary.asks}  model_calls=${summary.model_calls}  summary_unavailable=${summary.summary_unavailable_pct}%  verification_fail=${summary.verification_fail_pct}%`,
        `sentences kept=${summary.sentences_kept} stripped=${summary.sentences_stripped}`,
        '',
        'endpoint          n      p50     p95     p99     max  (ms)',
    ];
    for (const [k, v] of Object.entries(summary.latency_ms)) {
        lines.push(`${k.padEnd(16)} ${String(v.count).padStart(4)}  ${String(v.p50).padStart(6)}  ${String(v.p95).padStart(6)}  ${String(v.p99).padStart(6)}  ${String(v.max).padStart(6)}`);
    }
    const out = {};
    const dir = __ENV.RESULTS_DIR || 'tests/load/results';
    out[`${dir}/${LABEL}.json`] = JSON.stringify(summary, null, 2) + '\n';
    out[`${dir}/${LABEL}.txt`] = lines.join('\n') + '\n';
    out.stdout = lines.join('\n') + '\n';
    return out;
}
