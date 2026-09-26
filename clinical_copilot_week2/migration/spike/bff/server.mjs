// Throwaway spike, not product code: a minimal backend-for-frontend on
// http://localhost:5175. It holds a confidential client, keeps tokens in
// server memory and gives the browser only an HttpOnly session cookie.
// Usage: NODE_EXTRA_CA_CERTS=dev-cert.pem node bff/server.mjs   (run from the spike directory)
import { createServer } from 'node:http';
import { randomBytes, createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { readEnv, fetchText, writeResults, OEMR_BASE, FHIR_BASE } from '../lib.mjs';

const PORT = 5175;
const ORIGIN = `http://localhost:${PORT}`;
const env = readEnv();
for (const k of ['BFF_CLIENT_ID', 'BFF_CLIENT_SECRET', 'BFF_SCOPE']) {
  if (!env[k]) {
    console.error(`FAIL: ${k} missing; run register-client.mjs bff`);
    process.exit(1);
  }
}
const fixtures = JSON.parse(readFileSync(new URL('../../fixtures/fixture-ids.json', import.meta.url), 'utf8')).patients;
const fhirIds = Object.fromEntries(Object.entries(fixtures).map(([key, v]) => [key, v.fhirId]));
const ALLOWED = new Set(['Patient', 'AllergyIntolerance', 'Condition', 'MedicationRequest', 'CareTeam', 'Procedure']);
const BASIC = 'Basic ' + Buffer.from(`${env.BFF_CLIENT_ID}:${env.BFF_CLIENT_SECRET}`).toString('base64');
const sessions = new Map();

const PAGE = `<!doctype html><meta charset="utf-8"><title>Spike B: BFF</title>
<h1>Spike B: backend-for-frontend</h1>
<p><a href="/login">1. Log in</a> &nbsp; <button id="run">2. Run checks</button></p><pre id="out"></pre>
<script type="module">
const out = document.getElementById('out');
const checks = [];
const check = (name, ok, detail = '') => { checks.push({ name, ok: Boolean(ok), detail }); out.textContent += (ok ? 'ok   ' : 'FAIL ') + name + ' ' + detail + '\\n'; };
const get = async (path) => { const r = await fetch('/api/fhir/' + path); let j = null; try { j = await r.json(); } catch {} return { status: r.status, json: j }; };
document.getElementById('run').onclick = async () => {
  const ids = await (await fetch('/fixtures')).json();
  for (const key of ['TP-TYPICAL', 'TP-HISTORY']) {
    const id = ids[key];
    const p = await get('Patient/' + id);
    check(key + ': Patient read', p.status === 200 && p.json?.id === id, 'HTTP ' + p.status);
    const a = await get('AllergyIntolerance?patient=' + id);
    const e = a.json?.entry ?? [];
    check(key + ': allergies belong to this patient', a.status === 200 && e.length > 0 && e.every((x) => (x.resource?.patient?.reference ?? '').endsWith('Patient/' + id)), e.length + ' entries');
  }
  const oos = await get('Procedure?patient=' + ids['TP-TYPICAL']);
  check('out-of-scope read refused', oos.status === 401 || oos.status === 403, 'HTTP ' + oos.status);
  const pass = checks.length > 0 && checks.every((c) => c.ok);
  out.textContent += (pass ? 'PAGE PASS' : 'PAGE FAIL') + '\\n';
  await fetch('/results', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ pass, checks }) });
};
</script>`;

const sidOf = (req) => /(?:^|;\s*)sid=([A-Za-z0-9_-]+)/.exec(req.headers.cookie ?? '')?.[1];
const send = (res, status, type, body, extra = {}) => res.writeHead(status, { 'Content-Type': type, ...extra }).end(body);

const server = createServer(async (req, res) => {
  try {
    const url = new URL(req.url, ORIGIN);
    const sid = sidOf(req);
    const session = sid ? sessions.get(sid) : undefined;

    if (req.method === 'GET' && url.pathname === '/') return send(res, 200, 'text/html; charset=utf-8', PAGE);
    if (req.method === 'GET' && url.pathname === '/fixtures') return send(res, 200, 'application/json', JSON.stringify(fhirIds));

    if (req.method === 'GET' && url.pathname === '/login') {
      const newSid = randomBytes(24).toString('base64url');
      const verifier = randomBytes(32).toString('base64url');
      const state = randomBytes(16).toString('base64url');
      sessions.set(newSid, { state, verifier });
      const auth = new URL(`${OEMR_BASE}/oauth2/default/authorize`);
      auth.search = new URLSearchParams({
        response_type: 'code', client_id: env.BFF_CLIENT_ID, redirect_uri: `${ORIGIN}/callback`, scope: env.BFF_SCOPE,
        state, code_challenge: createHash('sha256').update(verifier).digest('base64url'), code_challenge_method: 'S256', aud: FHIR_BASE,
      }).toString();
      return send(res, 302, 'text/plain', '', { Location: auth.toString(), 'Set-Cookie': `sid=${newSid}; HttpOnly; SameSite=Lax; Path=/` });
    }

    if (req.method === 'GET' && url.pathname === '/callback') {
      if (!session?.state) return send(res, 400, 'text/plain', 'No login in progress. Start again at /login.');
      const { state, verifier } = session;
      session.state = undefined;
      if (url.searchParams.get('error')) return send(res, 400, 'text/plain', `Authorization failed: ${url.searchParams.get('error')}`);
      if (url.searchParams.get('state') !== state || !url.searchParams.get('code')) return send(res, 400, 'text/plain', 'State mismatch or missing code.');
      const tok = await fetchText(`${OEMR_BASE}/oauth2/default/token`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', Authorization: BASIC },
        body: new URLSearchParams({ grant_type: 'authorization_code', code: url.searchParams.get('code'), redirect_uri: `${ORIGIN}/callback`, code_verifier: verifier }),
      });
      const granted = (tok.json?.scope ?? '').split(' ');
      session.serverChecks = [
        { name: 'token endpoint HTTP 200', ok: tok.status === 200, detail: `HTTP ${tok.status}${tok.status === 200 ? '' : ': ' + tok.text.slice(0, 300)}` },
        { name: 'every requested scope granted', ok: env.BFF_SCOPE.split(' ').every((s) => granted.includes(s)), detail: `granted: ${tok.json?.scope ?? ''}` },
      ];
      session.info = { expiresIn: tok.json?.expires_in, refreshTokenIssued: typeof tok.json?.refresh_token === 'string' };
      session.token = typeof tok.json?.access_token === 'string' ? tok.json.access_token : undefined;
      return send(res, 302, 'text/plain', '', { Location: '/' });
    }

    if (req.method === 'GET' && url.pathname.startsWith('/api/fhir/')) {
      if (!session?.token) return send(res, 401, 'application/json', '{"error":"not logged in"}');
      const rest = url.pathname.slice('/api/fhir/'.length);
      if (!ALLOWED.has(rest.split('/')[0])) return send(res, 400, 'application/json', '{"error":"resource not allowed by the spike proxy"}');
      const upstream = await fetchText(`${FHIR_BASE}/${rest}${url.search}`, { headers: { Authorization: `Bearer ${session.token}`, Accept: 'application/fhir+json' } });
      return send(res, upstream.status, 'application/json', upstream.text);
    }

    if (req.method === 'POST' && url.pathname === '/results') {
      let body = '';
      for await (const chunk of req) {
        body += chunk;
        if (body.length > 1e6) return send(res, 413, 'text/plain', '');
      }
      const page = JSON.parse(body);
      const bad = await fetchText(`${FHIR_BASE}/Patient`, { headers: { Authorization: 'Bearer not-a-real-token' } });
      const serverChecks = [
        ...(session?.serverChecks ?? [{ name: 'logged in', ok: false, detail: 'no session; log in first' }]),
        { name: 'bad token refused', ok: bad.status === 401, detail: `HTTP ${bad.status}` },
      ];
      const pass = page.pass === true && serverChecks.every((c) => c.ok);
      writeResults('bff', { pass, pageChecks: page.checks, serverChecks, info: { ...(session?.info ?? {}), badTokenBody: bad.text.slice(0, 300) } });
      console.log(pass ? 'PASS' : 'FAIL', '(results-bff.json written)');
      return send(res, 204, 'text/plain', '');
    }

    return send(res, 404, 'text/plain', 'not found');
  } catch (e) {
    console.error('spike server error:', e);
    if (!res.headersSent) send(res, 500, 'text/plain', 'spike server error; see the terminal');
  }
});
server.on('error', (e) => {
  console.error(`FAIL: cannot listen on port ${PORT}: ${e.message}`);
  process.exit(1);
});
server.listen(PORT, '127.0.0.1', () => console.log(`Open ${ORIGIN} in a browser.`));
