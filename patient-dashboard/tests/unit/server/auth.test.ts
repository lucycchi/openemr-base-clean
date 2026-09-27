import { describe, expect, it } from 'vitest';
import { createApp } from '../../../server/app';
import { SessionStore } from '../../../server/session';
import type { OAuthClient, TokenResponse } from '../../../server/oauth';

const SECRET_TOKEN = 'secret-access-token-value';
const CLIENT_ID = 'dashboard-client';

/** An ID token as OpenEMR sends it: `sub` is the user, `aud` the client (IdTokenSMARTResponse.php). */
function idToken(sub: string, aud = CLIENT_ID): string {
    const part = (value: unknown) => Buffer.from(JSON.stringify(value)).toString('base64url');
    return `${part({ alg: 'RS256' })}.${part({ sub, aud, exp: 1_000_000 / 1000 + 3600 })}.sig`;
}

function fakeOAuth(idTokenValue?: string, accessToken = SECRET_TOKEN): OAuthClient & { exchanged: string[] } {
    const exchanged: string[] = [];
    return {
        exchanged,
        authorizeUrl: ({ state, codeChallenge }) =>
            `https://oemr.test/oauth2/default/authorize?state=${state}&code_challenge=${codeChallenge}&code_challenge_method=S256`,
        exchangeCode: async (code: string): Promise<TokenResponse> => {
            exchanged.push(code);
            return {
                access_token: accessToken,
                refresh_token: 'secret-refresh',
                expires_in: 3600,
                ...(idTokenValue === undefined ? {} : { id_token: idTokenValue }),
            };
        },
        refresh: async (): Promise<TokenResponse> => ({ access_token: 'refreshed', expires_in: 3600 }),
    };
}

function setup(secureCookie = false, maxSessions?: number, idTokenValue?: string, accessToken?: string) {
    const now = () => 1_000_000;
    const store = new SessionStore({
        ttlMs: 60 * 60 * 1000,
        now,
        ...(maxSessions === undefined ? {} : { maxSessions }),
    });
    const oauth = fakeOAuth(idTokenValue, accessToken);
    const app = createApp({ auth: { store, oauth, now, secureCookie, clientId: CLIENT_ID } });
    return { app, store, oauth };
}

async function startLogin(app: ReturnType<typeof createApp>) {
    const res = await app.request('/auth/login');
    const location = res.headers.get('location') ?? '';
    const state = new URL(location).searchParams.get('state') ?? '';
    const cookie = (res.headers.get('set-cookie') ?? '').split(';')[0] ?? '';
    return { res, state, cookie };
}

/** The session cookie a response sets, as "pd_sid=<id>". */
const setCookieOf = (res: Response) => (res.headers.get('set-cookie') ?? '').split(';')[0] ?? '';

describe('BFF login flow', () => {
    it('login redirects to OpenEMR with a PKCE S256 challenge and sets an HttpOnly session cookie', async () => {
        const { app } = setup();
        const { res, state } = await startLogin(app);

        expect(res.status).toBe(302);
        expect(res.headers.get('location')).toContain('code_challenge_method=S256');
        expect(state.length).toBeGreaterThanOrEqual(16);
        const setCookie = res.headers.get('set-cookie') ?? '';
        expect(setCookie).toMatch(/HttpOnly/i);
        expect(setCookie).toMatch(/SameSite=Lax/i);
        expect(setCookie).not.toMatch(/Secure/i);
    });

    it('callback rejects a reused or mismatched state', async () => {
        const { app, oauth } = setup();

        const first = await startLogin(app);
        const wrong = await app.request(`/auth/callback?code=c1&state=not-the-state`, {
            headers: { cookie: first.cookie },
        });
        expect(wrong.status).toBe(400);
        // The pending login is consumed by any callback attempt, so the real state no longer works either.
        const afterWrong = await app.request(`/auth/callback?code=c1&state=${first.state}`, {
            headers: { cookie: first.cookie },
        });
        expect(afterWrong.status).toBe(400);

        const second = await startLogin(app);
        const ok = await app.request(`/auth/callback?code=c2&state=${second.state}`, {
            headers: { cookie: second.cookie },
        });
        expect(ok.status).toBe(302);
        expect(ok.headers.get('location')).toBe('/');
        const reused = await app.request(`/auth/callback?code=c2&state=${second.state}`, {
            headers: { cookie: second.cookie },
        });
        expect(reused.status).toBe(400);

        expect(oauth.exchanged).toEqual(['c2']);
    });

    it('token response is stored server-side and never sent to the browser', async () => {
        const { app, store } = setup();
        const login = await startLogin(app);
        const callback = await app.request(`/auth/callback?code=c&state=${login.state}`, {
            headers: { cookie: login.cookie },
        });
        const me = await app.request('/auth/me', { headers: { cookie: setCookieOf(callback) } });

        const exposed = [
            await callback.text(),
            JSON.stringify([...callback.headers.entries()]),
            await me.clone().text(),
            JSON.stringify([...me.headers.entries()]),
            login.cookie,
        ].join('\n');
        expect(exposed).not.toContain(SECRET_TOKEN);
        expect(exposed).not.toContain('secret-refresh');
        expect(await me.json()).toEqual({ authenticated: true });

        const sessionId = setCookieOf(callback).split('=')[1] ?? '';
        expect(store.get(sessionId)?.tokens?.accessToken).toBe(SECRET_TOKEN);
    });

    it('callback remembers who signed in, from the ID token, so settings can follow the user', async () => {
        const { app, store } = setup(false, undefined, idToken('user-uuid-1'));
        const login = await startLogin(app);
        const callback = await app.request(`/auth/callback?code=c&state=${login.state}`, {
            headers: { cookie: login.cookie },
        });
        const sessionId = setCookieOf(callback).split('=')[1] ?? '';
        expect(store.get(sessionId)?.userId).toBe('user-uuid-1');
        const me = await app.request('/auth/me', { headers: { cookie: setCookieOf(callback) } });
        expect(await me.json()).toEqual({ authenticated: true });
    });

    it('without an ID token, the user is read from the access token, which OpenEMR issues as a JWT', async () => {
        // OpenEMR 8.2 grants `openid` but its token answer carries no id_token; its access token names the user.
        const { app, store } = setup(false, undefined, undefined, idToken('user-uuid-2'));
        const login = await startLogin(app);
        const callback = await app.request(`/auth/callback?code=c&state=${login.state}`, {
            headers: { cookie: login.cookie },
        });
        const sessionId = setCookieOf(callback).split('=')[1] ?? '';
        expect(store.get(sessionId)?.userId).toBe('user-uuid-2');
    });

    it('an ID token for another client does not name the user, but the login still works', async () => {
        const { app, store } = setup(false, undefined, idToken('user-uuid-1', 'someone-else'));
        const login = await startLogin(app);
        const callback = await app.request(`/auth/callback?code=c&state=${login.state}`, {
            headers: { cookie: login.cookie },
        });
        expect(callback.status).toBe(302);
        const sessionId = setCookieOf(callback).split('=')[1] ?? '';
        expect(store.get(sessionId)?.tokens).toBeDefined();
        expect(store.get(sessionId)?.userId).toBeUndefined();
    });

    it('logout is POST only and ends the session', async () => {
        const { app } = setup();
        const login = await startLogin(app);
        const callback = await app.request(`/auth/callback?code=c&state=${login.state}`, {
            headers: { cookie: login.cookie },
        });
        const cookie = setCookieOf(callback);

        const viaGet = await app.request('/auth/logout', { headers: { cookie } });
        expect(viaGet.status).toBe(404);

        const viaPost = await app.request('/auth/logout', { method: 'POST', headers: { cookie } });
        expect(viaPost.status).toBe(204);
        const me = await app.request('/auth/me', { headers: { cookie } });
        expect(await me.json()).toEqual({ authenticated: false });
    });

    it('session cookie is Secure when the app is served over HTTPS', async () => {
        const { app } = setup(true);
        const { res } = await startLogin(app);
        expect(res.headers.get('set-cookie') ?? '').toMatch(/Secure/);
    });

    it('the session id changes at login, so an id planted before login is useless after it (Opus review 4)', async () => {
        const { app } = setup();
        const login = await startLogin(app);
        const callback = await app.request(`/auth/callback?code=c&state=${login.state}`, {
            headers: { cookie: login.cookie },
        });

        expect(setCookieOf(callback)).not.toBe(login.cookie);
        expect(setCookieOf(callback)).toMatch(/^pd_sid=/);
        const before = await app.request('/auth/me', { headers: { cookie: login.cookie } });
        const after = await app.request('/auth/me', { headers: { cookie: setCookieOf(callback) } });
        expect(await before.json()).toEqual({ authenticated: false });
        expect(await after.json()).toEqual({ authenticated: true });
    });

    it('opening /auth/login while signed in keeps the session, so a cross-site link cannot sign the user out', async () => {
        const { app } = setup();
        const login = await startLogin(app);
        const callback = await app.request(`/auth/callback?code=c&state=${login.state}`, {
            headers: { cookie: login.cookie },
        });
        const cookie = setCookieOf(callback);

        const again = await app.request('/auth/login', { headers: { cookie } });

        expect(again.status).toBe(302);
        expect(again.headers.get('location')).toBe('/');
        expect(await (await app.request('/auth/me', { headers: { cookie } })).json()).toEqual({ authenticated: true });
    });

    it('when the session store is full, a new login is refused rather than using unbounded memory', async () => {
        const { app } = setup(false, 2);
        await startLogin(app);
        await startLogin(app);

        const third = await app.request('/auth/login');

        expect(third.status).toBe(503);
    });
});
