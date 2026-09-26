// @vitest-environment jsdom
import { renderHook, waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import type { Bundle, FhirResource, MedicationRequest } from 'fhir/r4';
import type { ApiClient, Result } from '../../../web/src/api/client';
import { useMedicationCards } from '../../../web/src/hooks/useMedicationCards';
import { loadFixture } from '../fixtures/load';

const TYPICAL = 'a2d68325-7821-4a53-aa27-816ce437150f';

function requests(): MedicationRequest[] {
    const bundle = loadFixture<Bundle<MedicationRequest>>('medications-TP-TYPICAL.json');
    return (bundle.entry ?? []).flatMap((entry) => (entry.resource === undefined ? [] : [entry.resource]));
}

function fakeClient(dates: Result<unknown>, paths: string[] = []): ApiClient {
    return {
        getResource: async () => ({ ok: false, error: { kind: 'network' } }),
        getBundle: async <T extends FhirResource>(path: string) => {
            paths.push(path);
            return { ok: true, value: requests() } as Result<T[]>;
        },
        getJson: async (path: string) => {
            paths.push(path);
            return dates;
        },
    };
}

const typicalDates = {
    patient: TYPICAL,
    entries: [
        { uuid: 'a2d6832a-be78-4354-b0ac-9d7b893d0ac4', enddate: null, outcome: 0 },
        { uuid: 'a2d6832a-bf83-4fd5-a6da-ee15b8d4283a', enddate: '2027-06-30 00:00:00', outcome: 0 },
    ],
};

describe('useMedicationCards', () => {
    it('loads the MedicationRequests and the list end dates, and keeps a future-ended entry', async () => {
        const paths: string[] = [];
        const client = fakeClient({ ok: true, value: typicalDates }, paths); // created once: a new client per render would refetch forever
        const { result } = renderHook(() => useMedicationCards(client, TYPICAL, '2026-09-26'));

        await waitFor(() => expect(result.current.medications.status).toBe('ready'));
        expect(
            result.current.medications.status === 'ready' && result.current.medications.data.map((m) => m.name),
        ).toEqual(['Metformin 500 mg', 'Lisinopril 10 mg']);
        expect(paths.sort()).toEqual(
            [`MedicationRequest?patient=${TYPICAL}`, `medication-end-dates?patient=${TYPICAL}`].sort(),
        );
    });

    it('if the end dates cannot be loaded, Medications errors but Prescriptions still shows', async () => {
        const client = fakeClient({ ok: false, error: { kind: 'http', status: 502 } }); // created once: a new client per render would refetch forever
        const { result } = renderHook(() => useMedicationCards(client, TYPICAL, '2026-09-26'));

        await waitFor(() => expect(result.current.prescriptions.status).toBe('ready'));
        await waitFor(() => expect(result.current.medications.status).toBe('error'));
    });

    it('end dates for another patient are an error, not data (BM-004)', async () => {
        const client = fakeClient({ ok: true, value: { ...typicalDates, patient: 'someone-else' } }); // created once: a new client per render would refetch forever
        const { result } = renderHook(() => useMedicationCards(client, TYPICAL, '2026-09-26'));

        await waitFor(() => expect(result.current.medications.status).toBe('error'));
    });
});
