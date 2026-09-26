import { describe, expect, it } from 'vitest';
import { SessionStore, ensureFreshToken } from '../../../server/session';
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

describe('session tokens', () => {
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
