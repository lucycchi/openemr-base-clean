// Seeds synthetic encounters for the Encounter history audit (Task 15) through
// the Standard REST API, and records their ids in encounter-ids.json.
// Dev stack only. Usage: NODE_EXTRA_CA_CERTS=../spike/dev-cert.pem node seed-encounters.mjs
import { existsSync, readFileSync, writeFileSync } from 'node:fs';
import { readEnv, fetchText, OEMR_BASE } from '../spike/lib.mjs';

const OUT = new URL('encounter-ids.json', import.meta.url);
if (existsSync(OUT)) {
  console.error('encounter-ids.json already exists; the encounters are already seeded.');
  process.exit(1);
}
const env = readEnv();
const patients = JSON.parse(readFileSync(new URL('fixture-ids.json', import.meta.url), 'utf8')).patients;

// Facility 3 "Great Clinic"; provider 6 Donna Lee (NPI set for this audit), provider 5 Fred Stone (no NPI).
// pc_catid: 5 Office Visit, 9 Established Patient, 10 New Patient, 13 Preventive Care Services.
const visit = (date, reason, pc_catid, provider_id) => ({
  date, reason, pc_catid, provider_id, class_code: 'AMB', facility_id: 3, billing_facility: 3, sensitivity: 'normal',
});
const PLAN = {
  'TP-TYPICAL': [
    visit('2026-08-14', 'Diabetes review', '5', 6),
    visit('2026-03-02', 'Blood pressure check', '9', 5),
    visit('2025-11-20', 'Annual physical', '13', 6),
  ],
  'TP-HISTORY': [visit('2024-10-26', 'Cough and fever', '10', 6)],
  'TP-LONG': Array.from({ length: 30 }, (_, i) => {
    const d = new Date(Date.UTC(2026, 8, 1 - i * 30));
    return visit(d.toISOString().slice(0, 10), `Long-list visit ${String(i + 1).padStart(2, '0')}`, '9', 6);
  }),
};

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
  console.error(`FAIL: password grant HTTP ${tok.status}: ${tok.text.slice(0, 300)}`);
  process.exit(1);
}

const out = {};
for (const [key, visits] of Object.entries(PLAN)) {
  out[key] = [];
  for (const v of visits) {
    const res = await fetchText(`${OEMR_BASE}/apis/default/api/patient/${patients[key].puuid}/encounter`, {
      method: 'POST',
      headers: { Authorization: `Bearer ${tok.json.access_token}`, 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify(v),
    });
    const data = res.json?.data;
    if (res.status < 200 || res.status >= 300 || !data) {
      writeFileSync(OUT, JSON.stringify(out, null, 2) + '\n');
      console.error(`FAIL: ${key} ${v.date}: HTTP ${res.status}: ${res.text.slice(0, 400)}`);
      process.exit(1);
    }
    out[key].push({ date: v.date, reason: v.reason, encounter: data.encounter ?? data.eid ?? null, uuid: data.uuid ?? null });
  }
  console.log(`ok ${key}: ${out[key].length} encounters`);
}
writeFileSync(OUT, JSON.stringify(out, null, 2) + '\n');
console.log('PASS: encounters seeded');
