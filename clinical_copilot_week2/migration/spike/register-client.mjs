// Throwaway spike: registers one OAuth client and saves its id (and secret,
// for confidential clients) to .env.local, which is gitignored.
// Usage: NODE_EXTRA_CA_CERTS=dev-cert.pem node register-client.mjs <public|bff|app|seed> [--replace]
// `app` is the patient-dashboard BFF's own client (redirect http://localhost:5180/auth/callback).
import { readEnv, writeEnv, fetchText, OEMR_BASE } from './lib.mjs';

const READ = ['Patient', 'AllergyIntolerance', 'Condition', 'MedicationRequest', 'CareTeam', 'Practitioner',
  'Organization', 'RelatedPerson', 'Encounter', 'Observation', 'Immunization', 'DocumentReference', 'DiagnosticReport'];

const KINDS = {
  public: {
    type: 'public',
    redirect: 'http://localhost:5174/callback',
    scope: ['openid', 'fhirUser', 'api:fhir', 'launch/patient', ...READ.map((r) => `patient/${r}.rs`)],
  },
  bff: {
    type: 'private',
    redirect: 'http://localhost:5175/callback',
    scope: ['openid', 'fhirUser', 'offline_access', 'api:fhir', ...READ.map((r) => `user/${r}.rs`)],
  },
  app: {
    type: 'private',
    redirect: 'http://localhost:5180/auth/callback',
    scope: ['openid', 'fhirUser', 'offline_access', 'api:fhir', ...READ.map((r) => `user/${r}.rs`)],
  },
  seed: {
    type: 'private',
    redirect: 'http://localhost:5176/unused',
    scope: ['openid', 'api:oemr', 'api:fhir', 'user/Patient.rs', 'user/patient.crus', 'user/allergy.cruds',
      'user/medical_problem.cruds', 'user/medication.cruds', 'user/encounter.crus', 'user/vital.crus'],
  },
};

const kind = process.argv[2];
const spec = KINDS[kind];
if (!spec) {
  console.error('usage: register-client.mjs <public|bff|app|seed> [--replace]');
  process.exit(2);
}
const prefix = kind.toUpperCase();
const env = readEnv();
if (env[`${prefix}_CLIENT_ID`] && !process.argv.includes('--replace')) {
  console.error(`${prefix}_CLIENT_ID is already set. Re-run with --replace, then disable the old client under API Clients.`);
  process.exit(1);
}

const body = {
  application_type: spec.type,
  client_name: `Patient Dashboard Spike (${kind})`,
  redirect_uris: [spec.redirect],
  scope: spec.scope.join(' '),
};

let res;
try {
  res = await fetchText(`${OEMR_BASE}/oauth2/default/registration`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  });
} catch (e) {
  console.error(`FAIL: registration request failed: ${e.name}: ${e.message}`);
  process.exit(1);
}
if ((res.status !== 200 && res.status !== 201) || typeof res.json?.client_id !== 'string') {
  console.error(`FAIL: registration returned HTTP ${res.status}`);
  console.error(res.text.slice(0, 1000));
  process.exit(1);
}
if (spec.type === 'private' && typeof res.json.client_secret !== 'string') {
  console.error('FAIL: confidential client registered without a client_secret');
  process.exit(1);
}

env[`${prefix}_CLIENT_ID`] = res.json.client_id;
env[`${prefix}_SCOPE`] = body.scope;
if (spec.type === 'private') env[`${prefix}_CLIENT_SECRET`] = res.json.client_secret;
writeEnv(env);
console.log(`PASS: registered the ${kind} client (HTTP ${res.status}).`);
console.log(`Returned fields: ${Object.keys(res.json).sort().join(', ')}`);
console.log('Now open the API Clients admin page and record whether this client is enabled.');
