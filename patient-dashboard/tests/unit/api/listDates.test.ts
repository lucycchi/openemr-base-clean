import { describe, expect, it } from 'vitest';
import { parseListDates } from '../../../web/src/api/listDates';

const LISINOPRIL = 'a2d6832a-bf83-4fd5-a6da-ee15b8d4283a';

describe('parseListDates', () => {
    it('indexes each list row by uuid', () => {
        const result = parseListDates(
            {
                patient: 'p1',
                list: 'medication',
                entries: [{ uuid: LISINOPRIL, enddate: '2027-06-30 00:00:00', outcome: 0 }],
            },
            'p1',
            'medication',
        );

        expect(result.ok && [...result.value]).toEqual([[LISINOPRIL, { enddate: '2027-06-30 00:00:00', outcome: 0 }]]);
    });

    it('an answer for another patient is a wrong-patient error (BM-004)', () => {
        expect(parseListDates({ patient: 'p2', list: 'medication', entries: [] }, 'p1', 'medication')).toEqual({
            ok: false,
            error: { kind: 'wrong-patient', expected: 'p1', found: 'p2' },
        });
    });

    it('an answer for the other list is invalid, never used', () => {
        expect(parseListDates({ patient: 'p1', list: 'medication', entries: [] }, 'p1', 'allergy')).toEqual({
            ok: false,
            error: { kind: 'invalid-response' },
        });
    });

    it('anything malformed is invalid-response, never an empty list', () => {
        const broken: unknown[] = [
            null,
            { patient: 'p1', list: 'allergy' },
            { patient: 'p1', list: 'allergy', entries: 'no' },
            { patient: 'p1', list: 'allergy', entries: [{ uuid: 1, enddate: null, outcome: 0 }] },
            { patient: 'p1', list: 'allergy', entries: [{ uuid: 'u', enddate: 5, outcome: 0 }] },
            { patient: 'p1', list: 'allergy', entries: [{ uuid: 'u', enddate: null }] },
        ];
        for (const body of broken) {
            expect(parseListDates(body, 'p1', 'allergy'), JSON.stringify(body)).toEqual({
                ok: false,
                error: { kind: 'invalid-response' },
            });
        }
    });

    it('a problem row carries its title and start date, and must have a title', () => {
        const row = { uuid: 'u', enddate: null, outcome: 0, title: 'Asthma', begdate: '2020-01-01 00:00:00' };
        const result = parseListDates(
            { patient: 'p1', list: 'medical_problem', entries: [row] },
            'p1',
            'medical_problem',
        );

        expect(result.ok && result.value.get('u')).toEqual({
            enddate: null,
            outcome: 0,
            title: 'Asthma',
            begdate: '2020-01-01 00:00:00',
        });
        expect(
            parseListDates(
                { patient: 'p1', list: 'medical_problem', entries: [{ ...row, title: undefined }] },
                'p1',
                'medical_problem',
            ),
        ).toEqual({ ok: false, error: { kind: 'invalid-response' } });
    });
});
