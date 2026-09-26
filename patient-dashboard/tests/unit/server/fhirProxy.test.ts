import { describe, expect, it } from 'vitest';
import { createApp } from '../../../server/app';
import { SessionStore } from '../../../server/session';
import { OAuthError } from '../../../server/oauth';
import type { OAuthClient } from '../../../server/oauth';

const FHIR_BASE = 'https://oemr.test/apis/default/fhir';

interface Captured {
    url: string;
    method: string;
    authorization: string | null;
}

function setup(
    refresh: OAuthClient['refresh'] = async () => ({ access_token: 'unused', expires_in: 0 }),
    expiresIn = 3_600_000,
) {
    const now = () => 5_000_000;
    const store = new SessionStore({ ttlMs: 3_600_000, now });
    const oauth: OAuthClient = {
        authorizeUrl: () => 'unused',
        exchangeCode: async () => ({ access_token: 'unused', expires_in: 0 }),
        refresh,
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
    session.tokens = { accessToken: 'server-side-token', refreshToken: 'refresh', expiresAt: now() + expiresIn };
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

    it('forwards an Organization read, for care-team facility names (BM-045)', async () => {
        const { app, calls, cookie } = setup();

        const res = await app.request('/api/fhir/Organization/a2c61356-e5b0-4763-89bc-be5c28166216', {
            headers: { cookie },
        });

        expect(res.status).toBe(200);
        expect(calls[0]?.url).toBe(`${FHIR_BASE}/Organization/a2c61356-e5b0-4763-89bc-be5c28166216`);
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

    it('a refresh token OpenEMR rejects gives 401, so the browser goes back to the login page', async () => {
        const { app, calls, cookie } = setup(async () => {
            throw new OAuthError('Token endpoint returned HTTP 400', 400);
        }, 1_000);

        const res = await app.request('/api/fhir/AllergyIntolerance?patient=abc', { headers: { cookie } });

        expect(res.status).toBe(401);
        expect(calls).toHaveLength(0);
    });

    it('a token endpoint that cannot be reached gives 502, never 500', async () => {
        const { app, cookie } = setup(async () => {
            throw new TypeError('fetch failed');
        }, 1_000);

        const res = await app.request('/api/fhir/AllergyIntolerance?patient=abc', { headers: { cookie } });

        expect(res.status).toBe(502);
    });

    it('a clinical search must name one patient, as the old page only ever showed one', async () => {
        const { app, calls, cookie } = setup();

        for (const path of [
            'Encounter',
            'Condition?code=44054006',
            'MedicationRequest?patient=',
            'CareTeam?status=active',
        ]) {
            const res = await app.request(`/api/fhir/${path}`, { headers: { cookie } });
            expect(res.status, path).toBe(400);
        }
        expect(calls).toHaveLength(0);
    });

    it('the patient picker search and reads by id still pass', async () => {
        const { app, calls, cookie } = setup();

        for (const path of ['Patient?name=Tessa', 'Practitioner/a2c6137a', 'Condition?patient=abc']) {
            const res = await app.request(`/api/fhir/${path}`, { headers: { cookie } });
            expect(res.status, path).toBe(200);
        }
        expect(calls).toHaveLength(3);
    });

    it('the one-patient rule cannot be dodged with a second value, a list, or a dot id', async () => {
        const { app, calls, cookie } = setup();

        for (const path of [
            'Condition?patient=abc&patient=def',
            'Condition?patient=abc,def',
            'Condition?patient=abc%2Cdef',
            'Condition/.',
            'Condition/..?patient=abc',
            'Encounter/.hidden',
        ]) {
            const res = await app.request(`/api/fhir/${path}`, { headers: { cookie } });
            expect([400, 404], path).toContain(res.status);
        }
        expect(calls).toHaveLength(0);
    });

    it('only the query parameters the app uses are forwarded; _REWRITE_COMMAND cannot re-route the request', async () => {
        // OpenEMR's apis/.htaccess appends the query (QSA) and PHP takes the last _REWRITE_COMMAND, so a
        // forwarded one would dispatch to any API route (Codex review 3).
        const { app, calls, cookie } = setup();

        for (const path of [
            'Patient?_REWRITE_COMMAND=default/api/patient',
            'Condition?patient=abc&_REWRITE_COMMAND=default/api/patient',
            'Encounter?patient=abc&_count=1000',
            'Patient/abc?_format=xml',
            'Encounter?patient=abc&date=gt2020',
        ]) {
            const res = await app.request(`/api/fhir/${path}`, { headers: { cookie } });
            expect(res.status, path).toBe(400);
        }
        expect(calls).toHaveLength(0);
    });
});
