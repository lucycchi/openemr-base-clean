import type { Bundle, FhirResource, Reference, Resource } from 'fhir/r4';

/** Every way a card's data can fail to load. A card shows these as "Couldn't load", never as empty. */
export type LoadError =
    | { kind: 'unauthenticated' }
    | { kind: 'network' }
    | { kind: 'http'; status: number }
    | { kind: 'invalid-response' }
    | { kind: 'wrong-patient'; expected: string; found: string };

export type Result<T> = { ok: true; value: T } | { ok: false; error: LoadError };

export interface ApiClientOptions {
    fetchImpl?: typeof fetch;
    /** Called on HTTP 401 (session gone or rejected). Defaults to sending the browser to the login route. */
    onUnauthenticated?: () => void;
    /** Proxy prefix on the BFF. */
    basePath?: string;
    /** Prefix of the BFF's own JSON routes (for example /api/medication-end-dates). */
    bffPath?: string;
}

export interface ApiClient {
    getResource<T extends FhirResource>(path: string): Promise<Result<T>>;
    getBundle<T extends FhirResource>(path: string): Promise<Result<T[]>>;
    /** A BFF JSON route under /api that is not a FHIR read; the caller parses the body. */
    getJson(path: string): Promise<Result<unknown>>;
}

const FHIR_JSON = 'application/fhir+json';

function defaultRelogin(): void {
    globalThis.location?.assign('/auth/login');
}

/** The only code in the SPA that calls fetch. All FHIR reads go through the BFF's /api/fhir proxy. */
export function createApiClient(options: ApiClientOptions = {}): ApiClient {
    const fetchImpl = options.fetchImpl ?? ((input, init) => fetch(input, init));
    const onUnauthenticated = options.onUnauthenticated ?? defaultRelogin;
    const basePath = options.basePath ?? '/api/fhir';
    const bffPath = options.bffPath ?? '/api';

    async function getJsonAt(url: string, accept: string): Promise<Result<unknown>> {
        let res: Response;
        try {
            res = await fetchImpl(url, {
                credentials: 'same-origin',
                headers: { Accept: accept },
            });
        } catch {
            return { ok: false, error: { kind: 'network' } };
        }
        if (res.status === 401) {
            onUnauthenticated();
            return { ok: false, error: { kind: 'unauthenticated' } };
        }
        if (!res.ok) {
            return { ok: false, error: { kind: 'http', status: res.status } };
        }
        try {
            return { ok: true, value: (await res.json()) as unknown };
        } catch {
            return { ok: false, error: { kind: 'invalid-response' } };
        }
    }

    function isResource(value: unknown): value is Resource {
        return typeof value === 'object' && value !== null && typeof (value as Resource).resourceType === 'string';
    }

    return {
        async getResource<T extends FhirResource>(path: string): Promise<Result<T>> {
            const result = await getJsonAt(`${basePath}/${path}`, FHIR_JSON);
            if (!result.ok) {
                return result;
            }
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
            const resources = (bundle.entry ?? [])
                .map((entry) => entry.resource)
                .filter((resource): resource is T => resource !== undefined);
            return { ok: true, value: resources };
        },

        getJson(path: string): Promise<Result<unknown>> {
            return getJsonAt(`${bffPath}/${path}`, 'application/json');
        },
    };
}

/** The patient reference a clinical resource points at, by resource type. */
function patientReferenceOf(resource: FhirResource): Reference | undefined {
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
        const found = reference?.replace(/^.*Patient\//, '') ?? '(none)';
        if (reference === undefined || found !== patientId) {
            return { ok: false, error: { kind: 'wrong-patient', expected: patientId, found } };
        }
    }
    return { ok: true, value: undefined };
}
