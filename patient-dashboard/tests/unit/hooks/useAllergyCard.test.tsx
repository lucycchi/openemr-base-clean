// @vitest-environment jsdom
import { renderHook, waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import type { AllergyIntolerance, Bundle, FhirResource } from 'fhir/r4';
import type { ApiClient, Result } from '../../../web/src/api/client';
import { useAllergyCard } from '../../../web/src/hooks/useAllergyCard';
import { loadFixture } from '../fixtures/load';

const LONG = 'a2d6832c-b8b1-48cd-ab3b-127acc55cc85';

function allergies(): AllergyIntolerance[] {
    const bundle = loadFixture<Bundle<AllergyIntolerance>>('allergies-TP-LONG.json');
    return (bundle.entry ?? []).flatMap((entry) => (entry.resource === undefined ? [] : [entry.resource]));
}

function fakeClient(dates: Result<unknown>, paths: string[] = []): ApiClient {
    return {
        getResource: async () => ({ ok: false, error: { kind: 'network' } }),
        getBundle: async <T extends FhirResource>(path: string) => {
            paths.push(path);
            return { ok: true, value: allergies() } as Result<T[]>;
        },
        getJson: async (path: string) => {
            paths.push(path);
            return dates;
        },
    };
}

const longDates = {
    patient: LONG,
    list: 'allergy',
    entries: [
        { uuid: 'a2d6832d-6331-4119-9115-66945ff551c9', enddate: null, outcome: 1 },
        { uuid: 'a2d6832d-b5b6-45bc-8b07-99b2fa01e454', enddate: '2027-12-31 00:00:00', outcome: 0 },
    ],
};

describe('useAllergyCard', () => {
    it('loads the AllergyIntolerances and the allergy list dates, and applies the old rule', async () => {
        const paths: string[] = [];
        const client = fakeClient({ ok: true, value: longDates }, paths);
        const { result } = renderHook(() => useAllergyCard(client, LONG, '2026-09-26'));

        await waitFor(() => expect(result.current.status).toBe('ready'));
        const names = result.current.status === 'ready' ? result.current.data.map((a) => a.name) : [];
        expect(names).toContain('Long-list allergen 03');
        expect(names).not.toContain('Long-list allergen 02');
        expect(paths.sort()).toEqual(
            [`AllergyIntolerance?patient=${LONG}`, `list-dates?list=allergy&patient=${LONG}`].sort(),
        );
    });

    it('if the allergy list dates cannot be read, the card errors rather than guess (safety)', async () => {
        const client = fakeClient({ ok: false, error: { kind: 'http', status: 502 } });
        const { result } = renderHook(() => useAllergyCard(client, LONG, '2026-09-26'));

        await waitFor(() => expect(result.current.status).toBe('error'));
    });

    it('allergy dates for another patient are an error (BM-004)', async () => {
        const client = fakeClient({ ok: true, value: { ...longDates, patient: 'someone-else' } });
        const { result } = renderHook(() => useAllergyCard(client, LONG, '2026-09-26'));

        await waitFor(() => expect(result.current.status).toBe('error'));
    });
});
