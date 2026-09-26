// @vitest-environment jsdom
import { renderHook, waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import type { AllergyIntolerance, FhirResource } from 'fhir/r4';
import type { ApiClient, Result } from '../../../web/src/api/client';
import { useBundleCard } from '../../../web/src/hooks/useBundleCard';

function clientReturning(result: Result<FhirResource[]>, paths: string[] = []): ApiClient {
    return {
        getResource: async () => ({ ok: false, error: { kind: 'network' } }),
        getJson: async () => ({ ok: false, error: { kind: 'network' } }),
        getBundle: async <T extends FhirResource>(path: string) => {
            paths.push(path);
            return result as Result<T[]>;
        },
    };
}

const forPatient = (id: string): AllergyIntolerance => ({
    resourceType: 'AllergyIntolerance',
    patient: { reference: `Patient/${id}` },
});
const countEntries = (resources: AllergyIntolerance[]) => resources.length;

describe('useBundleCard', () => {
    it('fetches the search for the patient and maps the result', async () => {
        const paths: string[] = [];
        const client = clientReturning({ ok: true, value: [forPatient('p1'), forPatient('p1')] }, paths);
        const { result } = renderHook(() => useBundleCard(client, 'p1', 'AllergyIntolerance', countEntries));

        await waitFor(() => expect(result.current).toEqual({ status: 'ready', data: 2 }));
        expect(paths).toEqual(['AllergyIntolerance?patient=p1']);
    });

    it('a resource for another patient is a load error, not data (BM-004)', async () => {
        const client = clientReturning({ ok: true, value: [forPatient('p1'), forPatient('p2')] });
        const { result } = renderHook(() => useBundleCard(client, 'p1', 'AllergyIntolerance', countEntries));

        await waitFor(() => expect(result.current.status).toBe('error'));
        expect(result.current).toEqual({
            status: 'error',
            error: { kind: 'wrong-patient', expected: 'p1', found: 'p2' },
        });
    });

    it('a failed request is a load error, never an empty card', async () => {
        const client = clientReturning({ ok: false, error: { kind: 'http', status: 502 } });
        const { result } = renderHook(() => useBundleCard(client, 'p1', 'AllergyIntolerance', countEntries));

        await waitFor(() => expect(result.current).toEqual({ status: 'error', error: { kind: 'http', status: 502 } }));
    });
});
