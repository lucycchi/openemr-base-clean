// Throwaway spike: registers one OAuth client and saves its id (and secret,
// for confidential clients) to .env.local, which is gitignored.
// Usage: NODE_EXTRA_CA_CERTS=dev-cert.pem node register-client.mjs <public|bff|app|seed|names> [--replace]
// Droplet: OEMR_BASE=https://146-190-139-37.sslip.io SPIKE_ENV_FILE=.env.droplet node register-client.mjs <kind>
//          (plus APP_REDIRECT for app, NAMES_KEY_FILE for names)
// `app` is the patient-dashboard BFF's own client (redirect http://localhost:5180/auth/callback).
// `names` is the BFF's server-only system client for staff and facility names (Fable review F1). It
// authenticates with a signed JWT: the RSA key is created in patient-dashboard/certs/ (gitignored) if
// missing, and only its public half is registered.
import { createHash, createPublicKey, generateKeyPairSync } from 'node:crypto';
import { existsSync, readFileSync, writeFileSync } from 'node:fs';
import { readEnv, writeEnv, fetchText, OEMR_BASE } from './lib.mjs';

// NAMES_KEY_FILE picks another key, for example certs/names-client-key.droplet.pem (certs/ is gitignored).
const NAMES_KEY_FILE = new URL(`../../../patient-dashboard/${process.env.NAMES_KEY_FILE ?? 'certs/names-client-key.pem'}`, import.meta.url);

/** Same key id as the BFF's publicJwks (server/systemToken.ts): the RFC 7638 thumbprint. */
function namesJwks() {
  if (!existsSync(NAMES_KEY_FILE)) {
    const { privateKey } = generateKeyPairSync('rsa', { modulusLength: 2048 });
    writeFileSync(NAMES_KEY_FILE, privateKey.export({ type: 'pkcs8', format: 'pem' }), { mode: 0o600 });
    console.log(`created ${NAMES_KEY_FILE.pathname}`);
  }
  const jwk = createPublicKey(readFileSync(NAMES_KEY_FILE, 'utf8')).export({ format: 'jwk' });
  const kid = createHash('sha256').update(JSON.stringify({ e: jwk.e, kty: jwk.kty, n: jwk.n })).digest('base64url');
  return { keys: [{ kty: jwk.kty, n: jwk.n, e: jwk.e, kid, alg: 'RS384', use: 'sig' }] };
}

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
    // APP_REDIRECT for a deployed dashboard, for example https://dashboard.146-190-139-37.sslip.io/auth/callback.
    redirect: process.env.APP_REDIRECT ?? 'http://localhost:5180/auth/callback',
    // api:oemr, patient.rs, medication.rs, allergy.rs and medical_problem.rs: the standard API is read for the medication
    // and allergy lists' end dates and outcome, and for the problem list itself, which FHIR does not send correctly (BM-044, BM-047, BM-051;
    // user decisions 2026-09-26).
    scope: ['openid', 'fhirUser', 'offline_access', 'api:fhir', ...READ.map((r) => `user/${r}.rs`),
      'api:oemr', 'user/patient.rs', 'user/medication.rs', 'user/allergy.rs', 'user/medical_problem.rs',
      // Prescriptions are added and discontinued from the dashboard (ARC-06); OpenEMR has no prescription update.
      'user/prescription.crds'],
  },
  names: {
    type: 'private',
    redirect: 'http://localhost:5180/unused',
    scope: ['system/Practitioner.rs', 'system/Organization.rs'],
    jwks: true,
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
  console.error('usage: register-client.mjs <public|bff|app|seed|names> [--replace]');
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
  ...(spec.jwks ? { jwks: namesJwks(), token_endpoint_auth_method: 'private_key_jwt', grant_types: ['client_credentials'] } : {}),
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
