/**
 * Staff and facility names for the dashboard's cards (for example a care team member or the facility of a
 * visit).
 *
 * Runs on the server. The browser calls /api/display-names?ref=Practitioner/<id>&ref=Organization/<id>.
 * OpenEMR's API only lets administrators read staff and facility records, but the old dashboard showed these
 * names to anyone who could open the patient. So, once it has checked the user is logged in, this file reads
 * the records with the BFF's own server-only OpenEMR login (systemToken.ts) and sends back just the display
 * name for each, never the full record. Names are remembered for 10 minutes so every page load does not
 * ask OpenEMR again. The reply is {"names": {ref: name}, "failed": [refs OpenEMR did not answer for]}.
 */
import { Hono } from 'hono';
import { getCookie } from 'hono/cookie';
import type { Organization, Practitioner } from 'fhir/r4';
import { displayName, NAME_UNAVAILABLE } from '../web/src/mappers/people';
import { SESSION_COOKIE } from './auth';
import type { OAuthClient } from './oauth';
import { ensureFreshToken } from './session';
import type { SessionStore } from './session';
import type { SystemTokenSource } from './systemToken';

/**
 * Only staff and facility names; relative references with an id that cannot be a dot-segment.
 * In words: "Practitioner/" or "Organization/", then an id of 1 to 64 letters, digits, dashes or dots that
 * does not start with a dot.
 */
const NAMED_REFERENCE = /^(Practitioner|Organization)\/([A-Za-z0-9-][A-Za-z0-9.-]{0,63})$/;
/** The most names asked for in one request. */
const MAX_REFERENCES = 50;
/** Give up on OpenEMR after 15 seconds. */
const TIMEOUT_MS = 15_000;
/** Names found, and 404s, are kept this long, so a busy dashboard does not re-read OpenEMR each load. */
const CACHE_MS = 10 * 60_000;

/** What the route needs: the sessions, the login clients, the clock, OpenEMR's FHIR address and a cache size. */
export interface DisplayNamesDeps {
    store: SessionStore;
    oauth: OAuthClient;
    now: () => number;
    /** OpenEMR's FHIR base, for example https://localhost:9300/apis/default/fhir */
    fhirBase: string;
    /** The BFF's own server-only OpenEMR login, used for the name reads. */
    systemToken: SystemTokenSource;
    /** The function used to call OpenEMR; tests pass a fake one. */
    fetchImpl?: typeof fetch;
    /** Most names kept at once; the oldest is dropped first (default 1000). */
    cacheMax?: number;
}

/** True when OpenEMR's answer really is the staff or facility record that was asked for (same type and id). */
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
    // The memory of names already looked up: a lookup table from reference to name and expiry time.
    // A name of `undefined` means "OpenEMR has no such record" (a 404), which is remembered too.
    const cache = new Map<string, { name: string | undefined; expiresAt: number }>();
    const cacheMax = deps.cacheMax ?? 1000;
    /**
     * Stores a name in the cache. A Map keeps entries in the order they were added, so deleting and
     * re-adding moves this one to the end; when the cache is over its size, the oldest entries (at the
     * front) are dropped.
     */
    const remember = (ref: string, name: string | undefined): void => {
        cache.delete(ref);
        cache.set(ref, { name, expiresAt: now() + CACHE_MS });
        while (cache.size > cacheMax) {
            const oldest = cache.keys().next().value;
            if (oldest === undefined) {
                break;
            }
            cache.delete(oldest);
        }
    };

    routes.get('/', async (c) => {
        // 1. Check the request: 1 to 50 references, each of the allowed shape (400 = bad request).
        const refs = c.req.queries('ref') ?? [];
        const parsed = refs.map((ref) => NAMED_REFERENCE.exec(ref));
        if (refs.length === 0 || refs.length > MAX_REFERENCES || parsed.some((match) => match === null)) {
            return c.json({ error: 'ref must be 1 to 50 Practitioner/<id> or Organization/<id> references' }, 400);
        }

        // 2. The user must be logged in (the user's own token is only checked here, not used for the reads).
        //    502 if OpenEMR does not answer a token renewal; 401 (not logged in) otherwise.
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

        // 3. Answer what we can from the cache; collect the rest to read from OpenEMR.
        const names: Record<string, string> = {};
        // References OpenEMR failed to answer (not a 404): the card says "Name couldn't be loaded".
        const failed: string[] = [];
        const toRead: RegExpExecArray[] = [];
        for (const match of parsed) {
            const ref = match?.[0] ?? '';
            const cached = cache.get(ref);
            if (cached !== undefined && cached.expiresAt > now()) {
                if (cached.name !== undefined) {
                    names[ref] = cached.name;
                }
            } else if (match !== null) {
                toRead.push(match);
            }
        }
        if (toRead.length === 0) {
            return c.json({ names, failed }, 200, { 'cache-control': 'no-store' });
        }

        // 4. Get the BFF's own OpenEMR token. If that fails, no names can be read: reply 502.
        let token: string;
        try {
            token = await systemToken.getToken();
        } catch (error) {
            console.error('System token request failed', { error: (error as Error).message });
            return c.json({ error: 'Names are not available' }, 502, { 'cache-control': 'no-store' });
        }

        // 5. Read each remaining record one after another. A failure on one name does not stop the others:
        //    that name goes into `failed` and the loop moves on (`continue`).
        for (const match of toRead) {
            // The whole reference, then its two parts: the type (Practitioner or Organization) and the id.
            const [ref = '', type = '', id = ''] = match;
            try {
                const res = await fetchImpl(`${fhirBase}/${ref}`, {
                    method: 'GET',
                    headers: { Authorization: `Bearer ${token}`, Accept: 'application/fhir+json' },
                    signal: AbortSignal.timeout(TIMEOUT_MS),
                });
                if (res.status === 404) {
                    // A user without an NPI (BM-028): the card shows "Name unavailable".
                    remember(ref, undefined);
                    continue;
                }
                if (!res.ok) {
                    failed.push(ref);
                    continue;
                }
                // Turn the record into a display name (the same rule the page uses); a record without a usable
                // name, or not the one asked for, counts as "no name".
                const resource = (await res.json()) as unknown;
                const name = isNamed(resource, type, id) ? displayName(resource) : NAME_UNAVAILABLE;
                const known = name === NAME_UNAVAILABLE ? undefined : name;
                remember(ref, known);
                if (known !== undefined) {
                    names[ref] = known;
                }
            } catch (error) {
                console.error('Name read failed', { type, error: (error as Error).name });
                failed.push(ref);
            }
        }
        return c.json({ names, failed }, 200, { 'cache-control': 'no-store' });
    });

    return routes;
}
