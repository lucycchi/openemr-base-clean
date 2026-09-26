// @vitest-environment jsdom
import { renderHook, waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import type { Bundle, Encounter, FhirResource, Practitioner } from 'fhir/r4';
import type { ApiClient, Result } from '../../../web/src/api/client';
import { displayName } from '../../../web/src/mappers/people';
import { useEncounters } from '../../../web/src/hooks/useEncounters';
import { loadFixture } from '../fixtures/load';

const TYPICAL = 'a2d68325-7821-4a53-aa27-816ce437150f';
const DONNA = 'Practitioner/a2c6137a-eabd-4143-9baa-b3c606366a5f';

function encounters(): Encounter[] {
    const bundle = loadFixture<Bundle<Encounter>>('encounters-TP-TYPICAL.json');
    return (bundle.entry ?? []).flatMap((entry) => (entry.resource === undefined ? [] : [entry.resource]));
}

function fakeClient(
    bundle: Result<FhirResource[]>,
    reads: Record<string, Result<FhirResource>>,
    requested: string[] = [],
    { namesFail = false, extraNames = {} }: { namesFail?: boolean; extraNames?: Record<string, string> } = {},
): ApiClient {
    return {
        // The BFF's /api/display-names, answered from the same test data (Fable review F1).
        getJson: async (path: string) => {
            requested.push(path);
            if (namesFail) {
                return { ok: false, error: { kind: 'http', status: 502 } };
            }
            const names: Record<string, string> = { ...extraNames };
            for (const ref of new URLSearchParams(path.split('?')[1]).getAll('ref')) {
                const read = reads[ref];
                if (
                    read?.ok &&
                    (read.value.resourceType === 'Practitioner' || read.value.resourceType === 'Organization')
                ) {
                    names[ref] = displayName(read.value);
                }
            }
            return { ok: true, value: { names } };
        },
        getBundle: async <T extends FhirResource>(path: string) => {
            requested.push(path);
            return bundle as Result<T[]>;
        },
        getResource: async <T extends FhirResource>(path: string) => {
            requested.push(path);
            return (reads[path] ?? { ok: false, error: { kind: 'http', status: 404 } }) as Result<T>;
        },
    };
}

describe('useEncounters', () => {
    it('loads the encounters and reads each provider once', async () => {
        const requested: string[] = [];
        const donna = loadFixture<Practitioner>('practitioner-donna-lee.json');
        const client = fakeClient(
            { ok: true, value: encounters() },
            { [DONNA]: { ok: true, value: donna } },
            requested,
        );
        const { result } = renderHook(() => useEncounters(client, TYPICAL));

        await waitFor(() => expect(result.current.status).toBe('ready'));
        expect(result.current.status === 'ready' && result.current.data.map((e) => e.provider)).toEqual([
            'Lee, Donna',
            'Name unavailable',
            'Lee, Donna',
        ]);
        expect(requested).toEqual([`Encounter?patient=${TYPICAL}`, `display-names?ref=${encodeURIComponent(DONNA)}`]);
    });

    it('an encounter for another patient is a load error (BM-004)', async () => {
        const client = fakeClient({ ok: true, value: encounters() }, {});
        const { result } = renderHook(() => useEncounters(client, 'someone-else'));

        await waitFor(() => expect(result.current.status).toBe('error'));
    });

    it('a failed search is a load error, never an empty card', async () => {
        const client = fakeClient({ ok: false, error: { kind: 'network' } }, {});
        const { result } = renderHook(() => useEncounters(client, TYPICAL));

        await waitFor(() => expect(result.current).toEqual({ status: 'error', error: { kind: 'network' } }));
    });
});
