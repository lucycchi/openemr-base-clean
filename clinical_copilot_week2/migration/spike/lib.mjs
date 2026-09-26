// Shared helpers for the throwaway auth spikes and the fixture seeder.
// Not product code. Run every script with NODE_EXTRA_CA_CERTS pointing at
// spike/dev-cert.pem; TLS verification stays on.
import { readFileSync, writeFileSync, existsSync } from 'node:fs';

const SPIKE_DIR = new URL('./', import.meta.url);
const ENV_FILE = new URL('.env.local', SPIKE_DIR);

export const OEMR_BASE = process.env.OEMR_BASE ?? 'https://localhost:9300';
export const FHIR_BASE = `${OEMR_BASE}/apis/default/fhir`;

export function readEnv() {
  if (!existsSync(ENV_FILE)) return {};
  return Object.fromEntries(
    readFileSync(ENV_FILE, 'utf8')
      .split('\n')
      .filter((line) => line.includes('='))
      .map((line) => {
        const i = line.indexOf('=');
        return [line.slice(0, i).trim(), line.slice(i + 1).trim()];
      }),
  );
}

export function writeEnv(env) {
  const body = Object.entries(env).map(([k, v]) => `${k}=${v}`).join('\n') + '\n';
  writeFileSync(ENV_FILE, body, { mode: 0o600 });
}

// Returns the response whatever its status. Network failures and timeouts throw.
export async function fetchText(url, init = {}, timeoutMs = 15000) {
  const res = await fetch(url, { ...init, signal: AbortSignal.timeout(timeoutMs) });
  const text = await res.text();
  let json = null;
  try {
    json = JSON.parse(text);
  } catch {
    json = null;
  }
  return { status: res.status, headers: res.headers, text, json };
}

export function writeResults(name, results) {
  writeFileSync(new URL(`results-${name}.json`, SPIKE_DIR), JSON.stringify(results, null, 2) + '\n');
}
