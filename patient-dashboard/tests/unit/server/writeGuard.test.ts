import { Hono } from 'hono';
import { describe, expect, it } from 'vitest';
import { guardWrite } from '../../../server/writeGuard';
import { SessionStore } from '../../../server/session';
import type { OAuthClient } from '../../../server/oauth';

function app() {
    const now = () => 1_000_000;
    const store = new SessionStore({ ttlMs: 3_600_000, now });
    const session = store.create();
    session.tokens = { accessToken: 'at', expiresAt: now() + 3_600_000 };
    const routes = new Hono();
    routes.post('/w', async (c) => {
        const guard = await guardWrite(c, { store, oauth: {} as OAuthClient, now, publicUrl: 'https://dash.test' });
        return guard.ok ? c.json({ token: guard.accessToken }) : guard.response;
    });
    return { routes, cookie: `pd_sid=${session.id}` };
}
const post = (headers: Record<string, string>) => ({ method: 'POST', headers, body: '{}' });

// Every dashboard write passes these checks before it reaches OpenEMR (ARC-06, Review Focus 5).
describe('guardWrite', () => {
    it('lets a signed-in, same-origin JSON request through with the user token', async () => {
        const { routes, cookie } = app();
        const res = await routes.request(
            '/w',
            post({ cookie, origin: 'https://dash.test', 'content-type': 'application/json' }),
        );
        expect(await res.json()).toEqual({ token: 'at' });
    });

    it('refuses another origin, or no origin, with 403 (a form on another site cannot write)', async () => {
        const { routes, cookie } = app();
        for (const origin of ['https://evil.test', undefined]) {
            const headers: Record<string, string> = { cookie, 'content-type': 'application/json' };
            if (origin !== undefined) {
                headers.origin = origin;
            }
            expect((await routes.request('/w', post(headers))).status, String(origin)).toBe(403);
        }
    });

    it('refuses a body that is not JSON with 415', async () => {
        const { routes, cookie } = app();
        const res = await routes.request(
            '/w',
            post({ cookie, origin: 'https://dash.test', 'content-type': 'text/plain' }),
        );
        expect(res.status).toBe(415);
    });

    it('refuses a request with no session with 401', async () => {
        const { routes } = app();
        const res = await routes.request(
            '/w',
            post({ origin: 'https://dash.test', 'content-type': 'application/json' }),
        );
        expect(res.status).toBe(401);
    });
});
