import { describe, expect, it } from 'vitest';
import { createApp } from '../../../server/app';
import { SessionStore } from '../../../server/session';
import type { OAuthClient, TokenResponse } from '../../../server/oauth';

const SECRET_TOKEN = 'secret-access-token-value';

function fakeOAuth(): OAuthClient & { exchanged: string[] } {
    const exchanged: string[] = [];
    return {
        exchanged,
        authorizeUrl: ({ state, codeChallenge }) =>
            `https://oemr.test/oauth2/default/authorize?state=${state}&code_challenge=${codeChallenge}&code_challenge_method=S256`,
        exchangeCode: async (code: string): Promise<TokenResponse> => {
            exchanged.push(code);
            return { access_token: SECRET_TOKEN, refresh_token: 'secret-refresh', expires_in: 3600 };
        },
        refresh: async (): Promise<TokenResponse> => ({ access_token: 'refreshed', expires_in: 3600 }),
    };
}

function setup(secureCookie = false) {
    const now = () => 1_000_000;
    const store = new SessionStore({ ttlMs: 60 * 60 * 1000, now });
    const oauth = fakeOAuth();
    const app = createApp({ auth: { store, oauth, now, secureCookie } });
    return { app, store, oauth };
}

async function startLogin(app: ReturnType<typeof createApp>) {
    const res = await app.request('/auth/login');
    const location = res.headers.get('location') ?? '';
    const state = new URL(location).searchParams.get('state') ?? '';
    const cookie = (res.headers.get('set-cookie') ?? '').split(';')[0] ?? '';
    return { res, state, cookie };
}

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
        const me = await app.request('/auth/me', { headers: { cookie: login.cookie } });

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

        const sessionId = login.cookie.split('=')[1] ?? '';
        expect(store.get(sessionId)?.tokens?.accessToken).toBe(SECRET_TOKEN);
    });

    it('logout is POST only and ends the session', async () => {
        const { app } = setup();
        const login = await startLogin(app);
        await app.request(`/auth/callback?code=c&state=${login.state}`, { headers: { cookie: login.cookie } });

        const viaGet = await app.request('/auth/logout', { headers: { cookie: login.cookie } });
        expect(viaGet.status).toBe(404);

        const viaPost = await app.request('/auth/logout', { method: 'POST', headers: { cookie: login.cookie } });
        expect(viaPost.status).toBe(204);
        const me = await app.request('/auth/me', { headers: { cookie: login.cookie } });
        expect(await me.json()).toEqual({ authenticated: false });
    });

    it('session cookie is Secure when the app is served over HTTPS', async () => {
        const { app } = setup(true);
        const { res } = await startLogin(app);
        expect(res.headers.get('set-cookie') ?? '').toMatch(/Secure/);
    });
});
