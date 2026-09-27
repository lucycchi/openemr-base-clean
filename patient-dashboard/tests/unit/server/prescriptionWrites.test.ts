import { describe, expect, it } from 'vitest';
import { createApp } from '../../../server/app';
import { SessionStore } from '../../../server/session';
import type { OAuthClient } from '../../../server/oauth';

const PATIENT = 'a2d68325-7821-4a53-aa27-816ce437150f';
const OTHER = 'b3e79436-8932-4b64-bb38-927df548261a';
const RX = 'c4f8a547-9a43-4c75-8c49-a38e0a59372b';
const NOW = Date.parse('2026-09-27T12:00:00Z');
const form = {
    drug: 'Amoxicillin 500 mg',
    dosage: '',
    quantity: '21',
    refills: 0,
    startDate: '2026-09-27',
    dateAdded: '2026-09-27 12:00:00',
    prescriber: '',
};

interface Call {
    method: string;
    url: string;
    body?: unknown;
}

/** A fake OpenEMR: the patient lookup, one prescription record, and the insert and delete answers. */
function fakeOpenEmr(options: { rx?: Record<string, unknown>; insertStatus?: number; deleteStatus?: number } = {}) {
    const calls: Call[] = [];
    const fetchImpl = (async (url: string, init?: RequestInit) => {
        const method = init?.method ?? 'GET';
        calls.push({
            method,
            url,
            ...(init?.body === undefined ? {} : { body: JSON.parse(String(init.body)) as unknown }),
        });
        if (url.endsWith(`/patient/${PATIENT}`)) {
            return Response.json({ data: { uuid: PATIENT, pid: '42' } });
        }
        if (method === 'GET' && url.endsWith(`/prescription/${RX}`)) {
            return Response.json({
                data: [options.rx ?? { uuid: RX, puuid: PATIENT, source_table: 'prescriptions', active: '1' }],
            });
        }
        if (method === 'POST' && url.endsWith('/prescription')) {
            return Response.json({ data: { id: 9, uuid: 'new-uuid' } }, { status: options.insertStatus ?? 201 });
        }
        if (method === 'DELETE') {
            return Response.json({ data: { message: 'record deleted' } }, { status: options.deleteStatus ?? 200 });
        }
        return new Response('', { status: 404 });
    }) as unknown as typeof fetch;
    return { calls, fetchImpl };
}

function setup(openemr = fakeOpenEmr()) {
    const now = () => NOW;
    const store = new SessionStore({ ttlMs: 3_600_000, now });
    const session = store.create();
    session.tokens = { accessToken: 'at', expiresAt: NOW + 3_600_000 };
    const app = createApp({
        prescriptionWrites: {
            store,
            oauth: {} as OAuthClient,
            now,
            publicUrl: 'https://dash.test',
            apiBase: 'https://oemr.test/apis/default/api',
            fetchImpl: openemr.fetchImpl,
        },
    });
    const send = (path: string, body: unknown = form) =>
        app.request(path, {
            method: 'POST',
            headers: {
                cookie: `pd_sid=${session.id}`,
                origin: 'https://dash.test',
                'content-type': 'application/json',
            },
            body: JSON.stringify(body),
        });
    return { send, calls: openemr.calls };
}

describe('POST /api/prescriptions', () => {
    it('adds the prescription for the patient on screen, with the old form defaults', async () => {
        const { send, calls } = setup();
        const res = await send(`/api/prescriptions?patient=${PATIENT}`);
        expect(res.status).toBe(201);
        expect(await res.json()).toEqual({ uuid: 'new-uuid' });
        const insert = calls.find((call) => call.method === 'POST');
        expect(insert?.body).toMatchObject({
            patient_id: 42,
            drug: 'Amoxicillin 500 mg',
            active: 1,
            request_intent: 'order',
        });
    });

    it('answers 400 with the problems and sends nothing to OpenEMR when the form is wrong', async () => {
        const { send, calls } = setup();
        const res = await send(`/api/prescriptions?patient=${PATIENT}`, { ...form, drug: '' });
        expect(res.status).toBe(400);
        expect(Object.keys(((await res.json()) as { errors: object }).errors)).toEqual(['drug']);
        expect(calls).toEqual([]);
    });

    it('answers 400 for a patient that is not a uuid', async () => {
        const { send } = setup();
        expect((await send('/api/prescriptions?patient=36')).status).toBe(400);
    });

    it('answers 502 when OpenEMR refuses the insert', async () => {
        const { send } = setup(fakeOpenEmr({ insertStatus: 400 }));
        expect((await send(`/api/prescriptions?patient=${PATIENT}`)).status).toBe(502);
    });
});

describe('POST /api/prescriptions/:uuid/discontinue', () => {
    it('discontinues an active prescription of the patient on screen', async () => {
        const { send, calls } = setup();
        const res = await send(`/api/prescriptions/${RX}/discontinue?patient=${PATIENT}`, {});
        expect(res.status).toBe(200);
        expect(calls.some((call) => call.method === 'DELETE' && call.url.endsWith(`/prescription/${RX}`))).toBe(true);
    });

    it("never discontinues another patient's prescription or a medication-list entry (answers 404, no DELETE)", async () => {
        for (const rx of [
            { uuid: RX, puuid: OTHER, source_table: 'prescriptions', active: '1' },
            { uuid: RX, puuid: PATIENT, source_table: 'lists', active: '1' },
        ]) {
            const openemr = fakeOpenEmr({ rx });
            const { send } = setup(openemr);
            expect((await send(`/api/prescriptions/${RX}/discontinue?patient=${PATIENT}`, {})).status).toBe(404);
            expect(openemr.calls.some((call) => call.method === 'DELETE')).toBe(false);
        }
    });
});

describe('POST /api/prescriptions/:uuid/replace', () => {
    it('adds the corrected prescription first, then discontinues the old one', async () => {
        const { send, calls } = setup();
        const res = await send(`/api/prescriptions/${RX}/replace?patient=${PATIENT}`);
        expect(res.status).toBe(201);
        expect(await res.json()).toEqual({ added: 'new-uuid', discontinued: true });
        const order = calls.filter((call) => call.method !== 'GET').map((call) => call.method);
        expect(order).toEqual(['POST', 'DELETE']);
    });

    it('reports 207 when the new one was added but the old one could not be discontinued', async () => {
        const { send } = setup(fakeOpenEmr({ deleteStatus: 500 }));
        const res = await send(`/api/prescriptions/${RX}/replace?patient=${PATIENT}`);
        expect(res.status).toBe(207);
        expect(await res.json()).toEqual({ added: 'new-uuid', discontinued: false });
    });

    it("adds nothing when the old prescription is not this patient's", async () => {
        const openemr = fakeOpenEmr({ rx: { uuid: RX, puuid: OTHER, source_table: 'prescriptions', active: '1' } });
        const { send } = setup(openemr);
        expect((await send(`/api/prescriptions/${RX}/replace?patient=${PATIENT}`)).status).toBe(404);
        expect(openemr.calls.some((call) => call.method === 'POST')).toBe(false);
    });
});
