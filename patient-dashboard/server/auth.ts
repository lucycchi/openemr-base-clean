import { createHash, randomBytes } from 'node:crypto';
import { Hono } from 'hono';
import type { Context } from 'hono';
import { deleteCookie, getCookie, setCookie } from 'hono/cookie';
import type { OAuthClient } from './oauth';
import { SessionLimitError, tokensFrom } from './session';
import type { SessionStore } from './session';

export const SESSION_COOKIE = 'pd_sid';

export interface AuthDeps {
    store: SessionStore;
    oauth: OAuthClient;
    now: () => number;
    /** True when the app is served over HTTPS; adds the Secure attribute to the session cookie. */
    secureCookie: boolean;
}

function setSessionCookie(c: Context, id: string, secure: boolean): void {
    setCookie(c, SESSION_COOKIE, id, { httpOnly: true, sameSite: 'Lax', path: '/', secure });
}

/** Login, callback, logout and session status. Tokens stay in the session store. */
export function authRoutes(deps: AuthDeps): Hono {
    const { store, oauth, now, secureCookie } = deps;
    const routes = new Hono();

    routes.get('/login', (c) => {
        // Already signed in: go back to the dashboard rather than drop the session, so a cross-site link
        // to /auth/login cannot sign the user out.
        const previous = getCookie(c, SESSION_COOKIE);
        if (store.get(previous)?.tokens !== undefined) {
            return c.redirect('/', 302);
        }
        // A fresh session for every login attempt.
        if (previous !== undefined) {
            store.delete(previous);
        }
        let session;
        try {
            session = store.create();
        } catch (error) {
            if (error instanceof SessionLimitError) {
                return c.text('The dashboard is busy. Try again in a few minutes.', 503);
            }
            throw error;
        }
        const codeVerifier = randomBytes(32).toString('base64url');
        const state = randomBytes(16).toString('base64url');
        session.pending = { state, codeVerifier };
        setSessionCookie(c, session.id, secureCookie);
        const codeChallenge = createHash('sha256').update(codeVerifier).digest('base64url');
        return c.redirect(oauth.authorizeUrl({ state, codeChallenge }), 302);
    });

    routes.get('/callback', async (c) => {
        const session = store.get(getCookie(c, SESSION_COOKIE));
        const pending = session?.pending;
        if (session !== undefined) {
            delete session.pending; // one use, whatever happens next
        }
        const code = c.req.query('code');
        if (
            session === undefined ||
            pending === undefined ||
            c.req.query('error') !== undefined ||
            code === undefined ||
            c.req.query('state') !== pending.state
        ) {
            return c.text('Login could not be completed. Start again from the dashboard.', 400);
        }
        let tokens;
        try {
            tokens = tokensFrom(await oauth.exchangeCode(code, pending.codeVerifier), now());
        } catch (error) {
            console.error('token exchange failed', { status: (error as { status?: number }).status });
            return c.text('Login failed at OpenEMR. Try again.', 502);
        }
        // A new session id at login, so an id planted before login is useless after it (Opus review 4).
        store.delete(session.id);
        const signedIn = store.create();
        signedIn.tokens = tokens;
        setSessionCookie(c, signedIn.id, secureCookie);
        return c.redirect('/', 302);
    });

    routes.post('/logout', (c) => {
        const id = getCookie(c, SESSION_COOKIE);
        if (id !== undefined) {
            store.delete(id);
        }
        deleteCookie(c, SESSION_COOKIE, { path: '/', secure: secureCookie });
        return c.body(null, 204);
    });

    routes.get('/me', (c) => {
        const session = store.get(getCookie(c, SESSION_COOKIE));
        const tokens = session?.tokens;
        const authenticated = tokens !== undefined && (tokens.expiresAt > now() || tokens.refreshToken !== undefined);
        return c.json({ authenticated });
    });

    return routes;
}
