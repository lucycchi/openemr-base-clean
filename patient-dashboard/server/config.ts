export interface BffConfig {
    oemrBase: string;
    clientId: string;
    clientSecret: string;
    scope: string;
    publicUrl: string;
    redirectUri: string;
    secureCookie: boolean;
    sessionTtlMs: number;
    port: number;
    host: string;
    /** The server-only client that reads staff and facility names (Fable review F1). */
    namesClientId: string;
    /** PEM file holding that client's private key; never committed. */
    namesKeyFile: string;
}

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
    if (!/^[1-9]\d*$/.test(raw)) {
        throw new Error(`IDLE_TIMEOUT_SECONDS must be a whole number of seconds above 0, got "${raw}"`);
    }
    return Number(raw);
}

/** Parses the BFF's environment once at start-up; fails fast on anything missing. */
export function loadConfig(env: NodeJS.ProcessEnv): BffConfig {
    const publicUrl = required(env, 'PUBLIC_URL').replace(/\/+$/, '');
    return {
        oemrBase: required(env, 'OEMR_BASE').replace(/\/+$/, ''),
        clientId: required(env, 'OEMR_CLIENT_ID'),
        clientSecret: required(env, 'OEMR_CLIENT_SECRET'),
        scope: required(env, 'OEMR_SCOPE'),
        publicUrl,
        redirectUri: `${publicUrl}/auth/callback`,
        secureCookie: publicUrl.startsWith('https://'),
        sessionTtlMs: idleTimeoutSecondsFrom(env) * 1000,
        port: Number(env.BFF_PORT ?? 5180),
        host: env.BFF_HOST ?? '127.0.0.1',
        namesClientId: required(env, 'NAMES_CLIENT_ID'),
        namesKeyFile: env.NAMES_KEY_FILE ?? 'certs/names-client-key.pem',
    };
}
