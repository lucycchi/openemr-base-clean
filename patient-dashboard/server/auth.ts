/**
 * Logging in to and out of the dashboard, using OpenEMR's own login page (OAuth).
 *
 * Runs on the server. The browser calls these addresses under /auth:
 * - /auth/login sends the browser to OpenEMR's login page.
 * - /auth/callback is where OpenEMR sends the browser back after a successful login, carrying a one-time
 *   code; the server swaps that code for tokens (OpenEMR's "passes" for API calls) and keeps them.
 * - /auth/logout forgets the session.
 * - /auth/me tells the page whether the user is logged in.
 * The tokens never go to the browser. The browser only holds a cookie (a small value the browser sends back
 * with every request) containing a random session id; the server looks the tokens up by that id
 * (session.ts). Talking to OpenEMR's login service is done by oauth.ts.
 */
import { createHash, randomBytes } from 'node:crypto';
import { Hono } from 'hono';
import type { Context } from 'hono';
import { deleteCookie, getCookie, setCookie } from 'hono/cookie';
import { userIdFromToken } from './idToken';
import type { OAuthClient } from './oauth';
import { SessionLimitError, tokensFrom } from './session';
import type { SessionStore } from './session';

/** The name of the cookie that holds the session id. */
export const SESSION_COOKIE = 'pd_sid';

/** What the login routes need: the session store, the OpenEMR login client, the clock and the cookie setting. */
export interface AuthDeps {
    store: SessionStore;
    oauth: OAuthClient;
    now: () => number;
    /** True when the app is served over HTTPS; adds the Secure attribute to the session cookie. */
    secureCookie: boolean;
    /** The dashboard's OpenEMR client id: the login's ID token must be addressed to it. */
    clientId: string;
}

/**
 * Puts the session id in the browser's cookie. httpOnly: the page's own scripts cannot read it.
 * sameSite Lax: other websites cannot make the browser send it with their background requests.
 */
function setSessionCookie(c: Context, id: string, secure: boolean): void {
    setCookie(c, SESSION_COOKIE, id, { httpOnly: true, sameSite: 'Lax', path: '/', secure });
}

/** Login, callback, logout and session status. Tokens stay in the session store. */
export function authRoutes(deps: AuthDeps): Hono {
    const { store, oauth, now, secureCookie, clientId } = deps;
    const routes = new Hono();

    /**
     * GET /auth/login: starts a login. Makes two random one-time values: `state`, which proves the answer
     * that comes back belongs to this login, and a "code verifier" (PKCE), a secret that proves the same
     * server that started the login is the one finishing it. Only a scrambled form of the verifier (the
     * "code challenge") goes to OpenEMR now. Then sends the browser to OpenEMR's login page (302 = redirect).
     */
    routes.get('/login', (c) => {
        // Already signed in: go back to the dashboard rather than drop the session, so a cross-site link
        // to /auth/login cannot sign the user out.
        const previous = getCookie(c, SESSION_COOKIE);
        // `?.` means "if there is no session, stop here and treat the tokens as missing".
        if (store.get(previous)?.tokens !== undefined) {
            return c.redirect('/', 302);
        }
        // A fresh session for every login attempt.
        if (previous !== undefined) {
            store.delete(previous);
        }
        let session;
        // try/catch: if creating the session fails because the store is full, answer 503 ("busy, try
        // later"); any other failure is passed on as a real error.
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

    /**
     * GET /auth/callback: OpenEMR sends the browser here after the user logs in, with a one-time `code`
     * and the `state` from above. If anything does not match, the login is refused (400 = bad request).
     * Otherwise the server swaps the code for tokens, stores them, and sends the browser to the dashboard.
     * `async` marks a function that has to wait for OpenEMR; `await` is where it waits.
     */
    routes.get('/callback', async (c) => {
        const session = store.get(getCookie(c, SESSION_COOKIE));
        const pending = session?.pending;
        if (session !== undefined) {
            delete session.pending; // one use, whatever happens next
        }
        const code = c.req.query('code');
        // `||` means "or": any one of these problems is enough to refuse.
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
        let userId;
        // If OpenEMR refuses the code or does not answer, log only the status number (never the tokens)
        // and answer 502 (OpenEMR did not answer properly).
        try {
            const response = await oauth.exchangeCode(code, pending.codeVerifier);
            tokens = tokensFrom(response, now());
            // Who signed in, so their card layout can be remembered (cardSettings.ts). Not needed to log in.
            // OpenEMR grants `openid` but sends no ID token, so its access token (a JWT naming the user
            // the same way) stands in when there is none.
            userId = userIdFromToken(response.id_token ?? response.access_token, clientId, now());
        } catch (error) {
            console.error('token exchange failed', { status: (error as { status?: number }).status });
            return c.text('Login failed at OpenEMR. Try again.', 502);
        }
        // A new session id at login, so an id planted before login is useless after it (Opus review 4).
        store.delete(session.id);
        const signedIn = store.create();
        signedIn.tokens = tokens;
        if (userId !== undefined) {
            signedIn.userId = userId;
        } else {
            console.warn('login did not name the user; card layout will not be remembered');
        }
        setSessionCookie(c, signedIn.id, secureCookie);
        return c.redirect('/', 302);
    });

    /** POST /auth/logout: forgets the session and clears the cookie. 204 = done, nothing to send back. */
    routes.post('/logout', (c) => {
        const id = getCookie(c, SESSION_COOKIE);
        if (id !== undefined) {
            store.delete(id);
        }
        deleteCookie(c, SESSION_COOKIE, { path: '/', secure: secureCookie });
        return c.body(null, 204);
    });

    /**
     * GET /auth/me: answers {"authenticated": true} or false. Logged in means the session has tokens that
     * are either still valid or can be renewed (there is a refresh token). `&&` means "and".
     */
    routes.get('/me', (c) => {
        const session = store.get(getCookie(c, SESSION_COOKIE));
        const tokens = session?.tokens;
        const authenticated = tokens !== undefined && (tokens.expiresAt > now() || tokens.refreshToken !== undefined);
        return c.json({ authenticated });
    });

    return routes;
}
