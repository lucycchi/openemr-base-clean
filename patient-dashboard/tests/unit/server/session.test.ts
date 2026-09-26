import { describe, expect, it } from 'vitest';
import { SessionStore, ensureFreshToken } from '../../../server/session';
import { OAuthError } from '../../../server/oauth';
import type { OAuthClient } from '../../../server/oauth';

function oauthCountingRefreshes() {
    const calls: string[] = [];
    const client: OAuthClient = {
        authorizeUrl: () => 'unused',
        exchangeCode: async () => ({ access_token: 'unused', expires_in: 0 }),
        refresh: async (refreshToken: string) => {
            calls.push(refreshToken);
            return { access_token: 'new-access', refresh_token: 'new-refresh', expires_in: 3600 };
        },
    };
    return { client, calls };
}

function oauthRefreshing(refresh: OAuthClient['refresh']): OAuthClient {
    return {
        authorizeUrl: () => 'unused',
        exchangeCode: async () => ({ access_token: 'unused', expires_in: 0 }),
        refresh,
    };
}

describe('session tokens', () => {
    it('concurrent requests share one refresh, because OpenEMR revokes a refresh token on first use', async () => {
        const now = () => 10_000_000;
        const store = new SessionStore({ ttlMs: 3_600_000, now });
        const session = store.create();
        session.tokens = { accessToken: 'old', refreshToken: 'once', expiresAt: now() + 1_000 };
        let used = 0;
        const oauth = oauthRefreshing(async (refreshToken) => {
            used += 1;
            if (refreshToken !== 'once' || used > 1) {
                throw new OAuthError('Token endpoint returned HTTP 400', 400);
            }
            await new Promise((resolve) => setTimeout(resolve, 5));
            return { access_token: 'new', refresh_token: 'next', expires_in: 3600 };
        });

        const results = await Promise.all(Array.from({ length: 9 }, () => ensureFreshToken(session, oauth, now)));

        expect(used).toBe(1);
        expect(results.map((tokens) => tokens?.accessToken)).toEqual(Array(9).fill('new'));
        expect(session.tokens?.refreshToken).toBe('next');
    });

    it('a refresh token OpenEMR rejects logs the session out, so the user is sent to log in again', async () => {
        const now = () => 10_000_000;
        const store = new SessionStore({ ttlMs: 3_600_000, now });
        const session = store.create();
        session.tokens = { accessToken: 'old', refreshToken: 'revoked', expiresAt: now() + 1_000 };
        const oauth = oauthRefreshing(async () => {
            throw new OAuthError('Token endpoint returned HTTP 400', 400);
        });

        expect(await ensureFreshToken(session, oauth, now)).toBeUndefined();
        expect(session.tokens).toBeUndefined();
    });

    it('a refresh that fails for another reason is an error, not a logout', async () => {
        const now = () => 10_000_000;
        const store = new SessionStore({ ttlMs: 3_600_000, now });
        const session = store.create();
        session.tokens = { accessToken: 'old', refreshToken: 'fine', expiresAt: now() + 1_000 };
        const oauth = oauthRefreshing(async () => {
            throw new TypeError('fetch failed');
        });

        await expect(ensureFreshToken(session, oauth, now)).rejects.toThrow('fetch failed');
        expect(session.tokens?.refreshToken).toBe('fine');
    });

    it('refresh runs when the access token is within 60 s of expiry', async () => {
        let clock = 10_000_000;
        const now = () => clock;
        const store = new SessionStore({ ttlMs: 3_600_000, now });
        const session = store.create();
        session.tokens = { accessToken: 'old-access', refreshToken: 'old-refresh', expiresAt: clock + 30_000 };
        const { client, calls } = oauthCountingRefreshes();

        const tokens = await ensureFreshToken(session, client, now);

        expect(calls).toEqual(['old-refresh']);
        expect(tokens?.accessToken).toBe('new-access');
        expect(session.tokens?.refreshToken).toBe('new-refresh');
        expect(session.tokens?.expiresAt).toBe(clock + 3_600_000);
        clock += 1;
    });

    it('does not refresh when more than 60 s remain', async () => {
        const now = () => 10_000_000;
        const store = new SessionStore({ ttlMs: 3_600_000, now });
        const session = store.create();
        session.tokens = { accessToken: 'current', refreshToken: 'r', expiresAt: now() + 120_000 };
        const { client, calls } = oauthCountingRefreshes();

        const tokens = await ensureFreshToken(session, client, now);

        expect(calls).toEqual([]);
        expect(tokens?.accessToken).toBe('current');
    });

    it('returns nothing when the token is expired and there is no refresh token', async () => {
        const now = () => 10_000_000;
        const store = new SessionStore({ ttlMs: 3_600_000, now });
        const session = store.create();
        session.tokens = { accessToken: 'stale', expiresAt: now() - 1 };
        const { client } = oauthCountingRefreshes();

        expect(await ensureFreshToken(session, client, now)).toBeUndefined();
    });

    it('sessions idle longer than the TTL are evicted', () => {
        let clock = 0;
        const store = new SessionStore({ ttlMs: 1000, now: () => clock });
        const session = store.create();

        clock = 999;
        expect(store.get(session.id)).toBeDefined();
        clock = 999 + 1001;
        expect(store.get(session.id)).toBeUndefined();
    });

    it('sweep removes abandoned login sessions', () => {
        let clock = 0;
        const store = new SessionStore({ ttlMs: 1000, now: () => clock });
        store.create();
        store.create();
        const live = store.create();

        clock = 800;
        store.get(live.id);
        clock = 1500;
        store.sweep();

        expect(store.size()).toBe(1);
        expect(store.get(live.id)).toBeDefined();
    });
});
