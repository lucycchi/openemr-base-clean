import { describe, expect, it } from 'vitest';
import { createApp } from '../../../server/app';
import { SessionStore } from '../../../server/session';
import type { OAuthClient } from '../../../server/oauth';

const FHIR_BASE = 'https://oemr.test/apis/default/fhir';

interface Captured {
    url: string;
    method: string;
    authorization: string | null;
}

function setup() {
    const now = () => 5_000_000;
    const store = new SessionStore({ ttlMs: 3_600_000, now });
    const oauth: OAuthClient = {
        authorizeUrl: () => 'unused',
        exchangeCode: async () => ({ access_token: 'unused', expires_in: 0 }),
        refresh: async () => ({ access_token: 'unused', expires_in: 0 }),
    };
    const calls: Captured[] = [];
    const fetchImpl = (async (input: string | URL | Request, init?: RequestInit) => {
        const headers = new Headers(init?.headers);
        calls.push({ url: String(input), method: init?.method ?? 'GET', authorization: headers.get('authorization') });
        return new Response(JSON.stringify({ resourceType: 'Bundle', entry: [] }), {
            status: 200,
            headers: { 'content-type': 'application/fhir+json' },
        });
    }) as typeof fetch;
    const app = createApp({ fhir: { store, oauth, now, fhirBase: FHIR_BASE, fetchImpl } });
    const session = store.create();
    session.tokens = { accessToken: 'server-side-token', expiresAt: now() + 3_600_000 };
    const cookie = `pd_sid=${session.id}`;
    return { app, calls, cookie };
}

describe('FHIR proxy', () => {
    it('forwards an allow-listed GET with the session token', async () => {
        const { app, calls, cookie } = setup();

        const res = await app.request('/api/fhir/AllergyIntolerance?patient=abc', { headers: { cookie } });

        expect(res.status).toBe(200);
        expect(calls).toHaveLength(1);
        expect(calls[0]?.url).toBe(`${FHIR_BASE}/AllergyIntolerance?patient=abc`);
        expect(calls[0]?.authorization).toBe('Bearer server-side-token');
        expect(await res.text()).not.toContain('server-side-token');
    });

    it('non-allow-listed resource returns 400', async () => {
        const { app, calls, cookie } = setup();

        const res = await app.request('/api/fhir/Procedure?patient=abc', { headers: { cookie } });

        expect(res.status).toBe(400);
        expect(calls).toHaveLength(0);
    });

    it('rejects paths that are not Resource or Resource/id', async () => {
        const { app, calls, cookie } = setup();

        for (const path of ['/api/fhir/Patient/abc/_history', '/api/fhir/', '/api/fhir/Patient/a%2Fb']) {
            const res = await app.request(path, { headers: { cookie } });
            expect(res.status, path).toBe(400);
        }
        // Dot segments (plain or percent-encoded) are resolved by URL parsing before routing, so they
        // land outside /api/fhir. Either way nothing is forwarded and nothing succeeds.
        for (const path of ['/api/fhir/Patient/../../oauth2', '/api/fhir/Patient/%2e%2e/%2e%2e/oauth2']) {
            const res = await app.request(path, { headers: { cookie } });
            expect([400, 404], path).toContain(res.status);
        }
        expect(calls).toHaveLength(0);
    });

    it('_include is removed before forwarding', async () => {
        const { app, calls, cookie } = setup();

        await app.request(
            '/api/fhir/CareTeam?patient=abc&_include=CareTeam:participant&_revinclude=Provenance:target',
            {
                headers: { cookie },
            },
        );

        expect(calls[0]?.url).toBe(`${FHIR_BASE}/CareTeam?patient=abc`);
    });

    it('POST is rejected', async () => {
        const { app, calls, cookie } = setup();

        const res = await app.request('/api/fhir/Patient', { method: 'POST', headers: { cookie }, body: '{}' });

        expect(res.status).toBe(405);
        expect(calls).toHaveLength(0);
    });

    it('returns 401 without a logged-in session', async () => {
        const { app, calls } = setup();

        const res = await app.request('/api/fhir/Patient/abc');

        expect(res.status).toBe(401);
        expect(calls).toHaveLength(0);
    });
});
