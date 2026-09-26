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
