// Adds TP-RXEDIT ("Rita RxEdit"), the one patient the prescription write tests may change (ARC-06), so the
// parity patients are never written to. Safe to re-run: an existing Rita RxEdit is reused, not duplicated.
// Usage (dev): NODE_EXTRA_CA_CERTS=../spike/dev-cert.pem node seed-rxedit.mjs
// Droplet: OEMR_BASE=https://146-190-139-37.sslip.io SPIKE_ENV_FILE=.env.droplet FIXTURE_IDS_FILE=fixture-ids.droplet.json node seed-rxedit.mjs
import { readFileSync, writeFileSync } from 'node:fs';
import { readEnv, fetchText, OEMR_BASE } from '../spike/lib.mjs';

const FILE = new URL(process.env.FIXTURE_IDS_FILE ?? 'fixture-ids.json', import.meta.url);
const ids = JSON.parse(readFileSync(FILE, 'utf8'));
const env = readEnv();
for (const k of ['SEED_CLIENT_ID', 'SEED_CLIENT_SECRET', 'SEED_SCOPE', 'OEMR_USER', 'OEMR_PASS']) {
  if (!env[k]) {
    console.error(`FAIL: ${k} missing from the spike env file`);
    process.exit(1);
  }
}

// The same password grant seed.mjs uses (seed.mjs:75-94), with the seed client.
const tokenRes = await fetchText(`${OEMR_BASE}/oauth2/default/token`, {
  method: 'POST',
  headers: {
    'Content-Type': 'application/x-www-form-urlencoded',
    Authorization: 'Basic ' + Buffer.from(`${env.SEED_CLIENT_ID}:${env.SEED_CLIENT_SECRET}`).toString('base64'),
  },
  body: new URLSearchParams({
    grant_type: 'password',
    client_id: env.SEED_CLIENT_ID,
    user_role: 'users',
    username: env.OEMR_USER,
    password: env.OEMR_PASS,
    scope: env.SEED_SCOPE,
  }),
});
if (tokenRes.status !== 200 || typeof tokenRes.json?.access_token !== 'string') {
  console.error(`FAIL: password grant returned HTTP ${tokenRes.status}`);
  process.exit(1);
}
const headers = {
  Authorization: `Bearer ${tokenRes.json.access_token}`,
  'Content-Type': 'application/json',
  Accept: 'application/json',
};

async function api(method, path, body) {
  const res = await fetchText(`${OEMR_BASE}/apis/default/api${path}`, {
    method,
    headers,
    body: body === undefined ? undefined : JSON.stringify(body),
  });
  if (res.status < 200 || res.status >= 300) {
    throw new Error(`${method} ${path} returned HTTP ${res.status}: ${res.text.slice(0, 300)}`);
  }
  return res.json;
}

// Reuse Rita RxEdit if she exists; otherwise create her.
const found = await api('GET', '/patient?fname=Rita&lname=RxEdit');
let puuid = (Array.isArray(found?.data) ? found.data : [])[0]?.uuid;
if (!puuid) {
  const created = await api('POST', '/patient', { fname: 'Rita', lname: 'RxEdit', DOB: '1970-01-01', sex: 'Female' });
  puuid = created?.data?.uuid;
}
if (!puuid) {
  console.error('FAIL: no patient uuid for Rita RxEdit');
  process.exit(1);
}
const pid = Number((await api('GET', `/patient/${puuid}`))?.data?.pid);
if (!Number.isInteger(pid) || pid <= 0) {
  console.error('FAIL: could not read the numeric pid');
  process.exit(1);
}
ids.patients['TP-RXEDIT'] = { pid, puuid, fhirId: puuid, created: {} };
writeFileSync(FILE, `${JSON.stringify(ids, null, 2)}\n`);
console.log(`TP-RXEDIT is pid ${pid}`);
