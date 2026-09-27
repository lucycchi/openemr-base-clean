import { describe, expect, it } from 'vitest';
import { createApp } from '../../../server/app';
import { SessionStore } from '../../../server/session';
import type { OAuthClient } from '../../../server/oauth';

const PATIENT = 'a2d68325-7821-4a53-aa27-816ce437150f';

function setup(signedIn = true) {
    const now = () => 1_000_000;
    const store = new SessionStore({ ttlMs: 3_600_000, now });
    const session = store.create();
    if (signedIn) {
        session.tokens = { accessToken: 'at', expiresAt: now() + 3_600_000 };
    }
    const fetchImpl = (async () => Response.json({ data: { uuid: PATIENT, pid: 36 } })) as unknown as typeof fetch;
    const app = createApp({
        openemrLink: {
            store,
            oauth: {} as OAuthClient,
            now,
            apiBase: 'https://o/apis/default/api',
            oemrPublicUrl: 'https://o',
            fetchImpl,
        },
    });
    return { app, cookie: `pd_sid=${session.id}` };
}

describe('GET /openemr/patient/:uuid (the "Edit in OpenEMR" button)', () => {
    it("sends the browser to that patient's chart page in OpenEMR", async () => {
        const { app, cookie } = setup();
        const res = await app.request(`/openemr/patient/${PATIENT}`, { headers: { cookie } });
        expect(res.status).toBe(302);
        expect(res.headers.get('location')).toBe(
            'https://o/interface/patient_file/summary/demographics.php?set_pid=36',
        );
    });

    it('answers 401 when signed out and 400 for something that is not a uuid', async () => {
        const out = setup(false);
        expect((await out.app.request(`/openemr/patient/${PATIENT}`, { headers: { cookie: out.cookie } })).status).toBe(
            401,
        );
        const { app, cookie } = setup();
        expect((await app.request('/openemr/patient/36', { headers: { cookie } })).status).toBe(400);
    });
});
