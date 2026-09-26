export interface TokenResponse {
    access_token: string;
    refresh_token?: string;
    expires_in: number;
    scope?: string;
}

/** The OpenEMR OAuth2 operations the BFF needs. Injected so tests never touch the network. */
export interface OAuthClient {
    authorizeUrl(params: { state: string; codeChallenge: string }): string;
    exchangeCode(code: string, codeVerifier: string): Promise<TokenResponse>;
    refresh(refreshToken: string): Promise<TokenResponse>;
}

export interface OAuthConfig {
    /** OpenEMR origin, for example https://localhost:9300 */
    base: string;
    clientId: string;
    clientSecret: string;
    scope: string;
    redirectUri: string;
}

export class OAuthError extends Error {
    constructor(
        message: string,
        readonly status: number,
    ) {
        super(message);
        this.name = 'OAuthError';
    }
}

const TIMEOUT_MS = 15_000;

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
    return response;
}

/** OpenEMR's confidential-client token endpoint, authenticated with HTTP Basic. */
export function createOAuthClient(config: OAuthConfig, fetchImpl: typeof fetch = fetch): OAuthClient {
    const basic = 'Basic ' + Buffer.from(`${config.clientId}:${config.clientSecret}`).toString('base64');

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
        exchangeCode(code, codeVerifier) {
            return token({
                grant_type: 'authorization_code',
                code,
                redirect_uri: config.redirectUri,
                code_verifier: codeVerifier,
            });
        },
        refresh(refreshToken) {
            return token({ grant_type: 'refresh_token', refresh_token: refreshToken });
        },
    };
}
