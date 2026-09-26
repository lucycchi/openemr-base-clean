// @vitest-environment jsdom
import { describe, expect, it } from 'vitest';
import type { Bundle, Condition } from 'fhir/r4';
import { mapProblems } from '../../../web/src/mappers/problems';
import { loadFixture } from '../fixtures/load';

// Condition?patient= bundles (every category) recorded from the dev stack with spike/fhir-get.mjs.
// TP-TYPICAL's Type 2 diabetes is linked to two visits, so FHIR leaves it out of problem-list-item
// and returns it twice as encounter-diagnosis (BM-043).
function recorded(key: string): Condition[] {
    const bundle = loadFixture<Bundle<Condition>>(`problems-${key}.json`);
    return (bundle.entry ?? []).flatMap((entry) => (entry.resource === undefined ? [] : [entry.resource]));
}

function condition(overrides: Partial<Condition>): Condition {
    return {
        resourceType: 'Condition',
        subject: { reference: 'Patient/p1' },
        category: [{ coding: [{ code: 'problem-list-item' }] }],
        ...overrides,
    };
}

describe('mapProblems', () => {
    it('resolved-per-FHIR problems are shown and labelled (BM-017)', () => {
        expect(mapProblems(recorded('TP-TYPICAL')).map((p) => [p.name, p.label])).toEqual([
            ['Type 2 diabetes mellitus', ''],
            ['Essential hypertension', 'recurrence'],
            ['Hyperlipidaemia', 'resolved per FHIR'],
        ]);
    });

    it('inactive problems are hidden (TP-HISTORY)', () => {
        expect(mapProblems(recorded('TP-HISTORY'))).toEqual([]);
    });

    it('TP-LONG order starts at "Long-list problem 60" (BM-018)', () => {
        const names = mapProblems(recorded('TP-LONG')).map((p) => p.name);

        expect(names).toHaveLength(60);
        expect(names[0]).toBe('Long-list problem 60');
        expect(names[59]).toBe('Long-list problem 01');
    });

    it('a problem with no onset sorts first, as MySQL puts a NULL begdate first', () => {
        const dated = condition({ id: 'd', onsetDateTime: '2020-01-01T00:00:00+00:00', code: { text: 'Dated' } });
        const undated = condition({ id: 'u', code: { text: 'Undated' } });

        expect(mapProblems([dated, undated]).map((p) => p.name)).toEqual(['Undated', 'Dated']);
    });

    it('names come from code.text, a real coding display, or the narrative, in that order', () => {
        const coded = condition({
            id: 'c',
            code: {
                coding: [{ system: 'http://snomed.info/sct', code: '44054006', display: 'Diabetes mellitus type 2' }],
            },
        });
        const narrative = condition({ id: 'n', text: { status: 'additional', div: '<div>Asthma</div>' } });
        const nothing = condition({ id: 'x' });

        expect(mapProblems([coded, narrative, nothing]).map((p) => p.name)).toEqual([
            'Diabetes mellitus type 2',
            'Asthma',
            'Unnamed problem',
        ]);
    });

    it('a problem linked to visits appears once, from its encounter-diagnosis entries (BM-043)', () => {
        const names = mapProblems(recorded('TP-TYPICAL')).map((p) => p.name);

        expect(names.filter((name) => name === 'Type 2 diabetes mellitus')).toHaveLength(1);
    });

    it('merged visit copies keep the most current status', () => {
        const visit = (id: string, status: string) =>
            condition({
                id,
                code: { text: 'Asthma' },
                onsetDateTime: '2020-01-01T00:00:00+00:00',
                category: [{ coding: [{ code: 'encounter-diagnosis' }] }],
                clinicalStatus: { coding: [{ code: status }] },
            });

        expect(mapProblems([visit('a', 'resolved'), visit('b', 'active')]).map((p) => [p.name, p.label])).toEqual([
            ['Asthma', ''],
        ]);
    });

    it('a problem-list entry is never merged with a visit entry of the same name', () => {
        const listed = condition({
            id: 'l',
            code: { text: 'Asthma' },
            onsetDateTime: '2020-01-01T00:00:00+00:00',
            category: [{ coding: [{ code: 'problem-list-item' }] }],
        });
        const visit = condition({
            id: 'v',
            code: { text: 'Asthma' },
            onsetDateTime: '2020-01-01T00:00:00+00:00',
            category: [{ coding: [{ code: 'encounter-diagnosis' }] }],
        });

        expect(mapProblems([listed, visit])).toHaveLength(2);
    });

    it('health concerns are not problems', () => {
        const concern = condition({
            id: 'h',
            code: { text: 'Worried about falls' },
            category: [{ coding: [{ code: 'health-concern' }] }],
        });

        expect(mapProblems([concern])).toEqual([]);
    });

    it('visit copies with the same name but different diagnosis codes are different problems', () => {
        const visit = (id: string, code: string) =>
            condition({
                id,
                code: { text: 'Fracture', coding: [{ system: 'http://hl7.org/fhir/sid/icd-10-cm', code }] },
                category: [{ coding: [{ code: 'encounter-diagnosis' }] }],
            });

        expect(mapProblems([visit('a', 'S52.501A'), visit('b', 'S82.001A')])).toHaveLength(2);
    });
});
