import { createHash, createPrivateKey, createPublicKey, randomUUID, sign } from 'node:crypto';

const ASSERTION_TYPE = 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer';
const ASSERTION_LIFETIME_S = 300;
const RENEW_BEFORE_MS = 60_000;
const TIMEOUT_MS = 15_000;

export interface AssertionDeps {
    /** OpenEMR's token endpoint, which is also the assertion's audience. */
    tokenUrl: string;
    clientId: string;
    /** PKCS#8 PEM of the client's RSA private key; never leaves the server. */
    privateKeyPem: string;
}

export interface SystemTokenDeps extends AssertionDeps {
    scope: string;
    now: () => number;
    fetchImpl?: typeof fetch;
}

export interface SystemTokenSource {
    getToken(): Promise<string>;
}

interface PublicJwk {
    kty: string | undefined;
    n: string | undefined;
    e: string | undefined;
    kid: string;
    alg: 'RS384';
    use: 'sig';
}

function base64url(value: string | Buffer): string {
    return Buffer.from(value).toString('base64url');
}

/** The public half of the client key as a JWKS, for registration. The kid is the RFC 7638 thumbprint. */
export function publicJwks(privateKeyPem: string): { keys: PublicJwk[] } {
    const jwk = createPublicKey(createPrivateKey(privateKeyPem)).export({ format: 'jwk' });
    const thumbprint = createHash('sha256')
        .update(JSON.stringify({ e: jwk.e, kty: jwk.kty, n: jwk.n }))
        .digest('base64url');
    return { keys: [{ kty: jwk.kty, n: jwk.n, e: jwk.e, kid: thumbprint, alg: 'RS384', use: 'sig' }] };
}

/**
 * The signed client assertion OpenEMR's client_credentials grant checks
 * (src/Services/JWTClientAuthenticationService.php): RS384, iss = sub = client id, aud = the token
 * endpoint, a unique jti, and a short expiry.
 */
export function clientAssertion(deps: AssertionDeps, nowMs: number, jti: string = randomUUID()): string {
    const kid = publicJwks(deps.privateKeyPem).keys[0]?.kid;
    const issuedAt = Math.floor(nowMs / 1000);
    const header = base64url(JSON.stringify({ alg: 'RS384', typ: 'JWT', kid }));
    const payload = base64url(
        JSON.stringify({
            iss: deps.clientId,
            sub: deps.clientId,
            aud: deps.tokenUrl,
            jti,
            iat: issuedAt,
            exp: issuedAt + ASSERTION_LIFETIME_S,
        }),
    );
    const signature = sign('sha384', Buffer.from(`${header}.${payload}`), createPrivateKey(deps.privateKeyPem));
    return `${header}.${payload}.${base64url(signature)}`;
}

/**
 * A server-only OpenEMR client (SMART backend services) used for one thing: reading Practitioner and
 * Organization names, which OpenEMR's API only lets administrators read (Fable review F1; user
 * decision 2026-09-26). Tokens are cached until a minute before they expire.
 */
export function createSystemTokenSource(deps: SystemTokenDeps): SystemTokenSource {
    const fetchImpl = deps.fetchImpl ?? fetch;
    let cached: { token: string; expiresAt: number } | undefined;

    return {
        async getToken(): Promise<string> {
            const now = deps.now();
            if (cached !== undefined && cached.expiresAt - RENEW_BEFORE_MS > now) {
                return cached.token;
            }
            const res = await fetchImpl(deps.tokenUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json' },
                body: new URLSearchParams({
                    grant_type: 'client_credentials',
                    scope: deps.scope,
                    client_assertion_type: ASSERTION_TYPE,
                    client_assertion: clientAssertion(deps, now),
                }).toString(),
                signal: AbortSignal.timeout(TIMEOUT_MS),
            });
            if (!res.ok) {
                throw new Error(`System token request returned HTTP ${res.status}`);
            }
            const body = (await res.json()) as { access_token?: unknown; expires_in?: unknown };
            if (typeof body.access_token !== 'string' || typeof body.expires_in !== 'number') {
                throw new Error('System token response is missing access_token or expires_in');
            }
            cached = { token: body.access_token, expiresAt: now + body.expires_in * 1000 };
            return cached.token;
        },
    };
}
