import { Hono } from 'hono';
import { getCookie } from 'hono/cookie';
import type { Organization, Practitioner } from 'fhir/r4';
import { displayName, NAME_UNAVAILABLE } from '../web/src/mappers/people';
import { SESSION_COOKIE } from './auth';
import type { OAuthClient } from './oauth';
import { ensureFreshToken } from './session';
import type { SessionStore } from './session';
import type { SystemTokenSource } from './systemToken';

/** Only staff and facility names; relative references with an id that cannot be a dot-segment. */
const NAMED_REFERENCE = /^(Practitioner|Organization)\/([A-Za-z0-9-][A-Za-z0-9.-]{0,63})$/;
const MAX_REFERENCES = 50;
const TIMEOUT_MS = 15_000;

export interface DisplayNamesDeps {
    store: SessionStore;
    oauth: OAuthClient;
    now: () => number;
    /** OpenEMR's FHIR base, for example https://localhost:9300/apis/default/fhir */
    fhirBase: string;
    systemToken: SystemTokenSource;
    fetchImpl?: typeof fetch;
}

function isNamed(value: unknown, type: string, id: string): value is Practitioner | Organization {
    if (typeof value !== 'object' || value === null) {
        return false;
    }
    const resource = value as { resourceType?: unknown; id?: unknown };
    return resource.resourceType === type && resource.id === id;
}

/**
 * GET /api/display-names?ref=Practitioner/<id>&ref=Organization/<id>. OpenEMR's API lets only
 * administrators read Practitioner and Organization (admin/users), while the old dashboard showed
 * these names to anyone who could open the patient (Fable review F1). For a logged-in user, the BFF
 * reads them with its own system client and returns the display name only, never the resource.
 */
export function displayNamesRoutes(deps: DisplayNamesDeps): Hono {
    const { store, oauth, now, fhirBase, systemToken } = deps;
    const fetchImpl = deps.fetchImpl ?? fetch;
    const routes = new Hono();

    routes.get('/', async (c) => {
        const refs = c.req.queries('ref') ?? [];
        const parsed = refs.map((ref) => NAMED_REFERENCE.exec(ref));
        if (refs.length === 0 || refs.length > MAX_REFERENCES || parsed.some((match) => match === null)) {
            return c.json({ error: 'ref must be 1 to 50 Practitioner/<id> or Organization/<id> references' }, 400);
        }

        const session = store.get(getCookie(c, SESSION_COOKIE));
        let loggedIn;
        try {
            loggedIn = session !== undefined && (await ensureFreshToken(session, oauth, now)) !== undefined;
        } catch (error) {
            console.error('Token refresh failed', { error: (error as Error).name });
            return c.json({ error: 'OpenEMR did not respond' }, 502);
        }
        if (!loggedIn) {
            return c.json({ error: 'Not logged in' }, 401);
        }

        let token: string;
        try {
            token = await systemToken.getToken();
        } catch (error) {
            console.error('System token request failed', { error: (error as Error).message });
            return c.json({ error: 'Names are not available' }, 502, { 'cache-control': 'no-store' });
        }

        const names: Record<string, string> = {};
        for (const match of parsed) {
            const [ref = '', type = '', id = ''] = match ?? [];
            try {
                const res = await fetchImpl(`${fhirBase}/${ref}`, {
                    method: 'GET',
                    headers: { Authorization: `Bearer ${token}`, Accept: 'application/fhir+json' },
                    signal: AbortSignal.timeout(TIMEOUT_MS),
                });
                if (!res.ok) {
                    continue; // 404 for a user without an NPI (BM-028): the card shows "Name unavailable"
                }
                const resource = (await res.json()) as unknown;
                const name = isNamed(resource, type, id) ? displayName(resource) : NAME_UNAVAILABLE;
                if (name !== NAME_UNAVAILABLE) {
                    names[ref] = name;
                }
            } catch (error) {
                console.error('Name read failed', { type, error: (error as Error).name });
            }
        }
        return c.json({ names }, 200, { 'cache-control': 'no-store' });
    });

    return routes;
}
