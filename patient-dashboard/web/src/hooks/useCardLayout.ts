/**
 * Which cards the signed-in user keeps collapsed, remembered between visits as on the old dashboard.
 * In: whether someone is signed in. Out: 'loading' until the saved layout arrives from the dashboard
 * server (/api/card-settings, server/cardSettings.ts), then an object that says whether a card is open
 * and saves a change when the user opens or collapses one.
 *
 * If the saved layout cannot be read (for example the login did not say who the user is), every card
 * starts open and changes are kept only while the page is open. The layout is a convenience, so a
 * failure here never hides a card or stops the dashboard.
 *
 * CardLayoutContext passes the layout down to every CardFrame without threading it through each card:
 * App wraps the patient view in it, and each CardFrame reads it.
 */
import { createContext, useEffect, useMemo, useState } from 'react';

export interface CardLayout {
    /** True when the card (by its label, for example "allergies") should be drawn open. */
    isOpen(card: string): boolean;
    /** Records the user's choice for one card and saves it for their next visit. */
    setOpen(card: string, open: boolean): void;
}

/** Undefined when no layout is provided; CardFrame then keeps its own open state. */
export const CardLayoutContext = createContext<CardLayout | undefined>(undefined);

/** The browser's fetch, wrapped so it is always called the ordinary way. */
const browserFetch: typeof fetch = (input, init) => fetch(input, init);

/** Reads {"collapsed": [...]} and keeps only the text entries. */
function collapsedFrom(body: unknown): string[] {
    const collapsed = (body as { collapsed?: unknown } | null)?.collapsed;
    return Array.isArray(collapsed) ? collapsed.filter((card): card is string => typeof card === 'string') : [];
}

export function useCardLayout(signedIn: boolean, fetchImpl: typeof fetch = browserFetch): CardLayout | 'loading' {
    // What the server said: the collapsed cards, and whether changes can be saved there.
    const [saved, setSaved] = useState<{ collapsed: string[]; canSave: boolean } | undefined>(undefined);

    // Ask the server once someone is signed in. `cancelled` stops a late answer after the page moved on.
    useEffect(() => {
        if (!signedIn) {
            return;
        }
        let cancelled = false;
        void fetchImpl('/api/card-settings', { credentials: 'same-origin' })
            .then(async (res) =>
                res.ok
                    ? { collapsed: collapsedFrom(await res.json()), canSave: true }
                    : { collapsed: [], canSave: false },
            )
            .catch(() => ({ collapsed: [], canSave: false }))
            .then((result) => {
                if (!cancelled) {
                    setSaved(result);
                }
            });
        return () => {
            cancelled = true;
        };
    }, [signedIn, fetchImpl]);

    // The layout object handed to the cards; rebuilt only when the saved state changes.
    return useMemo(() => {
        if (saved === undefined) {
            return 'loading';
        }
        return {
            isOpen: (card: string) => !saved.collapsed.includes(card),
            setOpen: (card: string, open: boolean) => {
                // Show the change at once; the save happens in the background.
                const others = saved.collapsed.filter((c) => c !== card);
                setSaved({ ...saved, collapsed: open ? others : [...others, card] });
                if (saved.canSave) {
                    // A failed save is ignored: the card still shows the user's choice for this visit.
                    void fetchImpl('/api/card-settings', {
                        method: 'PUT',
                        credentials: 'same-origin',
                        headers: { 'content-type': 'application/json' },
                        body: JSON.stringify({ card, open }),
                    }).catch(() => undefined);
                }
            },
        };
    }, [saved, fetchImpl]);
}
