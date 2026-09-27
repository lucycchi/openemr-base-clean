import { describe, expect, it } from 'vitest';
import type { AllergyIntolerance, Bundle, Patient } from 'fhir/r4';
import { assertBelongsTo, createApiClient } from '../../../web/src/api/client';

function jsonResponse(body: unknown, status = 200): Response {
    return new Response(JSON.stringify(body), { status, headers: { 'content-type': 'application/fhir+json' } });
}

function allergyFor(patientId: string): AllergyIntolerance {
    return {
        resourceType: 'AllergyIntolerance',
        id: `a-${patientId}`,
        patient: { reference: `Patient/${patientId}` },
    };
}

describe('API client', () => {
    it('returns the parsed resource on success', async () => {
        const patient: Patient = { resourceType: 'Patient', id: 'p1' };
        const client = createApiClient({ fetchImpl: async () => jsonResponse(patient) });

        const result = await client.getResource<Patient>('Patient/p1');

        expect(result).toEqual({ ok: true, value: patient });
    });

    it('401 triggers re-login', async () => {
        let relogins = 0;
        // Body copied from API-SPIKE.md, Error responses (bad or unknown bearer token).
        const body = {
            error: 'An error occurred',
            message: 'The resource owner or authorization server denied the request.',
            code: 0,
        };
        const client = createApiClient({
            fetchImpl: async () => jsonResponse(body, 401),
            onUnauthenticated: () => {
                relogins += 1;
            },
        });

        const result = await client.getBundle<AllergyIntolerance>('AllergyIntolerance?patient=p1');

        expect(relogins).toBe(1);
        expect(result).toEqual({ ok: false, error: { kind: 'unauthenticated' } });
    });

    it('fetch failure maps to LoadError', async () => {
        const client = createApiClient({
            fetchImpl: async () => {
                throw new TypeError('Failed to fetch');
            },
        });

        const result = await client.getResource<Patient>('Patient/p1');

        expect(result).toEqual({ ok: false, error: { kind: 'network' } });
    });

    it('a 5xx or 403 maps to an http LoadError, never an empty result', async () => {
        for (const status of [403, 500, 502]) {
            const client = createApiClient({ fetchImpl: async () => jsonResponse({ error: 'x' }, status) });
            const result = await client.getBundle<AllergyIntolerance>('AllergyIntolerance?patient=p1');
            expect(result, String(status)).toEqual({ ok: false, error: { kind: 'http', status } });
        }
    });

    it('a response that is not a Bundle maps to invalid-response', async () => {
        const client = createApiClient({ fetchImpl: async () => jsonResponse({ resourceType: 'OperationOutcome' }) });

        const result = await client.getBundle<AllergyIntolerance>('AllergyIntolerance?patient=p1');

        expect(result).toEqual({ ok: false, error: { kind: 'invalid-response' } });
    });

    it('returns the entries of a Bundle', async () => {
        const bundle: Bundle<AllergyIntolerance> = {
            resourceType: 'Bundle',
            type: 'collection',
            entry: [{ resource: allergyFor('p1') }],
        };
        const client = createApiClient({ fetchImpl: async () => jsonResponse(bundle) });

        const result = await client.getBundle<AllergyIntolerance>('AllergyIntolerance?patient=p1');

        expect(result).toEqual({ ok: true, value: [allergyFor('p1')] });
    });
});

describe('API client getJson (BFF routes outside the FHIR proxy)', () => {
    it('reads /api/<path> and returns the parsed body', async () => {
        const urls: string[] = [];
        const client = createApiClient({
            fetchImpl: async (input) => {
                urls.push(String(input));
                return jsonResponse({ patient: 'p1', entries: [] });
            },
        });

        const result = await client.getJson('list-dates?list=medication&patient=p1');

        expect(result).toEqual({ ok: true, value: { patient: 'p1', entries: [] } });
        expect(urls).toEqual(['/api/list-dates?list=medication&patient=p1']);
    });

    it('a 401 triggers re-login and a 502 is an http LoadError', async () => {
        let relogins = 0;
        const unauthorised = createApiClient({
            fetchImpl: async () => jsonResponse({}, 401),
            onUnauthenticated: () => {
                relogins += 1;
            },
        });
        const failing = createApiClient({ fetchImpl: async () => jsonResponse({}, 502) });

        expect(await unauthorised.getJson('x')).toEqual({ ok: false, error: { kind: 'unauthenticated' } });
        expect(relogins).toBe(1);
        expect(await failing.getJson('x')).toEqual({ ok: false, error: { kind: 'http', status: 502 } });
    });
});

describe('assertBelongsTo', () => {
    it('accepts resources that all reference the header patient', () => {
        expect(assertBelongsTo('p1', [allergyFor('p1'), allergyFor('p1')])).toEqual({ ok: true, value: undefined });
    });

    it('resource for another patient maps to LoadError (BM-004)', () => {
        const result = assertBelongsTo('p1', [allergyFor('p1'), allergyFor('p2')]);

        expect(result).toEqual({ ok: false, error: { kind: 'wrong-patient', expected: 'p1', found: 'p2' } });
    });

    it('a clinical resource with no patient reference is an error, not a pass', () => {
        const orphan: AllergyIntolerance = { resourceType: 'AllergyIntolerance', id: 'x', patient: {} };

        const result = assertBelongsTo('p1', [orphan]);

        expect(result).toEqual({ ok: false, error: { kind: 'wrong-patient', expected: 'p1', found: '(none)' } });
    });
});

// Writes go to the BFF's JSON routes (ARC-06). sendJson never throws: the caller gets a status to act on.
describe('sendJson', () => {
    it('posts JSON with the session cookie and hands back the status and body', async () => {
        const seen: { url: string; init?: RequestInit }[] = [];
        const client = createApiClient({
            fetchImpl: async (input, init) => {
                seen.push({ url: String(input), ...(init === undefined ? {} : { init }) });
                return jsonResponse({ uuid: 'u' }, 201);
            },
        });
        expect(await client.sendJson('POST', 'prescriptions?patient=p1', { drug: 'x' })).toEqual({
            status: 201,
            body: { uuid: 'u' },
        });
        expect(seen[0]?.url).toBe('/api/prescriptions?patient=p1');
        expect(seen[0]?.init?.method).toBe('POST');
        expect(seen[0]?.init?.credentials).toBe('same-origin');
        expect(new Headers(seen[0]?.init?.headers).get('content-type')).toBe('application/json');
        expect(seen[0]?.init?.body).toBe('{"drug":"x"}');
    });

    it('sends the user to log in again on 401, as reads do', async () => {
        let relogins = 0;
        const client = createApiClient({
            fetchImpl: async () => jsonResponse({}, 401),
            onUnauthenticated: () => relogins++,
        });
        expect((await client.sendJson('POST', 'prescriptions', {})).status).toBe(401);
        expect(relogins).toBe(1);
    });

    it('reports status 0 when the request never reached the server, and no body when it is not JSON', async () => {
        const offline = createApiClient({
            fetchImpl: async () => {
                throw new TypeError('offline');
            },
        });
        expect(await offline.sendJson('POST', 'prescriptions', {})).toEqual({ status: 0, body: undefined });
        const html = createApiClient({ fetchImpl: async () => new Response('<html>', { status: 502 }) });
        expect(await html.sendJson('POST', 'prescriptions', {})).toEqual({ status: 502, body: undefined });
    });
});
