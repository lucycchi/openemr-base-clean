// Audit helper, not product code: prints one FHIR response from the dev stack.
// Gets a read-only token with the bff client (user/*.rs scopes) by password
// grant, so audits can compare the API with the old dashboard without a browser.
// Usage: NODE_EXTRA_CA_CERTS=dev-cert.pem node fhir-get.mjs '<Resource?query>' [--summary]
//   --summary prints status, total and one line per entry instead of the raw JSON.
import { readEnv, fetchText, OEMR_BASE, FHIR_BASE } from './lib.mjs';

const path = process.argv[2];
if (!path) {
  console.error("usage: fhir-get.mjs '<Resource?query>' [--summary]");
  process.exit(2);
}
const env = readEnv();
for (const k of ['BFF_CLIENT_ID', 'BFF_CLIENT_SECRET', 'BFF_SCOPE', 'OEMR_USER', 'OEMR_PASS']) {
  if (!env[k]) {
    console.error(`FAIL: ${k} missing from .env.local`);
    process.exit(1);
  }
}
const tok = await fetchText(`${OEMR_BASE}/oauth2/default/token`, {
  method: 'POST',
  headers: {
    'Content-Type': 'application/x-www-form-urlencoded',
    Authorization: 'Basic ' + Buffer.from(`${env.BFF_CLIENT_ID}:${env.BFF_CLIENT_SECRET}`).toString('base64'),
  },
  body: new URLSearchParams({
    grant_type: 'password', client_id: env.BFF_CLIENT_ID, user_role: 'users',
    username: env.OEMR_USER, password: env.OEMR_PASS,
    scope: env.BFF_SCOPE.split(' ').filter((s) => s !== 'offline_access').join(' '),
  }),
});
if (tok.status !== 200 || typeof tok.json?.access_token !== 'string') {
  console.error(`FAIL: token HTTP ${tok.status}: ${tok.text.slice(0, 300)}`);
  process.exit(1);
}
const res = await fetchText(`${FHIR_BASE}/${path}`, {
  headers: { Authorization: `Bearer ${tok.json.access_token}`, Accept: 'application/fhir+json' },
});
if (!process.argv.includes('--summary')) {
  console.log(`HTTP ${res.status}`);
  console.log(res.json ? JSON.stringify(res.json, null, 2) : res.text);
} else {
  const entries = res.json?.entry ?? [];
  console.log(`HTTP ${res.status} ${res.json?.resourceType ?? ''} total=${res.json?.total ?? '-'} entries=${entries.length} links=${(res.json?.link ?? []).map((l) => l.relation).join(',')}`);
  for (const e of entries) {
    const r = e.resource ?? {};
    const text = r.code?.text ?? r.code?.coding?.[0]?.display ?? r.medicationCodeableConcept?.text
      ?? r.medicationCodeableConcept?.coding?.[0]?.display ?? r.name?.[0]?.text ?? '';
    const status = r.clinicalStatus?.coding?.[0]?.code ?? r.status ?? '';
    console.log(`- ${r.resourceType} ${r.id} | ${text} | ${status}`);
  }
}
