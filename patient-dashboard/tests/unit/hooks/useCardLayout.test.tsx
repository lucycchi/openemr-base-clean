// @vitest-environment jsdom
import { act, renderHook, waitFor } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { useCardLayout } from '../../../web/src/hooks/useCardLayout';

/** A stand-in for the browser's fetch that answers GET with `collapsed` and records every call. */
function fakeFetch(answer: { status: number; collapsed?: string[] }) {
    const calls: { url: string; init?: RequestInit }[] = [];
    const fetchImpl = vi.fn(async (url: string, init?: RequestInit) => {
        calls.push(init === undefined ? { url } : { url, init });
        const body = JSON.stringify({ collapsed: answer.collapsed ?? [] });
        return new Response(body, { status: init?.method === 'PUT' ? 200 : answer.status });
    });
    return { fetchImpl: fetchImpl as unknown as typeof fetch, calls };
}

describe("useCardLayout (the old dashboard remembers each user's collapsed cards)", () => {
    it('is loading until the saved layout arrives, then opens every card but the collapsed ones', async () => {
        const { fetchImpl } = fakeFetch({ status: 200, collapsed: ['allergies'] });
        const { result } = renderHook(() => useCardLayout(true, fetchImpl));
        expect(result.current).toBe('loading');

        await waitFor(() => expect(result.current).not.toBe('loading'));
        const layout = result.current === 'loading' ? undefined : result.current;
        expect(layout?.isOpen('allergies')).toBe(false);
        expect(layout?.isOpen('problems')).toBe(true);
    });

    it('saves a change for the user and shows it straight away', async () => {
        const { fetchImpl, calls } = fakeFetch({ status: 200 });
        const { result } = renderHook(() => useCardLayout(true, fetchImpl));
        await waitFor(() => expect(result.current).not.toBe('loading'));

        act(() => {
            if (result.current !== 'loading') {
                result.current.setOpen('medications', false);
            }
        });
        expect(result.current !== 'loading' && result.current.isOpen('medications')).toBe(false);
        const put = calls.find((call) => call.init?.method === 'PUT');
        expect(put?.url).toBe('/api/card-settings');
        expect(JSON.parse(String(put?.init?.body))).toEqual({ card: 'medications', open: false });
        expect(new Headers(put?.init?.headers).get('content-type')).toBe('application/json');
    });

    it('opens every card when the saved layout cannot be read, and does not try to save', async () => {
        const { fetchImpl, calls } = fakeFetch({ status: 404 });
        const { result } = renderHook(() => useCardLayout(true, fetchImpl));
        await waitFor(() => expect(result.current).not.toBe('loading'));

        act(() => {
            if (result.current !== 'loading') {
                result.current.setOpen('allergies', false);
            }
        });
        expect(result.current !== 'loading' && result.current.isOpen('allergies')).toBe(false);
        expect(result.current !== 'loading' && result.current.isOpen('problems')).toBe(true);
        expect(calls.some((call) => call.init?.method === 'PUT')).toBe(false);
    });

    it('asks nothing while signed out', () => {
        const { fetchImpl, calls } = fakeFetch({ status: 200 });
        renderHook(() => useCardLayout(false, fetchImpl));
        expect(calls).toEqual([]);
    });
});
