import { describe, expect, it } from 'vitest';
import { createApp } from '../../../server/app';
import { SessionStore } from '../../../server/session';
import type { OAuthClient } from '../../../server/oauth';

const API_BASE = 'https://oemr.test/apis/default/api';
const TYPICAL = 'a2d68325-7821-4a53-aa27-816ce437150f';

// Shapes recorded from the dev stack with spike/api-get.mjs: the patient read is wrapped in "data";
// the medication list is a bare array of lists rows.
const patientBody = (uuid: string, pid: number) => ({ validationErrors: [], internalErrors: [], data: { pid, uuid } });
const medicationRows = [
    {
        id: 1242,
        uuid: 'a2d6832a-bf83-4fd5-a6da-ee15b8d4283a',
        pid: 36,
        title: 'Lisinopril 10 mg',
        enddate: '2027-06-30 00:00:00',
        outcome: 0,
        activity: 1,
        comments: 'not for the dashboard',
    },
];

type Upstream = Record<string, { status: number; body: unknown; raw?: string }>;

function setup(upstream: Upstream) {
    const now = () => 5_000_000;
    const store = new SessionStore({ ttlMs: 3_600_000, now });
    const oauth: OAuthClient = {
        authorizeUrl: () => 'unused',
        exchangeCode: async () => ({ access_token: 'unused', expires_in: 0 }),
        refresh: async () => ({ access_token: 'unused', expires_in: 0 }),
    };
    const calls: { url: string; authorization: string | null }[] = [];
    const fetchImpl = (async (input: string | URL | Request, init?: RequestInit) => {
        const url = String(input);
        calls.push({ url, authorization: new Headers(init?.headers).get('authorization') });
        const answer = upstream[url] ?? { status: 404, body: {} };
        return new Response(answer.raw ?? JSON.stringify(answer.body), { status: answer.status });
    }) as typeof fetch;
    const app = createApp({ listDates: { store, oauth, now, apiBase: API_BASE, fetchImpl } });
    const session = store.create();
    session.tokens = { accessToken: 'server-side-token', expiresAt: now() + 3_600_000 };
    return { app, calls, cookie: `pd_sid=${session.id}` };
}

const happy: Upstream = {
    [`${API_BASE}/patient/${TYPICAL}`]: { status: 200, body: patientBody(TYPICAL, 36) },
    [`${API_BASE}/patient/36/medication`]: { status: 200, body: medicationRows },
};

describe('list dates from the standard API (BM-044, BM-047)', () => {
    it('maps the patient uuid to its pid, reads the medication list, and returns only uuid, end date and outcome', async () => {
        const { app, calls, cookie } = setup(happy);

        const res = await app.request(`/api/list-dates?list=medication&patient=${TYPICAL}`, { headers: { cookie } });

        expect(res.status).toBe(200);
        expect(await res.json()).toEqual({
            patient: TYPICAL,
            list: 'medication',
            entries: [
                {
                    uuid: 'a2d6832a-bf83-4fd5-a6da-ee15b8d4283a',
                    enddate: '2027-06-30 00:00:00',
                    outcome: 0,
                },
            ],
        });
        expect(calls.map((call) => call.url)).toEqual([
            `${API_BASE}/patient/${TYPICAL}`,
            `${API_BASE}/patient/36/medication`,
        ]);
        expect(calls.every((call) => call.authorization === 'Bearer server-side-token')).toBe(true);
        expect(res.headers.get('cache-control')).toBe('no-store');
    });

    it('an empty medication list is empty, although OpenEMR answers it with a bodyless 404 (BM-046)', async () => {
        const empty: Upstream = {
            [`${API_BASE}/patient/${TYPICAL}`]: { status: 200, body: patientBody(TYPICAL, 36) },
            [`${API_BASE}/patient/36/medication`]: { status: 404, body: null, raw: '' },
        };
        const { app, cookie } = setup(empty);

        const res = await app.request(`/api/list-dates?list=medication&patient=${TYPICAL}`, { headers: { cookie } });

        expect(res.status).toBe(200);
        expect(await res.json()).toEqual({ patient: TYPICAL, list: 'medication', entries: [] });
    });

    it('a 404 that carries a body is still a failure, not an empty list', async () => {
        const routeMissing: Upstream = {
            [`${API_BASE}/patient/${TYPICAL}`]: { status: 200, body: patientBody(TYPICAL, 36) },
            [`${API_BASE}/patient/36/medication`]: { status: 404, body: { error: 'Route not found' } },
        };
        const { app, cookie } = setup(routeMissing);

        const res = await app.request(`/api/list-dates?list=medication&patient=${TYPICAL}`, { headers: { cookie } });

        expect(res.status).toBe(502);
    });

    it('returns 401 without a logged-in session', async () => {
        const { app, calls } = setup(happy);

        const res = await app.request(`/api/list-dates?list=medication&patient=${TYPICAL}`);

        expect(res.status).toBe(401);
        expect(calls).toHaveLength(0);
    });

    it('rejects a patient id that is not a uuid, before calling OpenEMR', async () => {
        const { app, calls, cookie } = setup(happy);

        for (const bad of ['', '36', '../36/medication', `${TYPICAL}/x`]) {
            const res = await app.request(`/api/list-dates?list=medication&patient=${encodeURIComponent(bad)}`, {
                headers: { cookie },
            });
            expect(res.status, bad).toBe(400);
        }
        expect(calls).toHaveLength(0);
    });

    it('fails with 502 when OpenEMR answers for a different patient', async () => {
        const wrongPatient: Upstream = {
            [`${API_BASE}/patient/${TYPICAL}`]: { status: 200, body: patientBody('someone-else', 36) },
            [`${API_BASE}/patient/36/medication`]: { status: 200, body: medicationRows },
        };
        const wrongRows: Upstream = {
            [`${API_BASE}/patient/${TYPICAL}`]: { status: 200, body: patientBody(TYPICAL, 36) },
            [`${API_BASE}/patient/36/medication`]: { status: 200, body: [{ ...medicationRows[0], pid: 37 }] },
        };
        for (const upstream of [wrongPatient, wrongRows]) {
            const { app, cookie } = setup(upstream);
            const res = await app.request(`/api/list-dates?list=medication&patient=${TYPICAL}`, {
                headers: { cookie },
            });
            expect(res.status).toBe(502);
        }
    });

    it('fails with 502 when OpenEMR refuses or sends something unexpected', async () => {
        const refused: Upstream = { [`${API_BASE}/patient/${TYPICAL}`]: { status: 403, body: {} } };
        const notAList: Upstream = {
            [`${API_BASE}/patient/${TYPICAL}`]: { status: 200, body: patientBody(TYPICAL, 36) },
            [`${API_BASE}/patient/36/medication`]: { status: 200, body: { data: 'nope' } },
        };
        for (const upstream of [refused, notAList]) {
            const { app, cookie } = setup(upstream);
            const res = await app.request(`/api/list-dates?list=medication&patient=${TYPICAL}`, {
                headers: { cookie },
            });
            expect(res.status).toBe(502);
        }
    });

    it('allergies: reads the patient uuid directly and returns uuid, end date and outcome', async () => {
        const allergies: Upstream = {
            [`${API_BASE}/patient/${TYPICAL}/allergy`]: {
                status: 200,
                body: {
                    validationErrors: [],
                    internalErrors: [],
                    data: [
                        {
                            uuid: 'a2d68325-cf44-4bb9-96c7-afad3ab1129d',
                            puuid: TYPICAL,
                            title: 'Penicillin',
                            enddate: null,
                            outcome: 0,
                            comments: 'not for the dashboard',
                        },
                    ],
                },
            },
        };
        const { app, calls, cookie } = setup(allergies);

        const res = await app.request(`/api/list-dates?list=allergy&patient=${TYPICAL}`, { headers: { cookie } });

        expect(res.status).toBe(200);
        expect(await res.json()).toEqual({
            patient: TYPICAL,
            list: 'allergy',
            entries: [{ uuid: 'a2d68325-cf44-4bb9-96c7-afad3ab1129d', enddate: null, outcome: 0 }],
        });
        expect(calls.map((call) => call.url)).toEqual([`${API_BASE}/patient/${TYPICAL}/allergy`]);
    });

    it('allergies: a row for another patient, or an answer with validation errors, is a failure', async () => {
        const wrongRow: Upstream = {
            [`${API_BASE}/patient/${TYPICAL}/allergy`]: {
                status: 200,
                body: {
                    validationErrors: [],
                    internalErrors: [],
                    data: [{ uuid: 'u', puuid: 'other', enddate: null, outcome: 0 }],
                },
            },
        };
        // OpenEMR answers a bad patient id with HTTP 200, validation errors and an empty list.
        const invalid: Upstream = {
            [`${API_BASE}/patient/${TYPICAL}/allergy`]: {
                status: 200,
                body: {
                    validationErrors: { uuid: { 'invalid or nonexisting value': 'value' } },
                    internalErrors: [],
                    data: [],
                },
            },
        };
        for (const upstream of [wrongRow, invalid]) {
            const { app, cookie } = setup(upstream);
            const res = await app.request(`/api/list-dates?list=allergy&patient=${TYPICAL}`, { headers: { cookie } });
            expect(res.status).toBe(502);
        }
    });

    it('only the medication and allergy lists', async () => {
        const { app, calls, cookie } = setup(happy);

        for (const list of ['', 'medical_problem', 'surgery']) {
            const res = await app.request(`/api/list-dates?list=${list}&patient=${TYPICAL}`, { headers: { cookie } });
            expect(res.status, list).toBe(400);
        }
        expect(calls).toHaveLength(0);
    });
});
