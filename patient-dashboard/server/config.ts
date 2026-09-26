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

/** Parses the BFF's environment once at start-up; fails fast on anything missing. */
export function loadConfig(env: NodeJS.ProcessEnv): BffConfig {
    const publicUrl = required(env, 'PUBLIC_URL').replace(/\/+$/, '');
    const ttlMinutes = Number(env.SESSION_TTL_MINUTES ?? 480);
    if (!Number.isFinite(ttlMinutes) || ttlMinutes <= 0) {
        throw new Error('SESSION_TTL_MINUTES must be a positive number');
    }
    return {
        oemrBase: required(env, 'OEMR_BASE').replace(/\/+$/, ''),
        clientId: required(env, 'OEMR_CLIENT_ID'),
        clientSecret: required(env, 'OEMR_CLIENT_SECRET'),
        scope: required(env, 'OEMR_SCOPE'),
        publicUrl,
        redirectUri: `${publicUrl}/auth/callback`,
        secureCookie: publicUrl.startsWith('https://'),
        sessionTtlMs: ttlMinutes * 60_000,
        port: Number(env.BFF_PORT ?? 5180),
        host: env.BFF_HOST ?? '127.0.0.1',
        namesClientId: required(env, 'NAMES_CLIENT_ID'),
        namesKeyFile: env.NAMES_KEY_FILE ?? 'certs/names-client-key.pem',
    };
}
