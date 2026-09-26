import { Hono } from 'hono';
import { getCookie } from 'hono/cookie';
import { SESSION_COOKIE } from './auth';
import type { OAuthClient } from './oauth';
import { ensureFreshToken } from './session';
import type { SessionStore } from './session';

/** The only FHIR resource types the dashboard reads. Everything else is refused. */
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

/** `Resource` or `Resource/id`, nothing else: no traversal (an id cannot start with a dot), no _history or operations. */
const PATH_PATTERN = /^([A-Z][A-Za-z]+)(?:\/([A-Za-z0-9-][A-Za-z0-9.-]{0,63}))?$/;

const TIMEOUT_MS = 20_000;

export interface FhirProxyDeps {
    store: SessionStore;
    oauth: OAuthClient;
    now: () => number;
    /** OpenEMR's FHIR base, for example https://localhost:9300/apis/default/fhir */
    fhirBase: string;
    fetchImpl?: typeof fetch;
}

/** Read-only, allow-listed pass-through to OpenEMR FHIR using the session's server-side token. */
export function fhirProxyRoutes(deps: FhirProxyDeps): Hono {
    const { store, oauth, now, fhirBase } = deps;
    const fetchImpl = deps.fetchImpl ?? fetch;
    const routes = new Hono();

    routes.all('*', async (c) => {
        if (c.req.method !== 'GET') {
            return c.json({ error: 'The FHIR proxy is read-only' }, 405);
        }

        const url = new URL(c.req.url);
        const path = decodeURIComponent(url.pathname.replace(/^\/api\/fhir\/?/, ''));
        const match = PATH_PATTERN.exec(path);
        const resource = match?.[1];
        if (resource === undefined || !ALLOWED_RESOURCES.has(resource)) {
            return c.json({ error: 'Resource not available through the dashboard' }, 400);
        }

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

        const query = new URLSearchParams(url.search);
        for (const name of STRIPPED_PARAMS) {
            query.delete(name);
        }
        // A search (no id) must be scoped to one patient, except the picker's Patient?name= search:
        // the old page only ever showed one patient's records (Fable review F10).
        // Exactly one patient value, and not a list: FHIR accepts patient=a,b and PHP reads the last of
        // several values.
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

        return new Response(await upstream.text(), {
            status: upstream.status,
            headers: { 'content-type': 'application/fhir+json; charset=utf-8', 'cache-control': 'no-store' },
        });
    });

    return routes;
}
