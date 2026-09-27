// @vitest-environment jsdom
import { act, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { useIdleLogout } from '../../../web/src/hooks/useIdleLogout';

beforeEach(() => {
    vi.useFakeTimers();
});
afterEach(() => {
    vi.useRealTimers();
});

describe('useIdleLogout (OpenEMR signs out after the timeout global, default 7200 s)', () => {
    it('signs out once after the timeout with no activity', () => {
        const onTimeout = vi.fn();
        renderHook(() => useIdleLogout(true, 60, onTimeout));

        act(() => {
            vi.advanceTimersByTime(59_000);
        });
        expect(onTimeout).not.toHaveBeenCalled();
        act(() => {
            vi.advanceTimersByTime(20_000);
        });
        expect(onTimeout).toHaveBeenCalledTimes(1);
        act(() => {
            vi.advanceTimersByTime(120_000);
        });
        expect(onTimeout).toHaveBeenCalledTimes(1);
    });

    it('keyboard, pointer or wheel activity restarts the clock', () => {
        const onTimeout = vi.fn();
        renderHook(() => useIdleLogout(true, 60, onTimeout));

        act(() => {
            vi.advanceTimersByTime(50_000);
            window.dispatchEvent(new KeyboardEvent('keydown'));
            vi.advanceTimersByTime(50_000);
        });
        expect(onTimeout).not.toHaveBeenCalled();
        act(() => {
            vi.advanceTimersByTime(30_000);
        });
        expect(onTimeout).toHaveBeenCalledTimes(1);
    });

    it('does nothing while signed out', () => {
        const onTimeout = vi.fn();
        renderHook(() => useIdleLogout(false, 60, onTimeout));

        act(() => {
            vi.advanceTimersByTime(600_000);
        });
        expect(onTimeout).not.toHaveBeenCalled();
    });
});
