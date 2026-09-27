/**
 * The page's one and only way of asking the server for data. Every card goes through the client made
 * here; nothing else in the web page talks to the network.
 *
 * In: a path such as "Condition?patient=123" (a FHIR search) or "list-dates?..." (a BFF route).
 * Out: a Result, which is either "ok, here is the data" or "not ok, here is why" (a LoadError). It never
 * throws and never turns a failure into an empty list, so a card can always tell "nothing recorded"
 * apart from "couldn't load".
 *
 * Requests go to the page's own server (the BFF), which adds the user's OpenEMR token and forwards them
 * to OpenEMR; the browser never holds the token. This file also holds assertBelongsTo, the check that
 * every record returned really belongs to the patient on screen.
 */
import type { Bundle, FhirResource, Reference, Resource } from 'fhir/r4';

/**
 * Every way a card's data can fail to load. A card shows these as "Couldn't load", never as empty.
 * The `|` bars mean "one of these shapes": signed out, no network, the server answered with an error
 * code (`status`, for example 403 or 500), the answer was not readable data, or the data was for a
 * different patient than the one asked for.
 */
export type LoadError =
    | { kind: 'unauthenticated' }
    | { kind: 'network' }
    | { kind: 'http'; status: number }
    | { kind: 'invalid-response' }
    | { kind: 'wrong-patient'; expected: string; found: string };

/**
 * The outcome of any request: either `ok: true` with the data (`value`), or `ok: false` with the reason
 * (`error`). `T` stands for whatever kind of data was asked for, so the same wrapper serves every card.
 */
export type Result<T> = { ok: true; value: T } | { ok: false; error: LoadError };

/**
 * Optional settings when making a client. The page uses the defaults; the tests swap in a fake network
 * (`fetchImpl`) so they can check behaviour without a real server. A `?` after a name means "optional".
 */
export interface ApiClientOptions {
    fetchImpl?: typeof fetch;
    /** Called on HTTP 401 (session gone or rejected). Defaults to sending the browser to the login route. */
    onUnauthenticated?: () => void;
    /** Proxy prefix on the BFF. */
    basePath?: string;
    /** Prefix of the BFF's own JSON routes (for example /api/list-dates). */
    bffPath?: string;
}

/**
 * What a client can do. Each call returns a Promise: an answer that arrives later, once the server
 * has replied.
 */
export interface ApiClient {
    /** Reads a single FHIR record, for example "Patient/123". */
    getResource<T extends FhirResource>(path: string): Promise<Result<T>>;
    /** Runs a FHIR search and returns the list of records found (FHIR wraps them in a "Bundle"). */
    getBundle<T extends FhirResource>(path: string): Promise<Result<T[]>>;
    /** A BFF JSON route under /api that is not a FHIR read; the caller parses the body. */
    getJson(path: string): Promise<Result<unknown>>;
}

/**
 * The page's one way to change something: send JSON to one of the BFF's write routes (ARC-06). The answer
 * is the HTTP status and the parsed body; it never throws. Status 0 means the request never reached the
 * server; a body that is not JSON comes back as undefined. Kept apart from ApiClient, which only reads.
 */
export interface WriteClient {
    sendJson(method: 'POST', path: string, body: unknown): Promise<{ status: number; body: unknown }>;
}

const FHIR_JSON = 'application/fhir+json';

/** What happens by default when the session has ended: go to the login page. */
function defaultRelogin(): void {
    // `?.` means "only if it exists": outside a browser (in tests) there is no location to change.
    globalThis.location?.assign('/auth/login');
}

/** The only code in the SPA that calls fetch. All FHIR reads go through the BFF's /api/fhir proxy. */
export function createApiClient(options: ApiClientOptions = {}): ApiClient & WriteClient {
    // `??` means "use the value on the left, or the default on the right if none was given".
    // `(input, init) => fetch(...)` is a short way of writing a small function (an "arrow function").
    const fetchImpl = options.fetchImpl ?? ((input, init) => fetch(input, init));
    const onUnauthenticated = options.onUnauthenticated ?? defaultRelogin;
    const basePath = options.basePath ?? '/api/fhir';
    const bffPath = options.bffPath ?? '/api';

    /**
     * Sends one request and sorts the answer into a Result. `async` marks a function that waits for the
     * server; each `await` is a point where it pauses until the answer arrives.
     */
    async function getJsonAt(url: string, accept: string): Promise<Result<unknown>> {
        let res: Response;
        try {
            // Send the browser's session cookie with the request, so the BFF knows who is asking.
            res = await fetchImpl(url, {
                credentials: 'same-origin',
                headers: { Accept: accept },
            });
        } catch {
            // The request never reached the server (offline, server down).
            return { ok: false, error: { kind: 'network' } };
        }
        if (res.status === 401) {
            // 401 means the session has ended: send the user to log in again.
            onUnauthenticated();
            return { ok: false, error: { kind: 'unauthenticated' } };
        }
        if (!res.ok) {
            // Any other error code (403 not allowed, 404 not found, 500 server fault, ...).
            return { ok: false, error: { kind: 'http', status: res.status } };
        }
        try {
            return { ok: true, value: (await res.json()) as unknown };
        } catch {
            // The server said "OK" but the body was not readable JSON.
            return { ok: false, error: { kind: 'invalid-response' } };
        }
    }

    /** True when a value looks like a FHIR record, that is, it has a text `resourceType` field. */
    function isResource(value: unknown): value is Resource {
        return typeof value === 'object' && value !== null && typeof (value as Resource).resourceType === 'string';
    }

    return {
        async getResource<T extends FhirResource>(path: string): Promise<Result<T>> {
            const result = await getJsonAt(`${basePath}/${path}`, FHIR_JSON);
            if (!result.ok) {
                return result;
            }
            // An OperationOutcome is FHIR's way of reporting a problem, so it is not the record we asked for.
            if (!isResource(result.value) || result.value.resourceType === 'OperationOutcome') {
                return { ok: false, error: { kind: 'invalid-response' } };
            }
            return { ok: true, value: result.value as T };
        },

        async getBundle<T extends FhirResource>(path: string): Promise<Result<T[]>> {
            const result = await getJsonAt(`${basePath}/${path}`, FHIR_JSON);
            if (!result.ok) {
                return result;
            }
            if (!isResource(result.value) || result.value.resourceType !== 'Bundle') {
                return { ok: false, error: { kind: 'invalid-response' } };
            }
            const bundle = result.value as Bundle<T>;
            // Unwrap the Bundle: take each entry's record (`.map`) and drop any empty entries (`.filter`).
            const resources = (bundle.entry ?? [])
                .map((entry) => entry.resource)
                .filter((resource): resource is T => resource !== undefined);
            return { ok: true, value: resources };
        },

        getJson(path: string): Promise<Result<unknown>> {
            return getJsonAt(`${bffPath}/${path}`, 'application/json');
        },

        async sendJson(method: 'POST', path: string, body: unknown): Promise<{ status: number; body: unknown }> {
            let res: Response;
            try {
                // The browser adds an Origin header to this same-site POST; the BFF checks it (writeGuard.ts).
                res = await fetchImpl(`${bffPath}/${path}`, {
                    method,
                    credentials: 'same-origin',
                    headers: { 'content-type': 'application/json', Accept: 'application/json' },
                    body: JSON.stringify(body),
                });
            } catch {
                // The request never reached the server (offline, server down).
                return { status: 0, body: undefined };
            }
            if (res.status === 401) {
                // The session has ended: send the user to log in again, as reads do.
                onUnauthenticated();
                return { status: 401, body: undefined };
            }
            // `.catch(() => undefined)`: a body that is not JSON (an error page) reads as no body.
            const parsed = (await res.json().catch(() => undefined)) as unknown;
            return { status: res.status, body: parsed };
        },
    };
}

/** The patient reference a clinical resource points at, by resource type. */
function patientReferenceOf(resource: FhirResource): Reference | undefined {
    // Different FHIR record types name the patient in different fields; pick the right one for each type.
    switch (resource.resourceType) {
        case 'AllergyIntolerance':
            return resource.patient;
        case 'Condition':
        case 'MedicationRequest':
        case 'CareTeam':
        case 'Encounter':
            return resource.subject;
        default:
            return undefined;
    }
}

/**
 * Checks that every clinical resource references the header patient. With a patient-bound token
 * OpenEMR returns an empty Bundle for another patient instead of an error (BM-004), and any
 * mismatch or missing reference must surface as a load error, never as data or an empty card.
 */
export function assertBelongsTo(patientId: string, resources: readonly FhirResource[]): Result<void> {
    for (const resource of resources) {
        const reference = patientReferenceOf(resource)?.reference;
        // The pattern /^.*Patient\// matches everything up to and including "Patient/", so removing it
        // turns "Patient/123" (or a full web address ending in it) into the bare id "123".
        const found = reference?.replace(/^.*Patient\//, '') ?? '(none)';
        if (reference === undefined || found !== patientId) {
            return { ok: false, error: { kind: 'wrong-patient', expected: patientId, found } };
        }
    }
    return { ok: true, value: undefined };
}
