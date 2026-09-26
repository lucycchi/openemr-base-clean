import { describe, expect, it } from 'vitest';
import { parseMedicationListDates } from '../../../web/src/api/medicationListDates';

const LISINOPRIL = 'a2d6832a-bf83-4fd5-a6da-ee15b8d4283a';

describe('parseMedicationListDates', () => {
    it('indexes each list row by uuid', () => {
        const result = parseMedicationListDates(
            { patient: 'p1', entries: [{ uuid: LISINOPRIL, enddate: '2027-06-30 00:00:00', outcome: 0 }] },
            'p1',
        );

        expect(result.ok && [...result.value]).toEqual([[LISINOPRIL, { enddate: '2027-06-30 00:00:00', outcome: 0 }]]);
    });

    it('an answer for another patient is a wrong-patient error (BM-004)', () => {
        expect(parseMedicationListDates({ patient: 'p2', entries: [] }, 'p1')).toEqual({
            ok: false,
            error: { kind: 'wrong-patient', expected: 'p1', found: 'p2' },
        });
    });

    it('anything malformed is invalid-response, never an empty list', () => {
        const broken: unknown[] = [
            null,
            { patient: 'p1' },
            { patient: 'p1', entries: 'no' },
            { patient: 'p1', entries: [{ uuid: 1, enddate: null, outcome: 0 }] },
            { patient: 'p1', entries: [{ uuid: 'u', enddate: 5, outcome: 0 }] },
            { patient: 'p1', entries: [{ uuid: 'u', enddate: null }] },
        ];
        for (const body of broken) {
            expect(parseMedicationListDates(body, 'p1'), JSON.stringify(body)).toEqual({
                ok: false,
                error: { kind: 'invalid-response' },
            });
        }
    });
});
