/**
 * The BFF's own OpenEMR login, separate from any user's, used only to read staff and facility names.
 *
 * Runs on the server. OpenEMR lets a trusted server program log in on its own behalf ("SMART backend
 * services", the client_credentials grant). Instead of a password, the BFF proves who it is by signing a
 * short, dated note (a "client assertion", in JWT format) with its private key; OpenEMR checks the signature
 * against the public key registered for the client. In: the private key file and the client id (from
 * config.ts). Out: an access token that displayNames.ts uses to read Practitioner and Organization records.
 * The token is reused until a minute before it expires. The private key never leaves the server.
 */
import { createHash, createPrivateKey, createPublicKey, randomUUID, sign } from 'node:crypto';

/** The standard label that tells OpenEMR "my proof of identity is a signed JWT". */
const ASSERTION_TYPE = 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer';
/** The signed note is valid for 5 minutes. */
const ASSERTION_LIFETIME_S = 300;
/** Get a new token when the current one has less than a minute left. */
const RENEW_BEFORE_MS = 60_000;
/** Give up on OpenEMR after 15 seconds. */
const TIMEOUT_MS = 15_000;

/** What is needed to sign a client assertion. */
export interface AssertionDeps {
    /** OpenEMR's token endpoint, which is also the assertion's audience. */
    tokenUrl: string;
    clientId: string;
    /** PKCS#8 PEM of the client's RSA private key; never leaves the server. */
    privateKeyPem: string;
}

/** What the token source needs: the above, plus the permissions (scope) to ask for and the clock. */
export interface SystemTokenDeps extends AssertionDeps {
    scope: string;
    now: () => number;
    /** The function used to call OpenEMR; tests pass a fake one. */
    fetchImpl?: typeof fetch;
}

/** Something that can hand out a current system access token, waiting for OpenEMR if it must fetch one. */
export interface SystemTokenSource {
    getToken(): Promise<string>;
}

/** The public half of the key, in the JSON Web Key format OpenEMR reads when the client is registered. */
interface PublicJwk {
    kty: string | undefined;
    n: string | undefined;
    e: string | undefined;
    kid: string;
    alg: 'RS384';
    use: 'sig';
}

/** Encodes text or bytes in "base64url", the web-safe text form used inside JWTs. */
function base64url(value: string | Buffer): string {
    return Buffer.from(value).toString('base64url');
}

/**
 * The public half of the client key as a JWKS, for registration. The kid is the RFC 7638 thumbprint.
 * (JWKS: a list of public keys in JSON. The "kid", key id, is a fingerprint of the key, so OpenEMR can tell
 * which registered key a signature was made with.)
 */
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
 * (In words: a note saying "I am this client, this note is for OpenEMR's token service, here is a
 * never-reused serial number (jti), issued now (iat), expiring in 5 minutes (exp)", signed with the private
 * key. A JWT is three base64url parts joined by dots: header, contents, signature.)
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
    // The last token received and when it expires; `undefined` until the first one is fetched.
    let cached: { token: string; expiresAt: number } | undefined;

    return {
        /** Returns the remembered token if it has over a minute left; otherwise waits for OpenEMR to issue one. */
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
            // Any failure status, or an answer without a token and lifetime, is an error; displayNames.ts
            // turns it into a 502 reply.
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
