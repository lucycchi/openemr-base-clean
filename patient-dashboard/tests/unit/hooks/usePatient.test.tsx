// @vitest-environment jsdom
import { renderHook, waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import type { FhirResource, Patient } from 'fhir/r4';
import type { ApiClient, Result } from '../../../web/src/api/client';
import { usePatient } from '../../../web/src/hooks/usePatient';

function clientReturning(result: Result<FhirResource>): ApiClient {
    return {
        getResource: async <T extends FhirResource>() => result as Result<T>,
        getBundle: async () => ({ ok: true, value: [] }),
    };
}

const OPTIONS = { asOf: '2026-09-26', age: { format: 0, limitYears: 3 } } as const;

describe('usePatient', () => {
    it('maps the requested patient', async () => {
        const patient: Patient = { resourceType: 'Patient', id: 'p1', name: [{ given: ['Ann'], family: 'Lee' }] };
        const client = clientReturning({ ok: true, value: patient }); // created once, as the app does
        const { result } = renderHook(() => usePatient(client, 'p1', OPTIONS));

        await waitFor(() => expect(result.current.status).toBe('ready'));
        expect(result.current.status === 'ready' && result.current.data.name).toBe('Ann Lee');
    });

    it('a resource for another patient renders a load error (BM-004)', async () => {
        const other: Patient = { resourceType: 'Patient', id: 'someone-else' };
        const client = clientReturning({ ok: true, value: other });
        const { result } = renderHook(() => usePatient(client, 'p1', OPTIONS));

        await waitFor(() => expect(result.current.status).toBe('error'));
        expect(result.current).toEqual({
            status: 'error',
            error: { kind: 'wrong-patient', expected: 'p1', found: 'someone-else' },
        });
    });

    it('a failed request is a load error, not an empty header', async () => {
        const client = clientReturning({ ok: false, error: { kind: 'network' } });
        const { result } = renderHook(() => usePatient(client, 'p1', OPTIONS));

        await waitFor(() => expect(result.current.status).toBe('error'));
        expect(result.current).toEqual({ status: 'error', error: { kind: 'network' } });
    });
});

describe('usePatient when the patient changes', () => {
    it('never returns the previous patient while the next one loads', async () => {
        const patients: Record<string, Patient> = {
            p1: { resourceType: 'Patient', id: 'p1', name: [{ given: ['First'] }] },
            p2: { resourceType: 'Patient', id: 'p2', name: [{ given: ['Second'] }] },
        };
        let release: (() => void) | undefined;
        const client: ApiClient = {
            getResource: async <T extends FhirResource>(path: string) => {
                const id = path.split('/')[1] ?? '';
                if (id === 'p2') {
                    await new Promise<void>((resolve) => {
                        release = resolve;
                    });
                }
                return { ok: true, value: patients[id] } as Result<T>;
            },
            getBundle: async () => ({ ok: true, value: [] }),
        };
        const { result, rerender } = renderHook(({ id }) => usePatient(client, id, OPTIONS), {
            initialProps: { id: 'p1' },
        });
        await waitFor(() => expect(result.current.status).toBe('ready'));

        rerender({ id: 'p2' });

        expect(result.current.status).toBe('loading');
        release?.();
        await waitFor(() => expect(result.current.status === 'ready' && result.current.data.name).toBe('Second'));
    });
});
