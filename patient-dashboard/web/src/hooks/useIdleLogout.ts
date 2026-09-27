import { useEffect, useRef } from 'react';

const ACTIVITY = ['pointerdown', 'keydown', 'wheel', 'touchstart'] as const;
const CHECK_MS = 5_000;

/**
 * Signs the user out after `timeoutSeconds` without keyboard, pointer, wheel or touch activity, as
 * OpenEMR's tab frame does with its timeout global (interface/main/tabs/main.php:194-222). The clock is
 * checked every few seconds and on returning to the tab, so a sleeping laptop signs out on wake.
 */
export function useIdleLogout(active: boolean, timeoutSeconds: number, onTimeout: () => void): void {
    const lastActivity = useRef(0);
    const fired = useRef(false);
    const callback = useRef(onTimeout);
    useEffect(() => {
        callback.current = onTimeout;
    });

    useEffect(() => {
        if (!active) {
            return;
        }
        lastActivity.current = Date.now();
        fired.current = false;
        const touch = () => {
            lastActivity.current = Date.now();
        };
        const check = () => {
            if (!fired.current && Date.now() - lastActivity.current >= timeoutSeconds * 1000) {
                fired.current = true;
                callback.current();
            }
        };
        ACTIVITY.forEach((event) => globalThis.addEventListener(event, touch, { passive: true }));
        document.addEventListener('visibilitychange', check);
        const timer = setInterval(check, CHECK_MS);
        return () => {
            ACTIVITY.forEach((event) => globalThis.removeEventListener(event, touch));
            document.removeEventListener('visibilitychange', check);
            clearInterval(timer);
        };
    }, [active, timeoutSeconds]);
}
