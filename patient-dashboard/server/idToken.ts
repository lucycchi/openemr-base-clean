/**
 * Works out which OpenEMR user signed in, from a token OpenEMR returned at login. In: the token (a JWT:
 * three dot-separated parts, the middle one a base64url-encoded JSON list of "claims"), the dashboard's
 * client id and the current time. Out: the user's id (the `sub` claim, OpenEMR's user uuid), or
 * undefined when the token is missing, is not addressed to this client, has expired or names no user.
 *
 * The OpenID Connect ID token is the proper source. OpenEMR 8.2 grants the `openid` scope but its token
 * answer carries no ID token, so auth.ts passes the access token instead: OpenEMR issues it as a JWT with
 * the same `sub`, `aud` and `exp` claims.
 *
 * The signature is not checked. The token comes straight from OpenEMR's token endpoint over a TLS
 * connection this server verified, which OpenID Connect Core 1.0 section 3.1.3.7 accepts in place of a
 * signature check. It is only used to keep each user's card layout apart, never to grant access.
 */
export function userIdFromToken(token: string | undefined, clientId: string, nowMs: number): string | undefined {
    const payload = token?.split('.')[1];
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
    const { sub, aud, exp } = claims as { sub?: unknown; aud?: unknown; exp?: unknown };
    // `aud` may be one client id or a list of them; this client must be among them.
    const audiences = Array.isArray(aud) ? aud : [aud];
    if (!audiences.includes(clientId)) {
        return undefined;
    }
    // `exp` is in seconds since 1970; the token must not have run out.
    if (typeof exp !== 'number' || exp * 1000 <= nowMs) {
        return undefined;
    }
    return typeof sub === 'string' && sub !== '' ? sub : undefined;
}
