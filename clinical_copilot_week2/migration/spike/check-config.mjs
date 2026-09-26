// Throwaway spike: checks FHIR discovery and records the CORS preflight
// answers. The preflight is expected to fail: API routes are dispatched
// (RoutesExtensionListener, priority 40) before CORSListener (priority 25)
// can answer OPTIONS. Node does not enforce CORS, so Task 4 tests it in a
// real browser. Only the discovery checks decide PASS.
import { fetchText, FHIR_BASE, writeResults } from './lib.mjs';

const checks = [];
const observations = {};
const check = (name, ok, detail = '') => {
  checks.push({ name, ok: Boolean(ok), detail });
  console.log(`${ok ? 'ok  ' : 'FAIL'} ${name} ${detail}`);
};

try {
  const disc = await fetchText(`${FHIR_BASE}/.well-known/smart-configuration`);
  check('discovery HTTP 200', disc.status === 200, `HTTP ${disc.status}`);
  check('discovery is JSON', disc.json !== null);
  check('S256 PKCE advertised', disc.json?.code_challenge_methods_supported?.includes('S256'));
  observations.authorizationEndpoint = disc.json?.authorization_endpoint;
  observations.tokenEndpoint = disc.json?.token_endpoint;
  observations.capabilities = disc.json?.capabilities ?? [];

  for (const origin of ['http://localhost:5174', 'http://localhost:5175', 'https://unrelated.example']) {
    const pre = await fetchText(`${FHIR_BASE}/Patient`, {
      method: 'OPTIONS',
      headers: {
        Origin: origin,
        'Access-Control-Request-Method': 'GET',
        'Access-Control-Request-Headers': 'authorization',
      },
    });
    const allowOrigin = pre.headers.get('access-control-allow-origin');
    const allowHeaders = (pre.headers.get('access-control-allow-headers') ?? '')
      .split(',')
      .map((h) => h.trim().toLowerCase());
    const usable = pre.status >= 200 && pre.status < 300 && allowOrigin === origin && allowHeaders.includes('authorization');
    observations[origin] = {
      status: pre.status,
      usableByABrowser: usable,
      allowOrigin,
      allowHeaders,
      allowMethods: pre.headers.get('access-control-allow-methods'),
      allowCredentials: pre.headers.get('access-control-allow-credentials'),
      bodyStart: pre.text.slice(0, 200),
    };
    console.log(`observed: preflight from ${origin} -> HTTP ${pre.status}, usable by a browser: ${usable}`);
  }
} catch (e) {
  check('requests completed', false, `${e.name}: ${e.message}`);
}

const pass = checks.length > 0 && checks.every((c) => c.ok);
writeResults('config', { pass, checks, observations });
console.log(JSON.stringify(observations, null, 2));
console.log(pass ? 'PASS' : 'FAIL');
process.exitCode = pass ? 0 : 1;
