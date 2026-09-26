import { describe, expect, it } from 'vitest';
import type { ListDates } from '../../../web/src/api/listDates';
import { mapProblemList } from '../../../web/src/mappers/problems';
import { loadFixture } from '../fixtures/load';

// The standard API's problem list (GET /api/patient/:puuid/medical_problem), recorded with
// spike/api-get.mjs. It is the old card's own source: every problem row, whatever its activity, and a
// problem linked to two visits comes back twice with the same uuid (TP-TYPICAL Type 2 diabetes).
// TP-HISTORY has "Community-acquired pneumonia" (ended 2024-12-15) and a Fee Sheet problem with no
// activity, which FHIR leaves out (BM-051).
interface Row {
    uuid: string;
    title: string;
    begdate: string | null;
    enddate: string | null;
    outcome: number;
}

function recorded(key: string): Map<string, ListDates> {
    const body = loadFixture<{ data: Row[] }>(`problem-list-${key}.json`);
    return new Map(
        body.data.map((row) => [
            row.uuid,
            { enddate: row.enddate, outcome: row.outcome, title: row.title, begdate: row.begdate },
        ]),
    );
}

const TODAY = '2026-09-26';
const names = (rows: Map<string, ListDates>, today = TODAY) => mapProblemList(rows, today).map((p) => p.name);

describe('mapProblemList', () => {
    it('shows every current problem once, as the old card does (BM-017, BM-043 superseded)', () => {
        expect(names(recorded('TP-TYPICAL'))).toEqual([
            'Type 2 diabetes mellitus',
            'Essential hypertension',
            'Hyperlipidaemia',
        ]);
    });

    it('a Fee Sheet problem with no activity is shown, although FHIR leaves it out (BM-051)', () => {
        expect(names(recorded('TP-HISTORY'))).toEqual(['Fee sheet problem']);
    });

    it('a problem whose end date has passed, or marked resolved, is hidden', () => {
        const rows = new Map<string, ListDates>([
            ['a', { title: 'Ended', begdate: '2020-01-01 00:00:00', enddate: '2021-01-01 00:00:00', outcome: 0 }],
            ['b', { title: 'Resolved', begdate: '2020-01-01 00:00:00', enddate: null, outcome: 1 }],
            ['c', { title: 'Ends later', begdate: '2020-01-01 00:00:00', enddate: '2027-01-01 00:00:00', outcome: 0 }],
        ]);

        expect(names(rows)).toEqual(['Ends later']);
    });

    it('oldest start date first, a missing start first, as ORDER BY begdate (BM-018)', () => {
        const long = names(recorded('TP-LONG'));
        expect(long).toHaveLength(60);
        expect(long[0]).toBe('Long-list problem 60');
        expect(long[59]).toBe('Long-list problem 01');

        const rows = new Map<string, ListDates>([
            ['d', { title: 'Dated', begdate: '2020-01-01 00:00:00', enddate: null, outcome: 0 }],
            ['u', { title: 'Undated', begdate: null, enddate: null, outcome: 0 }],
        ]);
        expect(names(rows)).toEqual(['Undated', 'Dated']);
    });

    it('a problem with no title is "Unnamed problem"', () => {
        const rows = new Map<string, ListDates>([['x', { title: '  ', begdate: null, enddate: null, outcome: 0 }]]);
        expect(names(rows)).toEqual(['Unnamed problem']);
    });

    it('TP-EMPTY has no problems', () => {
        expect(names(recorded('TP-EMPTY'))).toEqual([]);
    });
});
