import { describe, expect, it } from 'vitest';
import { createApp } from '../../../server/app';
import { SessionStore } from '../../../server/session';
import type { OAuthClient } from '../../../server/oauth';

const FHIR_BASE = 'https://oemr.test/apis/default/fhir';
const DONNA = 'Practitioner/a2c6137a-eabd-4143-9baa-b3c606366a5f';
const FRED = 'Practitioner/a2c6137a-eaba-455c-8e8b-16927c8a7a13';
const CLINIC = 'Organization/a2c61356-e5b0-4763-89bc-be5c28166216';

type Upstream = Record<string, { status: number; body: unknown }>;

const upstream: Upstream = {
    [`${FHIR_BASE}/${DONNA}`]: {
        status: 200,
        body: {
            resourceType: 'Practitioner',
            id: 'a2c6137a-eabd-4143-9baa-b3c606366a5f',
            name: [{ family: 'Lee', given: ['Donna'] }],
            telecom: [{ value: 'not for the dashboard' }],
        },
    },
    [`${FHIR_BASE}/${CLINIC}`]: {
        status: 200,
        body: { resourceType: 'Organization', id: 'a2c61356-e5b0-4763-89bc-be5c28166216', name: 'Great Clinic' },
    },
};

function setup(options: { loggedIn?: boolean; systemToken?: () => Promise<string>; clock?: { now: number } } = {}) {
    const clock = options.clock ?? { now: 5_000_000 };
    const now = () => clock.now;
    const store = new SessionStore({ ttlMs: 3_600_000, now });
    const oauth: OAuthClient = {
        authorizeUrl: () => 'unused',
        exchangeCode: async () => ({ access_token: 'unused', expires_in: 0 }),
        refresh: async () => ({ access_token: 'unused', expires_in: 0 }),
    };
    const calls: { url: string; authorization: string | null }[] = [];
    const fetchImpl = (async (input: string | URL | Request, init?: RequestInit) => {
        const url = String(input);
        calls.push({ url, authorization: new Headers(init?.headers).get('authorization') });
        const answer = upstream[url] ?? { status: 404, body: { resourceType: 'OperationOutcome' } };
        return new Response(JSON.stringify(answer.body), { status: answer.status });
    }) as typeof fetch;
    const app = createApp({
        displayNames: {
            store,
            oauth,
            now,
            fhirBase: FHIR_BASE,
            systemToken: { getToken: options.systemToken ?? (async () => 'system-token') },
            fetchImpl,
        },
    });
    const session = store.create();
    if (options.loggedIn !== false) {
        session.tokens = { accessToken: 'user-token', expiresAt: now() + 3_600_000 };
    }
    return { app, calls, cookie: `pd_sid=${session.id}` };
}

const query = (...refs: string[]) => refs.map((ref) => `ref=${encodeURIComponent(ref)}`).join('&');

describe('display names (server-only lookup, Fable review F1)', () => {
    it('reads each reference with the system token and returns only the formatted name', async () => {
        const { app, calls, cookie } = setup();

        const res = await app.request(`/api/display-names?${query(DONNA, CLINIC, FRED)}`, { headers: { cookie } });

        expect(res.status).toBe(200);
        expect(await res.json()).toEqual({ names: { [DONNA]: 'Lee, Donna', [CLINIC]: 'Great Clinic' } });
        expect(calls.map((call) => call.url)).toEqual([
            `${FHIR_BASE}/${DONNA}`,
            `${FHIR_BASE}/${CLINIC}`,
            `${FHIR_BASE}/${FRED}`,
        ]);
        expect(calls.every((call) => call.authorization === 'Bearer system-token')).toBe(true);
        expect(res.headers.get('cache-control')).toBe('no-store');
    });

    it('needs a logged-in user; the system token is never used for anyone else', async () => {
        const { app, calls, cookie } = setup({ loggedIn: false });

        const res = await app.request(`/api/display-names?${query(DONNA)}`, { headers: { cookie } });

        expect(res.status).toBe(401);
        expect(calls).toHaveLength(0);
    });

    it('only Practitioner and Organization, only relative references with a safe id, at most 50', async () => {
        const { app, calls, cookie } = setup();
        const tooMany = Array.from({ length: 51 }, (_, i) => `Practitioner/p${i}`);

        for (const q of [
            '',
            query('Patient/abc'),
            query('RelatedPerson/r1'),
            query('Practitioner/..'),
            query('https://elsewhere.example/Practitioner/1'),
            query(...tooMany),
        ]) {
            const res = await app.request(`/api/display-names?${q}`, { headers: { cookie } });
            expect(res.status, q.slice(0, 60)).toBe(400);
        }
        expect(calls).toHaveLength(0);
    });

    it('a resource that answers for a different id is not named', async () => {
        const { app, cookie } = setup();
        upstream[`${FHIR_BASE}/Practitioner/other`] = upstream[`${FHIR_BASE}/${DONNA}`] ?? { status: 404, body: {} };

        const res = await app.request(`/api/display-names?${query('Practitioner/other')}`, { headers: { cookie } });

        expect(await res.json()).toEqual({ names: {} });
    });

    it('if the system token cannot be had, 502, so the cards show "Name unavailable" rather than fail', async () => {
        const { app, cookie } = setup({
            systemToken: async () => {
                throw new Error('System token request returned HTTP 401');
            },
        });

        const res = await app.request(`/api/display-names?${query(DONNA)}`, { headers: { cookie } });

        expect(res.status).toBe(502);
    });

    it('caches names, found or not, for ten minutes, so repeated card loads do not re-read OpenEMR', async () => {
        const clock = { now: 5_000_000 };
        const { app, calls, cookie } = setup({ clock });
        const ask = () => app.request(`/api/display-names?${query(DONNA, FRED)}`, { headers: { cookie } });

        await ask();
        clock.now += 9 * 60_000;
        expect(await (await ask()).json()).toEqual({ names: { [DONNA]: 'Lee, Donna' } });
        expect(calls).toHaveLength(2);

        clock.now += 2 * 60_000;
        await ask();
        expect(calls).toHaveLength(4);
    });
});
