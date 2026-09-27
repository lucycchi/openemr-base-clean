/**
 * The BFF's conversation with OpenEMR's login service (OAuth2), on behalf of the logged-in user.
 *
 * Runs on the server. It does three things: builds the address of OpenEMR's login page (the browser is
 * sent there by auth.ts); swaps the one-time code OpenEMR hands back after login for tokens; and swaps a
 * refresh token for a fresh access token when the old one is about to run out (session.ts). Tokens are
 * OpenEMR's "passes" for API calls. The dashboard proves who it is with its own client id and secret,
 * which are read from environment variables and never sent to the browser.
 */

/** OpenEMR's answer when it issues tokens (the names match OpenEMR's JSON). */
export interface TokenResponse {
    access_token: string;
    refresh_token?: string;
    /** How many seconds the access token lasts. */
    expires_in: number;
    scope?: string;
    /** The OpenID Connect ID token naming the user (sent because the dashboard asks for `openid`). */
    id_token?: string;
}

/** The OpenEMR OAuth2 operations the BFF needs. Injected so tests never touch the network. */
export interface OAuthClient {
    authorizeUrl(params: { state: string; codeChallenge: string }): string;
    exchangeCode(code: string, codeVerifier: string): Promise<TokenResponse>;
    refresh(refreshToken: string): Promise<TokenResponse>;
}

/** The dashboard's OpenEMR client registration, as read from the environment (config.ts). */
export interface OAuthConfig {
    /** OpenEMR origin, for example https://localhost:9300 */
    base: string;
    clientId: string;
    clientSecret: string;
    /** The permissions the dashboard asks for, space-separated. */
    scope: string;
    /** Where OpenEMR sends the browser back after login. */
    redirectUri: string;
}

/**
 * An error from OpenEMR's token service, carrying the HTTP status number so callers can tell a refused
 * token (400 or 401) from OpenEMR being down or answering nonsense (502).
 */
export class OAuthError extends Error {
    constructor(
        message: string,
        readonly status: number,
    ) {
        super(message);
        this.name = 'OAuthError';
    }
}

/** Give up on OpenEMR after 15 seconds. */
const TIMEOUT_MS = 15_000;

/** Checks OpenEMR's token answer has the parts the BFF needs, keeping only those; anything else is a 502. */
function parseTokenResponse(body: unknown): TokenResponse {
    if (typeof body !== 'object' || body === null) {
        throw new OAuthError('Token response is not an object', 502);
    }
    const record = body as Record<string, unknown>;
    const accessToken = record.access_token;
    const expiresIn = record.expires_in;
    if (typeof accessToken !== 'string' || typeof expiresIn !== 'number') {
        throw new OAuthError('Token response is missing access_token or expires_in', 502);
    }
    const response: TokenResponse = { access_token: accessToken, expires_in: expiresIn };
    if (typeof record.refresh_token === 'string') {
        response.refresh_token = record.refresh_token;
    }
    if (typeof record.scope === 'string') {
        response.scope = record.scope;
    }
    // The OpenID Connect ID token, which names the user (read in auth.ts through idToken.ts).
    if (typeof record.id_token === 'string') {
        response.id_token = record.id_token;
    }
    return response;
}

/**
 * OpenEMR's confidential-client token endpoint, authenticated with HTTP Basic.
 * ("Confidential client": the dashboard has a secret, kept on the server. "HTTP Basic": the id and secret
 * are sent together, encoded, in a header on each token request.) `fetchImpl` is the function used to call
 * OpenEMR; tests pass a fake one.
 */
export function createOAuthClient(config: OAuthConfig, fetchImpl: typeof fetch = fetch): OAuthClient {
    const basic = 'Basic ' + Buffer.from(`${config.clientId}:${config.clientSecret}`).toString('base64');

    /** Sends one form to OpenEMR's token endpoint and waits for its answer; any non-success status throws. */
    async function token(form: Record<string, string>): Promise<TokenResponse> {
        const res = await fetchImpl(`${config.base}/oauth2/default/token`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', Authorization: basic },
            body: new URLSearchParams(form),
            signal: AbortSignal.timeout(TIMEOUT_MS),
        });
        if (!res.ok) {
            throw new OAuthError(`Token endpoint returned HTTP ${res.status}`, res.status);
        }
        return parseTokenResponse(await res.json());
    }

    return {
        /**
         * The address of OpenEMR's login page, with what OpenEMR needs to know: who is asking, which
         * permissions, where to send the browser back, the `state` check value and the PKCE code challenge
         * (see auth.ts). `aud` names the FHIR API the tokens are for.
         */
        authorizeUrl({ state, codeChallenge }) {
            const url = new URL(`${config.base}/oauth2/default/authorize`);
            url.search = new URLSearchParams({
                response_type: 'code',
                client_id: config.clientId,
                redirect_uri: config.redirectUri,
                scope: config.scope,
                state,
                code_challenge: codeChallenge,
                code_challenge_method: 'S256',
                aud: `${config.base}/apis/default/fhir`,
            }).toString();
            return url.toString();
        },
        /** Swaps the one-time login code for tokens; the code verifier proves this server started the login. */
        exchangeCode(code, codeVerifier) {
            return token({
                grant_type: 'authorization_code',
                code,
                redirect_uri: config.redirectUri,
                code_verifier: codeVerifier,
            });
        },
        /** Swaps a refresh token for a new access token (and usually a new refresh token). */
        refresh(refreshToken) {
            return token({ grant_type: 'refresh_token', refresh_token: refreshToken });
        },
    };
}
