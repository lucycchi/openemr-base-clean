/**
 * The inactivity timer. App.tsx uses it to sign the user out when nobody has touched the keyboard,
 * mouse or screen for the site's idle timeout, so an unattended chart does not stay open. In: whether
 * the timer should run (only while signed in), the timeout in seconds, and what to do when it runs out.
 * Out: nothing; it simply calls `onTimeout` once when the time is up.
 */
import { useEffect, useRef } from 'react';

// The browser events that count as activity: a click or tap, a key press, a scroll, a touch.
const ACTIVITY = ['pointerdown', 'keydown', 'wheel', 'touchstart'] as const;
// How often to check the clock, in milliseconds (every 5 seconds).
const CHECK_MS = 5_000;

/**
 * Signs the user out after `timeoutSeconds` without keyboard, pointer, wheel or touch activity, as
 * OpenEMR's tab frame does with its timeout global (interface/main/tabs/main.php:194-222). The clock is
 * checked every few seconds and on returning to the tab, so a sleeping laptop signs out on wake.
 */
export function useIdleLogout(active: boolean, timeoutSeconds: number, onTimeout: () => void): void {
    // `useRef` is a value kept between redraws that, unlike useState, does not redraw the page when it
    // changes. These hold the time of the last activity, whether sign-out has already been triggered,
    // and the latest sign-out action.
    const lastActivity = useRef(0);
    const fired = useRef(false);
    const callback = useRef(onTimeout);
    // After every redraw, remember the newest sign-out action, so the timer below always calls it
    // without having to be restarted.
    useEffect(() => {
        callback.current = onTimeout;
    });

    // While active, listen for activity and check the clock; the clean-up removes the listeners and the
    // timer when signing out or when the timeout setting changes.
    useEffect(() => {
        if (!active) {
            return;
        }
        lastActivity.current = Date.now();
        fired.current = false;
        // Record "the user did something just now".
        const touch = () => {
            lastActivity.current = Date.now();
        };
        // Sign out once, if the time since the last activity has reached the timeout.
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
