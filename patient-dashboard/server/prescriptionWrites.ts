/**
 * The dashboard's prescription writes, passed on to OpenEMR's Standard REST API with the signed-in
 * user's own token (runs on the server).
 * - POST /api/prescriptions?patient=<uuid>: add a prescription
 * - POST /api/prescriptions/<uuid>/discontinue?patient=<uuid>: mark it inactive (OpenEMR's "delete" for
 *   prescriptions only sets active = 0, the same as unticking "active" on the old form)
 * - POST /api/prescriptions/<uuid>/replace?patient=<uuid>: "change". OpenEMR has no prescription update,
 *   so the corrected prescription is added first and the old one discontinued after. If that last step
 *   fails the answer is 207, so the page can say both exist: a failure leaves two prescriptions, never none.
 *
 * Before any change to an existing prescription it is read back to confirm it belongs to the patient on
 * screen, is still active, and is a real prescription rather than a medication-list entry (OpenEMR's
 * prescription list mixes both, marked by `source_table`).
 */
import { Hono } from 'hono';
import type { Context } from 'hono';
import type { OAuthClient } from './oauth';
import { lookupPid, PatientLookupError } from './patientLookup';
import { carriedOver, openemrPrescriptionBody, parsePrescriptionInput } from './prescriptionInput';
import type { SessionStore } from './session';
import { guardWrite } from './writeGuard';

/** A uuid such as a2d68325-7821-4a53-aa27-816ce437150f. */
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
/** Give up on OpenEMR after 20 seconds. */
const TIMEOUT_MS = 20_000;

export interface PrescriptionWritesDeps {
    store: SessionStore;
    oauth: OAuthClient;
    now: () => number;
    /** The dashboard's public address; writes must come from it (writeGuard.ts). */
    publicUrl: string;
    /** OpenEMR's Standard REST API, e.g. https://localhost:9300/apis/default/api */
    apiBase: string;
    fetchImpl?: typeof fetch;
}

/** The form was wrong: a message per field, answered as 400. */
class InputError extends Error {
    constructor(readonly errors: Record<string, string>) {
        super('invalid prescription');
    }
}

export function prescriptionWritesRoutes(deps: PrescriptionWritesDeps): Hono {
    const fetchImpl = deps.fetchImpl ?? fetch;
    const routes = new Hono();

    /** One call to OpenEMR's Standard API with the user's token. */
    const call = (accessToken: string, method: string, path: string, body?: unknown) =>
        fetchImpl(`${deps.apiBase}/${path}`, {
            method,
            headers: {
                Authorization: `Bearer ${accessToken}`,
                Accept: 'application/json',
                'Content-Type': 'application/json',
            },
            ...(body === undefined ? {} : { body: JSON.stringify(body) }),
            signal: AbortSignal.timeout(TIMEOUT_MS),
        });

    /**
     * The prescription as OpenEMR reads it back, when it exists, is a real (not list) prescription, is active,
     * and is the patient's; otherwise undefined.
     */
    async function activePrescriptionOf(
        accessToken: string,
        patient: string,
        rx: string,
    ): Promise<Record<string, unknown> | undefined> {
        const res = await call(accessToken, 'GET', `prescription/${rx}`);
        if (!res.ok) {
            return undefined;
        }
        const body = (await res.json()) as { data?: unknown };
        // OpenEMR may answer with one record or a list holding it; either way, find the one asked for.
        const rows: unknown[] = Array.isArray(body.data) ? body.data : [body.data];
        const row = rows.find(
            (r): r is Record<string, unknown> => typeof r === 'object' && r !== null && 'uuid' in r && r.uuid === rx,
        );
        const owned =
            row !== undefined &&
            row.puuid === patient &&
            row.source_table === 'prescriptions' &&
            String(row.active) === '1';
        return owned ? row : undefined;
    }

    /**
     * Checks the form and adds one prescription; the new uuid, or undefined if OpenEMR did not save it.
     * `carried` holds what a Change keeps from the old prescription (prescriptionInput.ts carriedOver).
     */
    async function add(
        accessToken: string,
        patient: string,
        body: unknown,
        carried: Record<string, string> = {},
    ): Promise<string | undefined> {
        const input = parsePrescriptionInput(body, deps.now());
        if (!input.ok) {
            throw new InputError(input.errors);
        }
        const pid = await lookupPid(deps.apiBase, accessToken, patient, fetchImpl);
        const res = await call(accessToken, 'POST', 'prescription', openemrPrescriptionBody(input.value, pid, carried));
        if (res.status !== 201) {
            return undefined;
        }
        const created = (await res.json()) as { data?: { uuid?: unknown } };
        return typeof created.data?.uuid === 'string' ? created.data.uuid : undefined;
    }

    /** Marks one prescription inactive; true when OpenEMR confirmed it. */
    const discontinue = async (accessToken: string, rx: string) =>
        (await call(accessToken, 'DELETE', `prescription/${rx}`)).ok;

    routes.post('/', async (c) => {
        const guard = await guardWrite(c, deps);
        if (!guard.ok) {
            return guard.response;
        }
        const patient = c.req.query('patient') ?? '';
        if (!UUID.test(patient)) {
            return c.json({ error: 'patient must be a patient uuid' }, 400);
        }
        try {
            const uuid = await add(guard.accessToken, patient, await c.req.json().catch(() => undefined));
            return uuid === undefined
                ? c.json({ error: 'OpenEMR did not save the prescription' }, 502)
                : c.json({ uuid }, 201);
        } catch (error) {
            return failure(c, error);
        }
    });

    routes.post('/:rx/discontinue', async (c) => {
        const guard = await guardWrite(c, deps);
        if (!guard.ok) {
            return guard.response;
        }
        const patient = c.req.query('patient') ?? '';
        const rx = c.req.param('rx');
        if (!UUID.test(patient) || !UUID.test(rx)) {
            return c.json({ error: 'patient and prescription must be uuids' }, 400);
        }
        try {
            if ((await activePrescriptionOf(guard.accessToken, patient, rx)) === undefined) {
                return c.json({ error: 'No such active prescription' }, 404);
            }
            return (await discontinue(guard.accessToken, rx))
                ? c.json({ discontinued: rx })
                : c.json({ error: 'OpenEMR did not discontinue the prescription' }, 502);
        } catch (error) {
            return failure(c, error);
        }
    });

    routes.post('/:rx/replace', async (c) => {
        const guard = await guardWrite(c, deps);
        if (!guard.ok) {
            return guard.response;
        }
        const patient = c.req.query('patient') ?? '';
        const rx = c.req.param('rx');
        if (!UUID.test(patient) || !UUID.test(rx)) {
            return c.json({ error: 'patient and prescription must be uuids' }, 400);
        }
        try {
            const old = await activePrescriptionOf(guard.accessToken, patient, rx);
            if (old === undefined) {
                return c.json({ error: 'No such active prescription' }, 404);
            }
            // The corrected prescription keeps what the form does not edit (dose, code, note, ...).
            const added = await add(
                guard.accessToken,
                patient,
                await c.req.json().catch(() => undefined),
                carriedOver(old),
            );
            if (added === undefined) {
                return c.json({ error: 'OpenEMR did not save the prescription' }, 502);
            }
            // The corrected prescription exists now; a failure here must not hide that from the page.
            const discontinued = await discontinue(guard.accessToken, rx).catch(() => false);
            return c.json({ added, discontinued }, discontinued ? 201 : 207);
        } catch (error) {
            return failure(c, error);
        }
    });

    return routes;
}

/** Turns an error into the answer the page gets: field messages for a bad form, otherwise a plain 502. */
function failure(c: Context, error: unknown): Response {
    if (error instanceof InputError) {
        return c.json({ errors: error.errors }, 400);
    }
    if (error instanceof PatientLookupError) {
        return c.json({ error: 'The patient could not be found' }, 502);
    }
    console.error('Prescription write failed', { error: (error as Error).name });
    return c.json({ error: 'OpenEMR did not respond' }, 502);
}
