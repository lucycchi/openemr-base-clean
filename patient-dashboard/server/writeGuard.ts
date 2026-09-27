/**
 * The checks every dashboard write passes before it reaches OpenEMR (runs on the server). In: the
 * request and the session store. Out: the signed-in user's fresh access token, or the refusal to send
 * back:
 * - 403: the request came from another site (its Origin header is not the dashboard's own address), so
 *   a page elsewhere cannot make a signed-in clinician's browser change a record
 * - 415: the body is not JSON (a plain HTML form cannot send JSON)
 * - 401: not signed in
 * - 502: OpenEMR did not answer the token refresh
 */
import type { Context } from 'hono';
import { getCookie } from 'hono/cookie';
import { SESSION_COOKIE } from './auth';
import type { OAuthClient } from './oauth';
import { ensureFreshToken } from './session';
import type { Session, SessionStore } from './session';

export interface WriteGuardDeps {
    store: SessionStore;
    oauth: OAuthClient;
    now: () => number;
    /** The dashboard's public address (PUBLIC_URL); a write's Origin must equal it. */
    publicUrl: string;
}

export async function guardWrite(
    c: Context,
    deps: WriteGuardDeps,
): Promise<{ ok: true; accessToken: string; session: Session } | { ok: false; response: Response }> {
    // Browsers always send Origin on a POST, so a missing or different one means "not from this page".
    if (c.req.header('origin') !== new URL(deps.publicUrl).origin) {
        return { ok: false, response: c.json({ error: 'Cross-site request refused' }, 403) };
    }
    if (!(c.req.header('content-type') ?? '').startsWith('application/json')) {
        return { ok: false, response: c.json({ error: 'expected JSON' }, 415) };
    }
    const session = deps.store.get(getCookie(c, SESSION_COOKIE));
    let tokens;
    try {
        tokens = session === undefined ? undefined : await ensureFreshToken(session, deps.oauth, deps.now);
    } catch {
        return { ok: false, response: c.json({ error: 'OpenEMR did not respond' }, 502) };
    }
    if (session === undefined || tokens === undefined) {
        return { ok: false, response: c.json({ error: 'Not logged in' }, 401) };
    }
    return { ok: true, accessToken: tokens.accessToken, session };
}
