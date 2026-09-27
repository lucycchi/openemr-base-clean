import { describe, expect, it } from 'vitest';
import { lookupPid, PatientLookupError } from '../../../server/patientLookup';

const UUID = 'a2d68325-7821-4a53-aa27-816ce437150f';
const answering = (status: number, body: unknown) =>
    (async () => new Response(JSON.stringify(body), { status })) as unknown as typeof fetch;

describe('lookupPid', () => {
    it('reads the numeric pid, which OpenEMR may send as text', async () => {
        expect(await lookupPid('https://o/api', 't', UUID, answering(200, { data: { uuid: UUID, pid: '36' } }))).toBe(
            36,
        );
    });

    it('refuses an answer for another patient, or without a pid', async () => {
        for (const data of [{ uuid: 'other', pid: 36 }, { uuid: UUID }]) {
            await expect(lookupPid('https://o/api', 't', UUID, answering(200, { data }))).rejects.toBeInstanceOf(
                PatientLookupError,
            );
        }
    });

    it('refuses an error answer', async () => {
        await expect(lookupPid('https://o/api', 't', UUID, answering(403, {}))).rejects.toBeInstanceOf(
            PatientLookupError,
        );
    });
});
