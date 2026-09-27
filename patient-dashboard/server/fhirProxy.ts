/**
 * The checkpoint between the dashboard page and OpenEMR's FHIR API (FHIR is the standard format for health
 * records that OpenEMR's modern API speaks).
 *
 * Runs on the server. The browser calls /api/fhir/<Resource>?patient=<id> (for example
 * /api/fhir/Condition?patient=123 for a patient's problems). This file checks the request is one the
 * dashboard is allowed to make: a read, of an allowed kind of record, for exactly one patient. It then
 * looks up the user's OpenEMR access token in their session (session.ts), passes the request on to
 * OpenEMR with that token, and sends OpenEMR's answer back unchanged. The browser never sees the token.
 * Anything not on the lists below is refused before OpenEMR is contacted.
 */
import { Hono } from 'hono';
import { getCookie } from 'hono/cookie';
import { SESSION_COOKIE } from './auth';
import type { OAuthClient } from './oauth';
import { ensureFreshToken } from './session';
import type { SessionStore } from './session';

/** The only FHIR resource types the dashboard reads. Everything else is refused. (A Set is a list with no repeats.) */
export const ALLOWED_RESOURCES: ReadonlySet<string> = new Set([
    'Patient',
    'AllergyIntolerance',
    'Condition',
    'MedicationRequest',
    'CareTeam',
    'Organization',
    'Practitioner',
    'RelatedPerson',
    'Encounter',
]);

/**
 * Query parameters never forwarded. OpenEMR answers `_include` on CareTeam with an empty
 * Bundle instead of an error (BM-029), which would read as "no care team".
 */
const STRIPPED_PARAMS = ['_include', '_revinclude'];

/**
 * The only query parameters the app sends. Anything else is refused: OpenEMR's apis/.htaccess appends
 * the query (QSA) and PHP takes the last _REWRITE_COMMAND, so a forwarded one would dispatch the
 * request to any API route, past this allow-list (Codex review 3).
 * (In plain terms: an extra parameter could trick OpenEMR into running a different API request.)
 */
const ALLOWED_PARAMS: ReadonlySet<string> = new Set(['patient', 'name']);

/**
 * `Resource` or `Resource/id`, nothing else: no traversal (an id cannot start with a dot), no _history or operations.
 * In words, the pattern matches: a capitalised word of letters (the resource type), then optionally a slash
 * and an id of 1 to 64 letters, digits, dashes or dots that does not start with a dot.
 */
const PATH_PATTERN = /^([A-Z][A-Za-z]+)(?:\/([A-Za-z0-9-][A-Za-z0-9.-]{0,63}))?$/;

/** Give up on OpenEMR after 20 seconds. */
const TIMEOUT_MS = 20_000;

/** What the proxy needs: the sessions, the login client (to renew tokens), the clock and OpenEMR's FHIR address. */
export interface FhirProxyDeps {
    store: SessionStore;
    oauth: OAuthClient;
    now: () => number;
    /** OpenEMR's FHIR base, for example https://localhost:9300/apis/default/fhir */
    fhirBase: string;
    /** The function used to call OpenEMR; tests pass a fake one. */
    fetchImpl?: typeof fetch;
}

/** Read-only, allow-listed pass-through to OpenEMR FHIR using the session's server-side token. */
export function fhirProxyRoutes(deps: FhirProxyDeps): Hono {
    const { store, oauth, now, fhirBase } = deps;
    const fetchImpl = deps.fetchImpl ?? fetch;
    const routes = new Hono();

    // One handler for every address under /api/fhir. The checks run in order; the first that fails sends
    // an error reply and stops.
    routes.all('*', async (c) => {
        // 1. Reads only. 405 = "that kind of request is not allowed here".
        if (c.req.method !== 'GET') {
            return c.json({ error: 'The FHIR proxy is read-only' }, 405);
        }

        // 2. The part of the address after /api/fhir/ must be an allowed resource type (and maybe an id).
        //    400 = bad request.
        const url = new URL(c.req.url);
        const path = decodeURIComponent(url.pathname.replace(/^\/api\/fhir\/?/, ''));
        const match = PATH_PATTERN.exec(path);
        const resource = match?.[1];
        if (resource === undefined || !ALLOWED_RESOURCES.has(resource)) {
            return c.json({ error: 'Resource not available through the dashboard' }, 400);
        }

        // 3. The user must be logged in: find their session from the cookie and get a usable token,
        //    renewing it if needed. If OpenEMR does not answer the renewal, reply 502 (OpenEMR did not answer
        //    properly); if there is no session or token, reply 401 (not logged in).
        const session = store.get(getCookie(c, SESSION_COOKIE));
        let tokens;
        try {
            tokens = session === undefined ? undefined : await ensureFreshToken(session, oauth, now);
        } catch (error) {
            console.error('Token refresh failed', { error: (error as Error).name });
            return c.json({ error: 'OpenEMR did not respond' }, 502);
        }
        if (tokens === undefined) {
            return c.json({ error: 'Not logged in' }, 401);
        }

        // 4. Drop the parameters OpenEMR mishandles, then refuse if any unknown parameter is left.
        const query = new URLSearchParams(url.search);
        for (const name of STRIPPED_PARAMS) {
            query.delete(name);
        }
        // `.some(...)` asks "is at least one of these true?"; `[...query.keys()]` is the list of parameter names.
        if ([...query.keys()].some((name) => !ALLOWED_PARAMS.has(name))) {
            return c.json({ error: 'Query parameter not available through the dashboard' }, 400);
        }
        // A search (no id) must be scoped to one patient, except the picker's Patient?name= search:
        // the old page only ever showed one patient's records (Fable review F10).
        // Exactly one patient value, and not a list: FHIR accepts patient=a,b and PHP reads the last of
        // several values.
        // The pattern allows the same id characters as above, so a comma (a list) is refused.
        const isSearch = match?.[2] === undefined;
        const patients = query.getAll('patient');
        const onePatient = patients.length === 1 && /^[A-Za-z0-9-][A-Za-z0-9.-]{0,63}$/.test(patients[0] ?? '');
        if (isSearch && resource !== 'Patient' && !onePatient) {
            return c.json({ error: 'A search must name one patient' }, 400);
        }
        if (patients.length > 1 || (patients.length === 1 && !onePatient)) {
            return c.json({ error: 'A search must name one patient' }, 400);
        }
        const search = query.toString();
        const upstreamUrl = `${fhirBase}/${path}${search === '' ? '' : `?${search}`}`;

        // 5. Pass the request on to OpenEMR with the user's token ("Bearer" = "whoever holds this token"),
        //    and wait for the answer. If OpenEMR does not answer in time, reply 502.
        let upstream: Response;
        try {
            upstream = await fetchImpl(upstreamUrl, {
                method: 'GET',
                headers: { Authorization: `Bearer ${tokens.accessToken}`, Accept: 'application/fhir+json' },
                signal: AbortSignal.timeout(TIMEOUT_MS),
            });
        } catch (error) {
            console.error('FHIR upstream request failed', { resource, error: (error as Error).name });
            return c.json({ error: 'OpenEMR did not respond' }, 502);
        }

        // 6. Send OpenEMR's answer back as it came, with the same status. "no-store" tells the browser not to
        //    keep a copy of patient data in its cache.
        return new Response(await upstream.text(), {
            status: upstream.status,
            headers: { 'content-type': 'application/fhir+json; charset=utf-8', 'cache-control': 'no-store' },
        });
    });

    return routes;
}
