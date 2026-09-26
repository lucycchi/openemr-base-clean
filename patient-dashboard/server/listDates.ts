import { Hono } from 'hono';
import { getCookie } from 'hono/cookie';
import { SESSION_COOKIE } from './auth';
import type { OAuthClient } from './oauth';
import { ensureFreshToken } from './session';
import type { SessionStore } from './session';

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
const LISTS = ['medication', 'allergy', 'medical_problem'] as const;
type ListName = (typeof LISTS)[number];
const TIMEOUT_MS = 20_000;

export interface ListDatesDeps {
    store: SessionStore;
    oauth: OAuthClient;
    now: () => number;
    /** OpenEMR's Standard REST API base, for example https://localhost:9300/apis/default/api */
    apiBase: string;
    fetchImpl?: typeof fetch;
}

/** The only fields the dashboard takes from a list row. */
export interface ListRowDates {
    uuid: string;
    enddate: string | null;
    /** 1 means resolved; the old cards hide those. */
    outcome: number;
    /** Problems only: the card is built from this list, so it needs the title and start date (BM-051). */
    title?: string;
    begdate?: string | null;
}

function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null;
}

function isEmpty(value: unknown): boolean {
    return (
        value === undefined ||
        (Array.isArray(value) ? value.length === 0 : isRecord(value) && Object.keys(value).length === 0)
    );
}

/** A row's dates, or undefined if it is malformed or does not belong to the patient. */
function parseRow(
    value: unknown,
    belongs: (row: Record<string, unknown>) => boolean,
    withTitle = false,
): ListRowDates | undefined {
    if (!isRecord(value) || !belongs(value) || typeof value.uuid !== 'string') {
        return undefined;
    }
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

class Unavailable extends Error {}

/**
 * GET /api/list-dates?list=medication|allergy|medical_problem&patient=<uuid>. FHIR gets these lists
 * wrong: MedicationRequest calls every end-dated entry "completed" (BM-044), AllergyIntolerance calls a
 * resolved allergy with no end date "active" (BM-047), and the problem list leaves out any problem
 * without activity = 1 (BM-051). For these lists only, this reads OpenEMR's Standard REST API (user
 * decisions 2026-09-26) and returns uuid, end date and outcome per row, plus title and start date
 * for problems, nothing else. It fails with 502 unless every row is the patient's.
 */
export function listDatesRoutes(deps: ListDatesDeps): Hono {
    const { store, oauth, now, apiBase } = deps;
    const fetchImpl = deps.fetchImpl ?? fetch;
    const routes = new Hono();

    routes.get('/', async (c) => {
        const patient = c.req.query('patient') ?? '';
        const list = c.req.query('list') ?? '';
        if (!UUID.test(patient) || !(LISTS as readonly string[]).includes(list)) {
            return c.json({ error: 'patient must be a patient uuid and list medication or allergy' }, 400);
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
        const accessToken = tokens.accessToken;

        /** `emptyOn404`: OpenEMR answers an empty medication list with a bodyless 404 (BM-046). */
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

        const medicationRows = async (): Promise<ListRowDates[]> => {
            const found = await get(`patient/${patient}`);
            const data = isRecord(found) ? found.data : undefined;
            const pid = isRecord(data) && data.uuid === patient && Number.isInteger(data.pid) ? data.pid : undefined;
            if (typeof pid !== 'number') {
                throw new Unavailable('patient lookup did not match');
            }
            // The patient was found above, so a bodyless 404 here can only mean an empty list.
            const rows = await get(`patient/${pid}/medication`, true);
            if (!Array.isArray(rows)) {
                throw new Unavailable('medication list is not a list');
            }
            return rows.map((row) => parseRow(row, (r) => r.pid === pid) ?? throwUnavailable());
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

        try {
            const entries =
                list === 'medication'
                    ? await medicationRows()
                    : list === 'allergy'
                      ? await wrappedRows('allergy', false)
                      : await wrappedRows('medical_problem', true);
            return c.json({ patient, list: list as ListName, entries }, 200, { 'cache-control': 'no-store' });
        } catch (error) {
            console.error('Standard API list request failed', { list, error: (error as Error).message });
            return c.json({ error: 'OpenEMR did not return the list' }, 502, { 'cache-control': 'no-store' });
        }
    });

    return routes;
}

function throwUnavailable(): never {
    throw new Unavailable('a row is malformed or belongs to another patient');
}
