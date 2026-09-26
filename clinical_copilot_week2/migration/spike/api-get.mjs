// Audit helper, not product code: prints one Standard REST API response from the dev stack.
// Gets a token with the seed client (api:oemr scopes) by password grant, so audits can check what
// the standard API returns next to FHIR.
// Usage: NODE_EXTRA_CA_CERTS=dev-cert.pem node api-get.mjs '<path under /apis/default/api/>'
import { readEnv, fetchText, OEMR_BASE } from './lib.mjs';

const path = process.argv[2];
if (!path) {
  console.error("usage: api-get.mjs '<path, for example patient/<uuid>>'");
  process.exit(2);
}
const env = readEnv();
for (const k of ['SEED_CLIENT_ID', 'SEED_CLIENT_SECRET', 'SEED_SCOPE', 'OEMR_USER', 'OEMR_PASS']) {
  if (!env[k]) {
    console.error(`FAIL: ${k} missing from .env.local`);
    process.exit(1);
  }
}
const tok = await fetchText(`${OEMR_BASE}/oauth2/default/token`, {
  method: 'POST',
  headers: {
    'Content-Type': 'application/x-www-form-urlencoded',
    Authorization: 'Basic ' + Buffer.from(`${env.SEED_CLIENT_ID}:${env.SEED_CLIENT_SECRET}`).toString('base64'),
  },
  body: new URLSearchParams({
    grant_type: 'password', client_id: env.SEED_CLIENT_ID, user_role: 'users',
    username: env.OEMR_USER, password: env.OEMR_PASS, scope: env.SEED_SCOPE,
  }),
});
if (tok.status !== 200 || typeof tok.json?.access_token !== 'string') {
  console.error(`FAIL: token HTTP ${tok.status}: ${tok.text.slice(0, 300)}`);
  process.exit(1);
}
const res = await fetchText(`${OEMR_BASE}/apis/default/api/${path}`, {
  headers: { Authorization: `Bearer ${tok.json.access_token}`, Accept: 'application/json' },
});
console.log(`HTTP ${res.status}`);
console.log(res.json ? JSON.stringify(res.json, null, 2) : res.text);
