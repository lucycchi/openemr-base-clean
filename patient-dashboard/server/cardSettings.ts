/**
 * Remembers which cards each user has collapsed, between visits. The old dashboard saves this per user
 * in OpenEMR's user settings (allergy_ps_expand and the like, through library/ajax/user_settings.php),
 * but that page needs an OpenEMR browser session and the API has no equivalent, so the dashboard server
 * keeps the same choice in its own small JSON file: { "<user id>": ["allergies", ...] }.
 *
 * GET /api/card-settings answers {"collapsed": [...]} for the signed-in user.
 * PUT /api/card-settings with {"card": "allergies", "open": false} collapses (or, with true, opens) one
 * card and answers the new list. Only a signed-in user whose login named them (see idToken.ts) can use
 * it; the settings are layout only and hold no patient data.
 */
import { existsSync, mkdirSync, readFileSync } from 'node:fs';
import { rename, writeFile } from 'node:fs/promises';
import { dirname } from 'node:path';
import { Hono } from 'hono';
import { getCookie } from 'hono/cookie';
import { SESSION_COOKIE } from './auth';
import type { SessionStore } from './session';

/** The cards that can be collapsed, by the label the page uses for each. */
export const COLLAPSIBLE_CARDS = [
    'allergies',
    'problems',
    'medications',
    'prescriptions',
    'care-team',
    'encounter-history',
] as const;
type Card = (typeof COLLAPSIBLE_CARDS)[number];

const isCard = (value: unknown): value is Card => (COLLAPSIBLE_CARDS as readonly unknown[]).includes(value);

/** Thrown when the file already holds the most users it may, and a new user tries to save. */
export class CardSettingsFullError extends Error {}

/** Each user's collapsed cards, kept in memory and written to one JSON file after every change. */
export class FileCardSettingsStore {
    /** Saves run one after another, so two quick changes can't write the file at the same moment. */
    private saving: Promise<void> = Promise.resolve();

    private constructor(
        private readonly file: string,
        private readonly byUser: Map<string, Card[]>,
        private readonly maxUsers: number,
    ) {}

    /**
     * Reads the file, or starts empty when it does not exist yet. A file that isn't valid JSON stops the
     * server from starting, rather than being overwritten and losing every user's settings.
     */
    static load(file: string, maxUsers = 10_000): FileCardSettingsStore {
        const byUser = new Map<string, Card[]>();
        // Make the folder now, so the first save has somewhere to write.
        mkdirSync(dirname(file), { recursive: true });
        if (existsSync(file)) {
            const stored: unknown = JSON.parse(readFileSync(file, 'utf8'));
            if (typeof stored !== 'object' || stored === null || Array.isArray(stored)) {
                throw new Error(`card settings file ${file} is not a JSON object`);
            }
            for (const [userId, cards] of Object.entries(stored)) {
                // Keep only card labels this version knows; anything else in the file is dropped.
                byUser.set(userId, Array.isArray(cards) ? cards.filter(isCard) : []);
            }
        }
        return new FileCardSettingsStore(file, byUser, maxUsers);
    }

    /** The cards this user has collapsed, in the order they collapsed them; none for a new user. */
    collapsed(userId: string): Card[] {
        return [...(this.byUser.get(userId) ?? [])];
    }

    /** Opens (true) or collapses (false) one card for one user, then saves the file. */
    async setOpen(userId: string, card: Card, open: boolean): Promise<void> {
        if (!this.byUser.has(userId) && this.byUser.size >= this.maxUsers) {
            throw new CardSettingsFullError('card settings store is full');
        }
        const others = (this.byUser.get(userId) ?? []).filter((c) => c !== card);
        this.byUser.set(userId, open ? others : [...others, card]);
        const json = JSON.stringify(Object.fromEntries(this.byUser));
        // Write a temporary file, then rename it over the real one: a crash mid-write leaves the old file whole.
        this.saving = this.saving.then(async () => {
            await writeFile(`${this.file}.tmp`, json, { mode: 0o600 });
            await rename(`${this.file}.tmp`, this.file);
        });
        await this.saving;
    }
}

export interface CardSettingsDeps {
    store: SessionStore;
    now: () => number;
    settings: FileCardSettingsStore;
}

export function cardSettingsRoutes(deps: CardSettingsDeps): Hono {
    const { store, now, settings } = deps;
    const routes = new Hono();

    /**
     * The signed-in user's id; 'signed-out' when there is no live login (401), and 'unknown' when the
     * login did not say who the user is (404), so one user's layout is never stored under another's.
     */
    function userOf(cookie: string | undefined): string | 'signed-out' | 'unknown' {
        const session = store.get(cookie);
        const tokens = session?.tokens;
        if (tokens === undefined || (tokens.expiresAt <= now() && tokens.refreshToken === undefined)) {
            return 'signed-out';
        }
        return session?.userId ?? 'unknown';
    }

    routes.get('/', (c) => {
        const user = userOf(getCookie(c, SESSION_COOKIE));
        if (user === 'signed-out') {
            return c.json({ error: 'not signed in' }, 401);
        }
        if (user === 'unknown') {
            return c.json({ error: 'user not known' }, 404);
        }
        return c.json({ collapsed: settings.collapsed(user) });
    });

    routes.put('/', async (c) => {
        const user = userOf(getCookie(c, SESSION_COOKIE));
        if (user === 'signed-out') {
            return c.json({ error: 'not signed in' }, 401);
        }
        if (user === 'unknown') {
            return c.json({ error: 'user not known' }, 404);
        }
        // Only a JSON body is accepted: a plain HTML form on another site cannot send one.
        if (!(c.req.header('content-type') ?? '').startsWith('application/json')) {
            return c.json({ error: 'expected JSON' }, 415);
        }
        const body: unknown = await c.req.json().catch(() => undefined);
        const { card, open } = (typeof body === 'object' && body !== null ? body : {}) as {
            card?: unknown;
            open?: unknown;
        };
        if (!isCard(card) || typeof open !== 'boolean') {
            return c.json({ error: 'expected {"card": <card>, "open": true|false}' }, 400);
        }
        try {
            await settings.setOpen(user, card, open);
        } catch (error) {
            if (error instanceof CardSettingsFullError) {
                return c.json({ error: 'settings store is full' }, 503);
            }
            throw error;
        }
        return c.json({ collapsed: settings.collapsed(user) });
    });

    return routes;
}
