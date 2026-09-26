// @vitest-environment jsdom
import { renderHook, waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import type { CareTeam, FhirResource, Practitioner, RelatedPerson } from 'fhir/r4';
import type { ApiClient, Result } from '../../../web/src/api/client';
import { displayName } from '../../../web/src/mappers/people';
import { useCareTeam } from '../../../web/src/hooks/useCareTeam';

const team = (patient: string): CareTeam => ({
    resourceType: 'CareTeam',
    id: 't1',
    name: 'practitioner',
    status: 'active',
    subject: { reference: `Patient/${patient}` },
    participant: [{ member: { reference: 'Practitioner/u1' } }, { member: { reference: 'RelatedPerson/r1' } }],
});

function fakeClient(
    bundle: Result<FhirResource[]>,
    reads: Record<string, Result<FhirResource>>,
    requested: string[] = [],
    {
        namesFail = false,
        extraNames = {},
        failRefs = [],
    }: { namesFail?: boolean; extraNames?: Record<string, string>; failRefs?: string[] } = {},
): ApiClient {
    return {
        // The BFF's /api/display-names, answered from the same test data (Fable review F1).
        getJson: async (path: string) => {
            requested.push(path);
            if (namesFail) {
                return { ok: false, error: { kind: 'http', status: 502 } };
            }
            const failed = new URLSearchParams(path.split('?')[1])
                .getAll('ref')
                .filter((ref) => failRefs.includes(ref));
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
            return { ok: true, value: { names, failed } };
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

const fred: Practitioner = { resourceType: 'Practitioner', id: 'u1', name: [{ family: 'Stone', given: ['Fred'] }] };
const martha = (patient: string): RelatedPerson => ({
    resourceType: 'RelatedPerson',
    id: 'r1',
    patient: { reference: `Patient/${patient}` },
    name: [{ text: 'martha mom' }],
});

describe('useCareTeam', () => {
    it('loads the teams, then reads each member once for its name', async () => {
        const requested: string[] = [];
        const client = fakeClient(
            { ok: true, value: [team('p1')] },
            { 'Practitioner/u1': { ok: true, value: fred }, 'RelatedPerson/r1': { ok: true, value: martha('p1') } },
            requested,
        );
        const { result } = renderHook(() => useCareTeam(client, 'p1'));

        await waitFor(() => expect(result.current.status).toBe('ready'));
        expect(result.current.status === 'ready' && result.current.data[0]?.members.map((m) => m.name)).toEqual([
            'Stone, Fred',
            'martha mom',
        ]);
        // Staff names come from the server-only lookup, never a user read of Practitioner (Fable review F1).
        expect(requested).toEqual(['CareTeam?patient=p1', 'display-names?ref=Practitioner%2Fu1', 'RelatedPerson/r1']);
    });

    it('a failed member read shows "Name unavailable", not a card error (BM-028)', async () => {
        const client = fakeClient({ ok: true, value: [team('p1')] }, {});
        const { result } = renderHook(() => useCareTeam(client, 'p1'));

        await waitFor(() => expect(result.current.status).toBe('ready'));
        expect(result.current.status === 'ready' && result.current.data[0]?.members.map((m) => m.name)).toEqual([
            'Name unavailable',
            'Name unavailable',
        ]);
    });

    it('a related person who belongs to another patient is not named', async () => {
        const client = fakeClient(
            { ok: true, value: [team('p1')] },
            { 'RelatedPerson/r1': { ok: true, value: martha('p2') } },
        );
        const { result } = renderHook(() => useCareTeam(client, 'p1'));

        await waitFor(() => expect(result.current.status).toBe('ready'));
        expect(result.current.status === 'ready' && result.current.data[0]?.members[1]?.name).toBe('Name unavailable');
    });

    it('a care team for another patient is a load error (BM-004)', async () => {
        const client = fakeClient({ ok: true, value: [team('p2')] }, {});
        const { result } = renderHook(() => useCareTeam(client, 'p1'));

        await waitFor(() => expect(result.current.status).toBe('error'));
    });

    it('a failed team search is a load error, never an empty card', async () => {
        const client = fakeClient({ ok: false, error: { kind: 'http', status: 502 } }, {});
        const { result } = renderHook(() => useCareTeam(client, 'p1'));

        await waitFor(() => expect(result.current).toEqual({ status: 'error', error: { kind: 'http', status: 502 } }));
    });

    it("reads a member's facility once and shows its name (BM-045)", async () => {
        const withFacility: CareTeam = {
            ...team('p1'),
            participant: [
                { member: { reference: 'Practitioner/u1' }, onBehalfOf: { reference: 'Organization/o1' } },
                { member: { reference: 'Organization/o1' } },
            ],
        };
        const requested: string[] = [];
        const client = fakeClient(
            { ok: true, value: [withFacility] },
            {
                'Organization/o1': {
                    ok: true,
                    value: { resourceType: 'Organization', id: 'o1', name: 'Great Clinic' },
                },
            },
            requested,
        );
        const { result } = renderHook(() => useCareTeam(client, 'p1'));

        await waitFor(() => expect(result.current.status).toBe('ready'));
        expect(result.current.status === 'ready' && result.current.data[0]?.members.map((m) => m.facility)).toEqual([
            'Great Clinic',
        ]);
        expect(requested).toEqual(['CareTeam?patient=p1', 'display-names?ref=Practitioner%2Fu1&ref=Organization%2Fo1']);
    });

    it('if the names lookup fails, staff say so, distinct from "no record", and the card still loads', async () => {
        const client = fakeClient(
            { ok: true, value: [team('p1')] },
            { 'Practitioner/u1': { ok: true, value: fred }, 'RelatedPerson/r1': { ok: true, value: martha('p1') } },
            [],
            { namesFail: true },
        );
        const { result } = renderHook(() => useCareTeam(client, 'p1'));

        await waitFor(() => expect(result.current.status).toBe('ready'));
        expect(result.current.status === 'ready' && result.current.data[0]?.members.map((m) => m.name)).toEqual([
            "Name couldn't be loaded",
            'martha mom',
        ]);
    });

    it('a name for a reference nobody asked for is ignored', async () => {
        const client = fakeClient({ ok: true, value: [team('p1')] }, {}, [], {
            extraNames: { 'Practitioner/u1': 'Stone, Fred', 'Practitioner/intruder': 'Someone Else' },
        });
        const { result } = renderHook(() => useCareTeam(client, 'p1'));

        await waitFor(() => expect(result.current.status).toBe('ready'));
        expect(result.current.status === 'ready' && result.current.data[0]?.members[0]?.name).toBe('Stone, Fred');
        expect(JSON.stringify(result.current)).not.toContain('Someone Else');
    });

    it('a reference the lookup reports as failed reads "Name couldn\'t be loaded"', async () => {
        const client = fakeClient({ ok: true, value: [team('p1')] }, {}, [], { failRefs: ['Practitioner/u1'] });
        const { result } = renderHook(() => useCareTeam(client, 'p1'));

        await waitFor(() => expect(result.current.status).toBe('ready'));
        expect(result.current.status === 'ready' && result.current.data[0]?.members[0]?.name).toBe(
            "Name couldn't be loaded",
        );
    });
});
