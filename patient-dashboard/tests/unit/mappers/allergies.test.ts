// @vitest-environment jsdom
import { describe, expect, it } from 'vitest';
import type { AllergyIntolerance, Bundle } from 'fhir/r4';
import { mapAllergies } from '../../../web/src/mappers/allergies';
import { loadFixture } from '../fixtures/load';

// AllergyIntolerance bundles recorded from the dev stack with spike/fhir-get.mjs.
function recorded(key: string): AllergyIntolerance[] {
    const bundle = loadFixture<Bundle<AllergyIntolerance>>(`allergies-${key}.json`);
    return (bundle.entry ?? []).flatMap((entry) => (entry.resource === undefined ? [] : [entry.resource]));
}

function allergy(overrides: Partial<AllergyIntolerance>): AllergyIntolerance {
    return {
        resourceType: 'AllergyIntolerance',
        patient: { reference: 'Patient/p1' },
        clinicalStatus: { coding: [{ code: 'active' }] },
        ...overrides,
    };
}

describe('mapAllergies', () => {
    it('TP-TYPICAL: Penicillin (Low risk, reaction Hives) and Peanuts, in entry order', () => {
        expect(mapAllergies(recorded('TP-TYPICAL'))).toEqual([
            {
                id: 'a2d68325-cf44-4bb9-96c7-afad3ab1129d',
                name: 'Penicillin',
                reaction: 'Hives',
                risk: 'Low risk',
                high: false,
            },
            { id: 'a2d68326-2283-43f8-b3ec-c574b07c0f85', name: 'Peanuts', reaction: '', risk: '', high: false },
        ]);
    });

    it('uncoded name comes from narrative (BM-010), never the data-absent "Unknown"', () => {
        expect(mapAllergies(recorded('TP-TYPICAL')).map((a) => a.name)).not.toContain('Unknown');
    });

    it('a coded allergy uses its code display', () => {
        const coded = allergy({
            code: {
                coding: [{ system: 'http://snomed.info/sct', code: '91936005', display: 'Allergy to penicillin' }],
            },
            text: { status: 'additional', div: '<div>Penicillin</div>' },
        });
        expect(mapAllergies([coded])[0]?.name).toBe('Allergy to penicillin');
    });

    it('narrative markup is text, never HTML (BM-009, TP-ESCAPING)', () => {
        expect(mapAllergies(recorded('TP-ESCAPING'))[0]?.name).toBe('Latex x');
    });

    it('moderate maps to "Low risk", high is highlighted (BM-011)', () => {
        const high = allergy({
            id: 'h',
            criticality: 'high',
            text: { status: 'additional', div: '<div>Shellfish</div>' },
        });
        const unknown = allergy({
            id: 'u',
            criticality: 'unable-to-assess',
            text: { status: 'additional', div: '<div>Dust</div>' },
        });

        expect(mapAllergies([high, unknown])).toEqual([
            { id: 'h', name: 'Shellfish', reaction: '', risk: 'High risk', high: true },
            { id: 'u', name: 'Dust', reaction: '', risk: 'Unable to assess', high: false },
        ]);
    });

    it('inactive entries are hidden (BM-016)', () => {
        expect(mapAllergies(recorded('TP-HISTORY'))).toEqual([]);
    });

    it('no brackets without a risk level (BM-015): the view carries an empty risk', () => {
        expect(mapAllergies(recorded('TP-TYPICAL'))[1]?.risk).toBe('');
    });

    it('entry order is kept (BM-014)', () => {
        const names = mapAllergies(recorded('TP-LONG')).map((a) => a.name);
        expect(names).toHaveLength(25);
        expect(names[0]).toBe('Long-list allergen 01');
        expect(names[24]).toBe('Long-list allergen 25');
    });

    it('an allergy with no name anywhere is "Unnamed allergy"', () => {
        expect(mapAllergies([allergy({ id: 'x' })])[0]?.name).toBe('Unnamed allergy');
    });
});
