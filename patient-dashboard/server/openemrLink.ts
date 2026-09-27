/**
 * Where the "Edit in OpenEMR" button goes (runs on the server). OpenEMR's chart page needs its own patient
 * number (pid), which the page does not know, so the button points here and this route looks the pid up
 * and sends the browser on:
 *   GET /openemr/patient/<uuid> → 302 to <OpenEMR>/interface/patient_file/summary/demographics.php?set_pid=<pid>
 *
 * OpenEMR has no link that opens a patient inside its full screen with the menu (main.php only accepts a
 * one-time token), so this opens the chart page on its own. If the user is not signed in to OpenEMR they
 * see its login page, and after signing in OpenEMR goes to its home screen rather than this patient
 * (library/auth.inc.php:138-160), which is why the on-screen note says to click again.
 */
import { Hono } from 'hono';
import { getCookie } from 'hono/cookie';
import { SESSION_COOKIE } from './auth';
import type { OAuthClient } from './oauth';
import { lookupPid } from './patientLookup';
import { ensureFreshToken } from './session';
import type { SessionStore } from './session';

/** A uuid such as a2d68325-7821-4a53-aa27-816ce437150f. */
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

export interface OpenEmrLinkDeps {
    store: SessionStore;
    oauth: OAuthClient;
    now: () => number;
    /** OpenEMR's Standard REST API, used to look up the patient's number. */
    apiBase: string;
    /** OpenEMR's address as the user's browser reaches it (OEMR_PUBLIC_URL, default OEMR_BASE). */
    oemrPublicUrl: string;
    fetchImpl?: typeof fetch;
}

export function openemrLinkRoutes(deps: OpenEmrLinkDeps): Hono {
    const routes = new Hono();

    routes.get('/patient/:uuid', async (c) => {
        const patient = c.req.param('uuid');
        if (!UUID.test(patient)) {
            return c.text('Not a patient id', 400);
        }
        const session = deps.store.get(getCookie(c, SESSION_COOKIE));
        let tokens;
        try {
            tokens = session === undefined ? undefined : await ensureFreshToken(session, deps.oauth, deps.now);
        } catch {
            return c.text('OpenEMR did not respond. Try again.', 502);
        }
        if (tokens === undefined) {
            return c.text('Your dashboard session has ended. Sign in again.', 401);
        }
        try {
            const pid = await lookupPid(deps.apiBase, tokens.accessToken, patient, deps.fetchImpl ?? fetch);
            return c.redirect(
                `${deps.oemrPublicUrl}/interface/patient_file/summary/demographics.php?set_pid=${pid}`,
                302,
            );
        } catch {
            return c.text('OpenEMR could not find this patient.', 502);
        }
    });

    return routes;
}
