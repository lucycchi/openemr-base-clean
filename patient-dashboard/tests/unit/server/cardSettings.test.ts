import { mkdtempSync, readFileSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import { createApp } from '../../../server/app';
import { FileCardSettingsStore } from '../../../server/cardSettings';
import { SessionStore } from '../../../server/session';

const tempFile = () => join(mkdtempSync(join(tmpdir(), 'card-settings-')), 'card-settings.json');

// The old dashboard saves each card's collapsed state per user in OpenEMR's user settings
// (allergy_ps_expand and friends, library/ajax/user_settings.php), which the API cannot reach.
// The BFF keeps the same per-user choice in its own file instead.
describe('FileCardSettingsStore', () => {
    it('starts with nothing collapsed when there is no file yet', () => {
        const store = FileCardSettingsStore.load(tempFile());
        expect(store.collapsed('user-1')).toEqual([]);
    });

    it('remembers a collapsed card per user, and forgets it when the card is opened again', async () => {
        const store = FileCardSettingsStore.load(tempFile());
        await store.setOpen('user-1', 'allergies', false);
        await store.setOpen('user-1', 'medications', false);
        expect(store.collapsed('user-1')).toEqual(['allergies', 'medications']);
        expect(store.collapsed('user-2')).toEqual([]);

        await store.setOpen('user-1', 'allergies', true);
        expect(store.collapsed('user-1')).toEqual(['medications']);
    });

    it('keeps the choice across a restart, by writing it to the file', async () => {
        const file = tempFile();
        await FileCardSettingsStore.load(file).setOpen('user-1', 'care-team', false);
        expect(FileCardSettingsStore.load(file).collapsed('user-1')).toEqual(['care-team']);
    });

    it('creates the folder for the file when it does not exist yet', async () => {
        const file = join(mkdtempSync(join(tmpdir(), 'card-settings-')), 'new-folder', 'card-settings.json');
        await FileCardSettingsStore.load(file).setOpen('user-1', 'allergies', false);
        expect(FileCardSettingsStore.load(file).collapsed('user-1')).toEqual(['allergies']);
    });

    it('refuses to start on a damaged file rather than silently losing every setting', () => {
        const file = tempFile();
        writeFileSync(file, '{not json');
        expect(() => FileCardSettingsStore.load(file)).toThrow();
    });

    it('ignores cards it does not know in a stored file', () => {
        const file = tempFile();
        writeFileSync(file, JSON.stringify({ 'user-1': ['allergies', 'no-such-card', 7] }));
        expect(FileCardSettingsStore.load(file).collapsed('user-1')).toEqual(['allergies']);
    });

    it('stops taking new users when it is full, so the file cannot grow without bound', async () => {
        const store = FileCardSettingsStore.load(tempFile(), 2);
        await store.setOpen('user-1', 'allergies', false);
        await store.setOpen('user-2', 'allergies', false);
        await expect(store.setOpen('user-3', 'allergies', false)).rejects.toThrow();
        await store.setOpen('user-1', 'problems', false);
        expect(store.collapsed('user-1')).toEqual(['allergies', 'problems']);
    });
});

function signedInApp(options: { userId?: string } = {}) {
    const now = () => 1_000_000;
    const sessions = new SessionStore({ ttlMs: 3_600_000, now });
    const session = sessions.create();
    session.tokens = { accessToken: 'at', expiresAt: now() + 3_600_000 };
    if (options.userId !== undefined) {
        session.userId = options.userId;
    }
    const file = tempFile();
    const settings = FileCardSettingsStore.load(file);
    const app = createApp({ cardSettings: { store: sessions, now, settings } });
    return { app, cookie: `pd_sid=${session.id}`, file };
}

const put = (body: unknown, cookie: string) => ({
    method: 'PUT',
    headers: { cookie, 'content-type': 'application/json' },
    body: JSON.stringify(body),
});

describe('/api/card-settings', () => {
    it("returns and changes the signed-in user's collapsed cards", async () => {
        const { app, cookie, file } = signedInApp({ userId: 'user-1' });

        const first = await app.request('/api/card-settings', { headers: { cookie } });
        expect(first.status).toBe(200);
        expect(await first.json()).toEqual({ collapsed: [] });

        const changed = await app.request('/api/card-settings', put({ card: 'allergies', open: false }, cookie));
        expect(changed.status).toBe(200);
        expect(await changed.json()).toEqual({ collapsed: ['allergies'] });
        expect(JSON.parse(readFileSync(file, 'utf8'))).toEqual({ 'user-1': ['allergies'] });
    });

    it('answers 401 when nobody is signed in', async () => {
        const { app } = signedInApp({ userId: 'user-1' });
        expect((await app.request('/api/card-settings')).status).toBe(401);
        expect((await app.request('/api/card-settings', put({ card: 'allergies', open: false }, ''))).status).toBe(401);
    });

    it('answers 404 when the login did not say who the user is, so nothing is shared between users', async () => {
        const { app, cookie } = signedInApp();
        expect((await app.request('/api/card-settings', { headers: { cookie } })).status).toBe(404);
        expect((await app.request('/api/card-settings', put({ card: 'allergies', open: false }, cookie))).status).toBe(
            404,
        );
    });

    it('refuses an unknown card or a malformed change', async () => {
        const { app, cookie } = signedInApp({ userId: 'user-1' });
        for (const body of [{ card: 'nope', open: false }, { card: 'allergies' }, { card: 'allergies', open: 'no' }]) {
            expect((await app.request('/api/card-settings', put(body, cookie))).status, JSON.stringify(body)).toBe(400);
        }
        const notJson = await app.request('/api/card-settings', {
            method: 'PUT',
            headers: { cookie, 'content-type': 'text/plain' },
            body: 'allergies',
        });
        expect(notJson.status).toBe(415);
    });
});
