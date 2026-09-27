/**
 * The BFF's own settings, read once when the server starts.
 *
 * Runs on the server. In: environment variables (named values set by Docker or the .env file, for example
 * OEMR_BASE, the address of OpenEMR, and OEMR_CLIENT_SECRET, the dashboard's OpenEMR password). Out: one
 * tidy BffConfig object that index.ts hands to the other parts. If a required value is missing or
 * nonsense, the server refuses to start, so a mistake shows up at once rather than halfway through a login.
 */

/** The BFF's settings after they have been read and checked. */
export interface BffConfig {
    /** OpenEMR's address, for example https://localhost:9300 (no trailing slash). */
    oemrBase: string;
    /** OpenEMR's address as the user's browser reaches it, for the "Edit in OpenEMR" link (default: oemrBase). */
    oemrPublicUrl: string;
    /** The dashboard's registered OpenEMR client: its id, secret and the permissions (scope) it asks for. */
    clientId: string;
    clientSecret: string;
    scope: string;
    /** The address users type to reach the dashboard. */
    publicUrl: string;
    /** Where OpenEMR sends the browser back after login: PUBLIC_URL + /auth/callback. */
    redirectUri: string;
    /** True over HTTPS: the session cookie is then only ever sent over an encrypted connection. */
    secureCookie: boolean;
    /** How long an idle session lasts, in milliseconds. */
    sessionTtlMs: number;
    /** The network port and address the server listens on. */
    port: number;
    host: string;
    /** The server-only client that reads staff and facility names (Fable review F1). */
    namesClientId: string;
    /** PEM file holding that client's private key; never committed. */
    namesKeyFile: string;
}

/** Reads one environment variable that must be set; stops start-up with a clear message if it is not. */
function required(env: NodeJS.ProcessEnv, name: string): string {
    const value = env[name];
    if (value === undefined || value.trim() === '') {
        throw new Error(`Missing required environment variable ${name} (see patient-dashboard/.env)`);
    }
    return value.trim();
}

/**
 * OpenEMR's timeout global (default 7200 s): the session and the page both sign out after this long
 * without activity, as the old tab frame does (interface/main/tabs/main.php).
 */
export function idleTimeoutSecondsFrom(env: NodeJS.ProcessEnv): number {
    const raw = env.IDLE_TIMEOUT_SECONDS ?? '7200';
    // The pattern accepts only a whole number that does not start with 0 (so 1, 30, 7200; not 0, -5 or 1.5).
    if (!/^[1-9]\d*$/.test(raw)) {
        throw new Error(`IDLE_TIMEOUT_SECONDS must be a whole number of seconds above 0, got "${raw}"`);
    }
    return Number(raw);
}

/** Parses the BFF's environment once at start-up; fails fast on anything missing. */
export function loadConfig(env: NodeJS.ProcessEnv): BffConfig {
    // .replace(/\/+$/, '') removes any slashes at the end of an address, so "https://x/" becomes "https://x".
    const publicUrl = required(env, 'PUBLIC_URL').replace(/\/+$/, '');
    return {
        oemrBase: required(env, 'OEMR_BASE').replace(/\/+$/, ''),
        // OpenEMR's address as a clinician's browser reaches it, for the "Edit in OpenEMR" link. Usually the
        // same as OEMR_BASE; set OEMR_PUBLIC_URL when the server reaches OpenEMR by an internal address.
        oemrPublicUrl: (env.OEMR_PUBLIC_URL ?? required(env, 'OEMR_BASE')).replace(/\/+$/, ''),
        clientId: required(env, 'OEMR_CLIENT_ID'),
        clientSecret: required(env, 'OEMR_CLIENT_SECRET'),
        scope: required(env, 'OEMR_SCOPE'),
        publicUrl,
        redirectUri: `${publicUrl}/auth/callback`,
        secureCookie: publicUrl.startsWith('https://'),
        sessionTtlMs: idleTimeoutSecondsFrom(env) * 1000,
        // Optional settings: `??` means "if not set, use this default".
        port: Number(env.BFF_PORT ?? 5180),
        host: env.BFF_HOST ?? '127.0.0.1',
        namesClientId: required(env, 'NAMES_CLIENT_ID'),
        namesKeyFile: env.NAMES_KEY_FILE ?? 'certs/names-client-key.pem',
    };
}
