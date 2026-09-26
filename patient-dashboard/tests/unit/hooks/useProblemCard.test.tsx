// @vitest-environment jsdom
import { renderHook, waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import type { ApiClient, Result } from '../../../web/src/api/client';
import { useProblemCard } from '../../../web/src/hooks/useProblemCard';

const HISTORY = 'a2d6832a-671e-4a74-a35e-9424da762ae7';

function fakeClient(list: Result<unknown>, paths: string[] = []): ApiClient {
    return {
        getResource: async () => ({ ok: false, error: { kind: 'network' } }),
        getBundle: async () => ({ ok: false, error: { kind: 'network' } }),
        getJson: async (path: string) => {
            paths.push(path);
            return list;
        },
    };
}

const historyList = {
    patient: HISTORY,
    list: 'medical_problem',
    entries: [
        {
            uuid: 'p1',
            title: 'Community-acquired pneumonia',
            begdate: '2024-10-26 00:00:00',
            enddate: '2024-12-15 00:00:00',
            outcome: 0,
        },
        { uuid: 'p2', title: 'Fee sheet problem', begdate: '2024-10-26 00:00:00', enddate: null, outcome: 0 },
    ],
};

describe('useProblemCard', () => {
    it('reads the problem list from the standard API and applies the old rule', async () => {
        const paths: string[] = [];
        const client = fakeClient({ ok: true, value: historyList }, paths);
        const { result } = renderHook(() => useProblemCard(client, HISTORY, '2026-09-26'));

        await waitFor(() => expect(result.current.status).toBe('ready'));
        expect(result.current.status === 'ready' && result.current.data.map((p) => p.name)).toEqual([
            'Fee sheet problem',
        ]);
        expect(paths).toEqual([`list-dates?list=medical_problem&patient=${HISTORY}`]);
    });

    it('a failed read is a load error, never an empty list', async () => {
        const client = fakeClient({ ok: false, error: { kind: 'http', status: 502 } });
        const { result } = renderHook(() => useProblemCard(client, HISTORY, '2026-09-26'));

        await waitFor(() => expect(result.current).toEqual({ status: 'error', error: { kind: 'http', status: 502 } }));
    });

    it('a list for another patient is an error (BM-004)', async () => {
        const client = fakeClient({ ok: true, value: { ...historyList, patient: 'someone-else' } });
        const { result } = renderHook(() => useProblemCard(client, HISTORY, '2026-09-26'));

        await waitFor(() => expect(result.current.status).toBe('error'));
    });
});
