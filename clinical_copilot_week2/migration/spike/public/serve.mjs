// Throwaway spike server for Spike A on http://127.0.0.1:5174. Serves the page,
// forwards /apis/ to OpenEMR so the page can call the API from its own origin,
// and saves the page's results to results-public.json. It passes the page's
// Authorization header through but never stores or logs it.
// Usage: NODE_EXTRA_CA_CERTS=dev-cert.pem node public/serve.mjs   (run from the spike directory)
import { createServer } from 'node:http';
import { readFileSync } from 'node:fs';
import { readEnv, writeResults, fetchText, OEMR_BASE } from '../lib.mjs';

const PORT = 5174;
const env = readEnv();
if (!env.PUBLIC_CLIENT_ID || !env.PUBLIC_SCOPE) {
  console.error('FAIL: PUBLIC_CLIENT_ID or PUBLIC_SCOPE missing; run register-client.mjs public');
  process.exit(1);
}
const ids = JSON.parse(readFileSync(new URL('../../fixtures/fixture-ids.json', import.meta.url), 'utf8')).patients;
const config = JSON.stringify({
  base: OEMR_BASE,
  clientId: env.PUBLIC_CLIENT_ID,
  scope: env.PUBLIC_SCOPE,
  expectedPatientId: ids['TP-TYPICAL'].fhirId,
  otherPatientId: ids['TP-HISTORY'].fhirId,
});
const page = readFileSync(new URL('index.html', import.meta.url));

async function readBody(req) {
  let body = '';
  for await (const chunk of req) {
    body += chunk;
    if (body.length > 1e6) throw new Error('body too large');
  }
  return body;
}

const server = createServer(async (req, res) => {
  try {
    const url = new URL(req.url, `http://localhost:${PORT}`);
    if (req.method === 'GET' && (url.pathname === '/' || url.pathname === '/callback')) {
      res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' }).end(page);
      return;
    }
    if (req.method === 'GET' && url.pathname === '/config.json') {
      res.writeHead(200, { 'Content-Type': 'application/json' }).end(config);
      return;
    }
    if (req.method === 'GET' && url.pathname.startsWith('/apis/default/fhir/')) {
      const headers = { Accept: 'application/fhir+json' };
      if (req.headers.authorization) headers.Authorization = req.headers.authorization;
      const upstream = await fetchText(`${OEMR_BASE}${url.pathname}${url.search}`, { headers });
      res.writeHead(upstream.status, { 'Content-Type': 'application/json' }).end(upstream.text);
      return;
    }
    if (req.method === 'POST' && url.pathname === '/results') {
      const results = JSON.parse(await readBody(req));
      writeResults('public', results);
      console.log(results.pass ? 'PASS' : 'FAIL', `(proxied); direct: ${results.directPass ? 'PASS' : 'FAIL'}; results-public.json written`);
      res.writeHead(204).end();
      return;
    }
    res.writeHead(404).end();
  } catch (e) {
    console.error('spike server error:', e.message);
    if (!res.headersSent) res.writeHead(500).end();
  }
});
server.on('error', (e) => {
  console.error(`FAIL: cannot listen on port ${PORT}: ${e.message}`);
  process.exit(1);
});
server.listen(PORT, '127.0.0.1', () => console.log(`Open http://localhost:${PORT} in the browser that trusts dev-cert.pem.`));
