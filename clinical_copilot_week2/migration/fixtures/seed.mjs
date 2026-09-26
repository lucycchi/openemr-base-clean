// Seeds synthetic test patients on the dev stack through the Standard REST API
// and writes fixture-ids.json. Records the API cannot create are listed in
// TEST-PATIENTS.md under "Manual steps". Dev stack only; no real patient data.
// Usage: NODE_EXTRA_CA_CERTS=../spike/dev-cert.pem node seed.mjs [--fresh]
import { existsSync, writeFileSync } from 'node:fs';
import { readEnv, fetchText, OEMR_BASE, FHIR_BASE } from '../spike/lib.mjs';

const OUT = new URL('fixture-ids.json', import.meta.url);
const PARTIAL = new URL('fixture-ids.partial.json', import.meta.url);
if (existsSync(OUT) && !process.argv.includes('--fresh')) {
  console.error('fixture-ids.json already exists. Use --fresh to create a new, separately named set.');
  process.exit(1);
}
const env = readEnv();
for (const k of ['SEED_CLIENT_ID', 'SEED_CLIENT_SECRET', 'SEED_SCOPE', 'OEMR_USER', 'OEMR_PASS']) {
  if (!env[k]) {
    console.error(`FAIL: ${k} missing from spike/.env.local`);
    process.exit(1);
  }
}
const suffix = process.argv.includes('--fresh') ? `-${Date.now().toString(36)}` : '';
const day = (daysAgo) => new Date(Date.now() - daysAgo * 864e5).toISOString().slice(0, 10);
// The medication endpoint validates dates as Y-m-d H:i:s (src/Services/ListService.php:42-43);
// allergies and problems take Y-m-d.
const withTime = (record) => ({
  ...record,
  ...(record.begdate && { begdate: `${record.begdate} 00:00:00` }),
  ...(record.enddate && { enddate: `${record.enddate} 00:00:00` }),
});
const many = (n, label, start) =>
  Array.from({ length: n }, (_, i) => ({ title: `${label} ${String(i + 1).padStart(2, '0')}`, begdate: day(start + i) }));

const FIXTURES = [
  {
    key: 'TP-TYPICAL',
    patient: { fname: 'Tessa', lname: `Typical${suffix}`, DOB: '1958-03-14', sex: 'Female' },
    allergies: [{ title: 'Penicillin', begdate: day(3000) }, { title: 'Peanuts', begdate: day(2000) }],
    problems: [
      { title: 'Type 2 diabetes mellitus', begdate: day(2500) },
      { title: 'Essential hypertension', begdate: day(1800) },
      { title: 'Hyperlipidaemia', begdate: day(900) },
    ],
    medications: [
      { title: 'Metformin 500 mg', begdate: day(2400) },
      { title: 'Lisinopril 10 mg', begdate: day(1700) },
      { title: 'Atorvastatin 20 mg', begdate: day(800) },
    ],
  },
  { key: 'TP-EMPTY', patient: { fname: 'Evan', lname: `Empty${suffix}`, DOB: '1990-07-01', sex: 'Male' } },
  { key: 'TP-NKA', patient: { fname: 'Nora', lname: `NoKnownAllergies${suffix}`, DOB: '1975-11-23', sex: 'Female' } },
  {
    key: 'TP-HISTORY',
    patient: { fname: 'Hugo', lname: `History${suffix}`, DOB: '1949-02-02', sex: 'Male' },
    allergies: [{ title: 'Sulfa drugs', begdate: day(4000), enddate: day(1000) }],
    problems: [{ title: 'Community-acquired pneumonia', begdate: day(700), enddate: day(650) }],
    medications: [{ title: 'Amoxicillin 500 mg', begdate: day(700), enddate: day(690) }],
  },
  { key: 'TP-DECEASED', patient: { fname: 'Dora', lname: `Deceased${suffix}`, DOB: '1932-05-09', sex: 'Female' } },
  {
    key: 'TP-LONG',
    patient: { fname: 'Lena', lname: `LongLists${suffix}`, DOB: '1944-09-30', sex: 'Female' },
    allergies: many(25, 'Long-list allergen', 100),
    problems: many(60, 'Long-list problem', 200),
    medications: many(60, 'Long-list medication', 300),
  },
  {
    key: 'TP-ESCAPING',
    patient: { fname: 'Zoë', lname: `O'Brien-Núñez${suffix}`, DOB: '1988-12-12', sex: 'Female' },
    allergies: [{ title: 'Latex <b>x</b>', begdate: day(100) }],
  },
];

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
  console.error(`FAIL: password grant returned HTTP ${tokenRes.status}: ${tokenRes.text.slice(0, 500)}`);
  process.exit(1);
}
const token = tokenRes.json.access_token;

async function api(method, path, body) {
  const res = await fetchText(`${OEMR_BASE}/apis/default/api${path}`, {
    method,
    headers: { Authorization: `Bearer ${token}`, 'Content-Type': 'application/json', Accept: 'application/json' },
    body: body === undefined ? undefined : JSON.stringify(body),
  });
  if (res.status < 200 || res.status >= 300) {
    throw new Error(`${method} ${path} returned HTTP ${res.status}: ${res.text.slice(0, 500)}`);
  }
  return res.json;
}

const out = { generatedAt: new Date().toISOString(), patients: {} };
try {
  for (const fx of FIXTURES) {
    const created = await api('POST', '/patient', fx.patient);
    const puuid = created?.data?.uuid;
    let pid = created?.data?.pid;
    if (!puuid) throw new Error(`${fx.key}: patient create returned no uuid: ${JSON.stringify(created).slice(0, 300)}`);
    if (!pid) pid = (await api('GET', `/patient/${puuid}`))?.data?.pid;
    if (!pid) throw new Error(`${fx.key}: could not read the numeric pid`);
    const entry = { pid, puuid, fhirId: null, created: { allergies: 0, problems: 0, medications: 0 } };
    out.patients[fx.key] = entry;
    for (const a of fx.allergies ?? []) { await api('POST', `/patient/${puuid}/allergy`, a); entry.created.allergies++; }
    for (const p of fx.problems ?? []) { await api('POST', `/patient/${puuid}/medical_problem`, p); entry.created.problems++; }
    for (const m of fx.medications ?? []) { await api('POST', `/patient/${pid}/medication`, withTime(m)); entry.created.medications++; }
    const fhir = await fetchText(`${FHIR_BASE}/Patient/${puuid}`, {
      headers: { Authorization: `Bearer ${token}`, Accept: 'application/fhir+json' },
    });
    if (fhir.status !== 200 || fhir.json?.id !== puuid) {
      throw new Error(`${fx.key}: FHIR Patient/${puuid} returned HTTP ${fhir.status}; the uuid is not the FHIR id`);
    }
    entry.fhirId = fhir.json.id;
    console.log(`ok ${fx.key}: pid ${pid}, ${JSON.stringify(entry.created)}`);
  }
} catch (e) {
  writeFileSync(PARTIAL, JSON.stringify(out, null, 2) + '\n');
  console.error(`FAIL: ${e.message}`);
  console.error('Patients created so far are listed in fixture-ids.partial.json.');
  process.exit(1);
}
writeFileSync(OUT, JSON.stringify(out, null, 2) + '\n');
console.log(`PASS: seeded ${Object.keys(out.patients).length} patients`);
