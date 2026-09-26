import { Hono } from 'hono';
import { getCookie } from 'hono/cookie';
import { SESSION_COOKIE } from './auth';
import type { OAuthClient } from './oauth';
import { ensureFreshToken } from './session';
import type { SessionStore } from './session';

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
const TIMEOUT_MS = 20_000;

export interface MedicationEndDatesDeps {
    store: SessionStore;
    oauth: OAuthClient;
    now: () => number;
    /** OpenEMR's Standard REST API base, for example https://localhost:9300/apis/default/api */
    apiBase: string;
    fetchImpl?: typeof fetch;
}

/** The only fields the dashboard takes from a medication-list row. */
export interface MedicationListDates {
    uuid: string;
    enddate: string | null;
    /** 1 means resolved; the old card hides those. */
    outcome: number;
}

function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null;
}

function parseRow(value: unknown, pid: number): MedicationListDates | undefined {
    if (!isRecord(value) || value.pid !== pid || typeof value.uuid !== 'string') {
        return undefined;
    }
    const { uuid, enddate, outcome } = value;
    if ((enddate !== null && typeof enddate !== 'string') || typeof outcome !== 'number') {
        return undefined;
    }
    return { uuid, enddate, outcome };
}

/**
 * GET /api/medication-end-dates?patient=<uuid>. FHIR MedicationRequest does not carry the end date
 * and calls every end-dated entry "completed" (BM-044), so for the medication list only, this reads
 * OpenEMR's Standard REST API (user decision 2026-09-26): patient uuid to pid, then that patient's
 * medication list. It returns uuid, end date and outcome per row, nothing else, and fails
 * with 502 unless every answer is for the requested patient.
 */
export function medicationEndDatesRoutes(deps: MedicationEndDatesDeps): Hono {
    const { store, oauth, now, apiBase } = deps;
    const fetchImpl = deps.fetchImpl ?? fetch;
    const routes = new Hono();

    routes.get('/', async (c) => {
        const patient = c.req.query('patient') ?? '';
        if (!UUID.test(patient)) {
            return c.json({ error: 'patient must be a patient uuid' }, 400);
        }
        const session = store.get(getCookie(c, SESSION_COOKIE));
        const tokens = session === undefined ? undefined : await ensureFreshToken(session, oauth, now);
        if (tokens === undefined) {
            return c.json({ error: 'Not logged in' }, 401);
        }

        /** `emptyOn404`: OpenEMR answers an empty list with a bodyless 404 (RestControllerHelper::responseHandler, BM-046). */
        const get = async (path: string, emptyOn404 = false): Promise<unknown> => {
            const res = await fetchImpl(`${apiBase}/${path}`, {
                method: 'GET',
                headers: { Authorization: `Bearer ${tokens.accessToken}`, Accept: 'application/json' },
                signal: AbortSignal.timeout(TIMEOUT_MS),
            });
            const text = await res.text();
            if (emptyOn404 && res.status === 404 && text.trim() === '') {
                return [];
            }
            if (!res.ok) {
                throw new Error(`HTTP ${res.status}`);
            }
            return JSON.parse(text) as unknown;
        };
        const unavailable = () =>
            c.json({ error: 'OpenEMR did not return the medication list' }, 502, { 'cache-control': 'no-store' });

        try {
            const found = await get(`patient/${patient}`);
            const data = isRecord(found) ? found.data : undefined;
            const pid = isRecord(data) && data.uuid === patient && Number.isInteger(data.pid) ? data.pid : undefined;
            if (typeof pid !== 'number') {
                return unavailable();
            }
            // The patient was found above, so a bodyless 404 here can only mean an empty list.
            const rows = await get(`patient/${pid}/medication`, true);
            if (!Array.isArray(rows)) {
                return unavailable();
            }
            const entries: MedicationListDates[] = [];
            for (const row of rows) {
                const parsed = parseRow(row, pid);
                if (parsed === undefined) {
                    return unavailable();
                }
                entries.push(parsed);
            }
            return c.json({ patient, entries }, 200, { 'cache-control': 'no-store' });
        } catch (error) {
            console.error('Standard API medication list request failed', { error: (error as Error).message });
            return unavailable();
        }
    });

    return routes;
}
