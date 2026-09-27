/**
 * Medication, allergy and problem list details that OpenEMR's FHIR API gets wrong, read instead from
 * OpenEMR's older Standard REST API.
 *
 * Runs on the server. The browser calls /api/list-dates?list=medication&patient=<patient uuid> (or
 * list=allergy, or list=medical_problem). This file checks the request, looks up the user's OpenEMR access
 * token in their session (session.ts), asks OpenEMR's Standard REST API for that list, checks every row
 * really belongs to that patient, and sends back only a few fields per row: id, title, start date, end date
 * and outcome (resolved or not). The page uses these to decide which entries are active, as the old
 * dashboard did. Anything unexpected in OpenEMR's answer means the whole list is refused (502) rather
 * than shown partly wrong.
 */
import { Hono } from 'hono';
import { getCookie } from 'hono/cookie';
import { SESSION_COOKIE } from './auth';
import type { OAuthClient } from './oauth';
import { lookupPid, PatientLookupError } from './patientLookup';
import { ensureFreshToken } from './session';
import type { SessionStore } from './session';

/**
 * A patient uuid: 32 hexadecimal characters (0-9 and a-f) in groups of 8-4-4-4-12 separated by dashes,
 * upper or lower case.
 */
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
/** The three lists this route serves; ListName is "one of these three words". */
const LISTS = ['medication', 'allergy', 'medical_problem'] as const;
type ListName = (typeof LISTS)[number];
/** Give up on OpenEMR after 20 seconds. */
const TIMEOUT_MS = 20_000;

/** What the route needs: the sessions, the login client, the clock and OpenEMR's two API addresses. */
export interface ListDatesDeps {
    store: SessionStore;
    oauth: OAuthClient;
    now: () => number;
    /** OpenEMR's Standard REST API base, for example https://localhost:9300/apis/default/api */
    apiBase: string;
    /** OpenEMR's FHIR base, used to check the problem list's permission (patients/med). */
    fhirBase: string;
    /** The function used to call OpenEMR; tests pass a fake one. */
    fetchImpl?: typeof fetch;
}

/** The only fields the dashboard takes from a list row. */
export interface ListRowDates {
    uuid: string;
    /** The end date as OpenEMR wrote it, or null when there is none. */
    enddate: string | null;
    /** 1 means resolved; the old cards hide those. */
    outcome: number;
    /** Every list: the title the clinician entered (the old cards show it) and the start date, for ordering. */
    title?: string;
    begdate?: string | null;
}

/** True when the value is an object with named fields (a "record"), not a number, text or nothing. */
function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null;
}

/** True when the value is missing, an empty list or an object with no fields. */
function isEmpty(value: unknown): boolean {
    return (
        value === undefined ||
        (Array.isArray(value) ? value.length === 0 : isRecord(value) && Object.keys(value).length === 0)
    );
}

/**
 * A row's dates, or undefined if it is malformed or does not belong to the patient.
 * `belongs` is a small test, supplied by the caller, that says whether a row is this patient's.
 */
function parseRow(
    value: unknown,
    belongs: (row: Record<string, unknown>) => boolean,
    withTitle = false,
): ListRowDates | undefined {
    if (!isRecord(value) || !belongs(value) || typeof value.uuid !== 'string') {
        return undefined;
    }
    // Take just these five fields out of the row; everything else in it is ignored.
    const { uuid, enddate, outcome, title, begdate } = value;
    if ((enddate !== null && typeof enddate !== 'string') || typeof outcome !== 'number') {
        return undefined;
    }
    if (!withTitle) {
        return { uuid, enddate, outcome };
    }
    if (typeof title !== 'string' || (begdate !== null && typeof begdate !== 'string')) {
        return undefined;
    }
    return { uuid, enddate, outcome, title, begdate };
}

/** OpenEMR's answer could not be trusted or did not arrive: the reply is 502. */
class Unavailable extends Error {}
/** The user is not allowed to see this list: the reply is 403. */
class Forbidden extends Error {}

/**
 * GET /api/list-dates?list=medication|allergy|medical_problem&patient=<uuid>. FHIR gets these lists
 * wrong: MedicationRequest calls every end-dated entry "completed" (BM-044), AllergyIntolerance calls a
 * resolved allergy with no end date "active" (BM-047), and the problem list leaves out any problem
 * without activity = 1 (BM-051). For these lists only, this reads OpenEMR's Standard REST API (user
 * decisions 2026-09-26) and returns uuid, end date, outcome, title and start date per row, nothing
 * else. It fails with 502 unless every row is the patient's.
 */
export function listDatesRoutes(deps: ListDatesDeps): Hono {
    const { store, oauth, now, apiBase, fhirBase } = deps;
    const fetchImpl = deps.fetchImpl ?? fetch;
    const routes = new Hono();

    routes.get('/', async (c) => {
        // 1. Check the request: a proper patient uuid and one of the three list names (400 = bad request).
        const patient = c.req.query('patient') ?? '';
        const list = c.req.query('list') ?? '';
        if (!UUID.test(patient) || !(LISTS as readonly string[]).includes(list)) {
            return c.json({ error: 'patient must be a patient uuid and list medication or allergy' }, 400);
        }
        // 2. The user must be logged in: find their session from the cookie and get a usable token.
        //    502 if OpenEMR does not answer the token renewal; 401 (not logged in) if there is no token.
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
        const accessToken = tokens.accessToken;

        // 3. Helpers, defined here so they can use the token. Each `async (...) => {...}` is a small function
        //    that waits for OpenEMR.

        /**
         * Reads one Standard REST API address with the user's token and returns the decoded JSON; any
         * failure status throws Unavailable.
         * `emptyOn404`: OpenEMR answers an empty medication list with a bodyless 404 (BM-046).
         */
        const get = async (path: string, emptyOn404 = false): Promise<unknown> => {
            const res = await fetchImpl(`${apiBase}/${path}`, {
                method: 'GET',
                headers: { Authorization: `Bearer ${accessToken}`, Accept: 'application/json' },
                signal: AbortSignal.timeout(TIMEOUT_MS),
            });
            const text = await res.text();
            if (emptyOn404 && res.status === 404 && text.trim() === '') {
                return [];
            }
            if (!res.ok) {
                throw new Unavailable(`HTTP ${res.status}`);
            }
            return JSON.parse(text) as unknown;
        };

        /**
         * The medication list. OpenEMR files it under the patient's internal number (pid), not the uuid, so
         * first look the patient up by uuid to get the pid, then read the list and check every row has that pid.
         */
        const medicationRows = async (): Promise<ListRowDates[]> => {
            // The patient's number, shared with the prescription writes (patientLookup.ts).
            const pid = await lookupPid(apiBase, accessToken, patient, fetchImpl).catch((error: unknown) => {
                throw new Unavailable(error instanceof PatientLookupError ? error.message : 'patient lookup failed');
            });
            // The patient was found above, so a bodyless 404 here can only mean an empty list.
            const rows = await get(`patient/${pid}/medication`, true);
            if (!Array.isArray(rows)) {
                throw new Unavailable('medication list is not a list');
            }
            // `.map(...)` handles each row in turn; `?? throwUnavailable()` means "if the row fails its checks,
            // refuse the whole list".
            return rows.map((row) => parseRow(row, (r) => r.pid === pid, true) ?? throwUnavailable());
        };

        /** The allergy and problem lists: wrapped in data, keyed by patient uuid. */
        const wrappedRows = async (path: string, withTitle: boolean): Promise<ListRowDates[]> => {
            const found = await get(`patient/${patient}/${path}`);
            // A bad patient id comes back as HTTP 200 with validation errors and an empty list.
            if (!isRecord(found) || !isEmpty(found.validationErrors) || !isEmpty(found.internalErrors)) {
                throw new Unavailable(`${path} list has errors`);
            }
            if (!Array.isArray(found.data)) {
                throw new Unavailable(`${path} list is not a list`);
            }
            return found.data.map((row) => parseRow(row, (r) => r.puuid === patient, withTitle) ?? throwUnavailable());
        };

        /**
         * The standard problem list checks encounters/notes (routes:221), but the old card checks the
         * issue ACL, patients/med (demographics.php:1096), which is what FHIR Condition checks. So the
         * user must be allowed a one-row Condition search for the patient first (Codex review 3).
         * (In plain terms: the two APIs check different permissions, so this first asks FHIR the same
         * permission question the old card asked. 401 or 403 there means "not allowed", and the reply is 403.)
         */
        const problemRows = async (): Promise<ListRowDates[]> => {
            const gate = await fetchImpl(`${fhirBase}/Condition?patient=${patient}&_count=1`, {
                method: 'GET',
                headers: { Authorization: `Bearer ${accessToken}`, Accept: 'application/fhir+json' },
                signal: AbortSignal.timeout(TIMEOUT_MS),
            });
            if (gate.status === 401 || gate.status === 403) {
                throw new Forbidden('no patients/med access');
            }
            if (!gate.ok) {
                throw new Unavailable(`problem permission check returned HTTP ${gate.status}`);
            }
            return wrappedRows('medical_problem', true);
        };

        // 4. Read the requested list and reply. If anything above failed: 403 when not allowed, otherwise
        //    log the reason on the server and reply 502 (OpenEMR did not answer properly). "no-store" tells
        //    the browser not to keep a copy of patient data.
        try {
            const entries =
                list === 'medication'
                    ? await medicationRows()
                    : list === 'allergy'
                      ? await wrappedRows('allergy', true)
                      : await problemRows();
            return c.json({ patient, list: list as ListName, entries }, 200, { 'cache-control': 'no-store' });
        } catch (error) {
            if (error instanceof Forbidden) {
                return c.json({ error: 'Not permitted to see this list' }, 403, { 'cache-control': 'no-store' });
            }
            console.error('Standard API list request failed', { list, error: (error as Error).message });
            return c.json({ error: 'OpenEMR did not return the list' }, 502, { 'cache-control': 'no-store' });
        }
    });

    return routes;
}

/** Stops reading the list because one row is malformed or not this patient's (see parseRow). */
function throwUnavailable(): never {
    throw new Unavailable('a row is malformed or belongs to another patient');
}
