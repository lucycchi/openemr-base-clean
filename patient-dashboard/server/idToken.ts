/**
 * Works out which OpenEMR user signed in, from the OpenID Connect ID token OpenEMR returns with the
 * access token at login. In: the ID token (a JWT: three dot-separated parts, the middle one a
 * base64url-encoded JSON list of "claims"), the expected issuer and client id, and the current time.
 * Out: the user's id (the `sub` claim, OpenEMR's user uuid), or undefined when the token is missing,
 * comes from another issuer, is not addressed to this client, has expired or names no user.
 *
 * The signature is not checked. The token comes straight from OpenEMR's token endpoint over a TLS
 * connection this server verified, which OpenID Connect Core 1.0 section 3.1.3.7 accepts in place of a
 * signature check. It is only used to keep each user's card layout apart, never to grant access.
 */
export function userIdFromIdToken(
    idToken: string | undefined,
    expected: { clientId: string; issuer: string },
    nowMs: number,
): string | undefined {
    const payload = idToken?.split('.')[1];
    if (payload === undefined) {
        return undefined;
    }
    let claims: unknown;
    try {
        claims = JSON.parse(Buffer.from(payload, 'base64url').toString('utf8'));
    } catch {
        return undefined;
    }
    if (typeof claims !== 'object' || claims === null) {
        return undefined;
    }
    const { sub, aud, exp, iss } = claims as { sub?: unknown; aud?: unknown; exp?: unknown; iss?: unknown };
    // `iss` must be the OpenEMR this server logs in to (its site address plus /oauth2/default).
    if (iss !== expected.issuer) {
        return undefined;
    }
    // `aud` may be one client id or a list of them; this client must be among them.
    const audiences = Array.isArray(aud) ? aud : [aud];
    if (!audiences.includes(expected.clientId)) {
        return undefined;
    }
    // `exp` is in seconds since 1970; the token must not have run out.
    if (typeof exp !== 'number' || exp * 1000 <= nowMs) {
        return undefined;
    }
    return typeof sub === 'string' && sub !== '' ? sub : undefined;
}
