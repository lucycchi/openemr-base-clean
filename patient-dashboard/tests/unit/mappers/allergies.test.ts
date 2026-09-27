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

// TP-LONG's first three allergy rows as the standard API returns them (spike/api-get.mjs, 2026-09-26).
// FHIR calls allergen 02 active although it is marked resolved (BM-047), and allergen 03 inactive
// although it runs to 2027-12-31 (BM-016).
const TODAY = '2026-09-26';
const LONG_LIST = new Map([
    ['a2d6832d-1338-4065-b95a-303f0b805f04', { enddate: null, outcome: 0 }],
    ['a2d6832d-6331-4119-9115-66945ff551c9', { enddate: null, outcome: 1 }],
    ['a2d6832d-b5b6-45bc-8b07-99b2fa01e454', { enddate: '2027-12-31 00:00:00', outcome: 0 }],
]);
const firstThree = (
    key: string,
    dates: ReadonlyMap<string, { enddate: string | null; outcome: number }>,
    today: string,
) =>
    mapAllergies(recorded(key), dates, today)
        .map((a) => a.name)
        .filter((name) => /allergen 0[1-3]$/.test(name));

describe('mapAllergies', () => {
    it('an allergy marked resolved is hidden, although FHIR says active (BM-047)', () => {
        expect(firstThree('TP-LONG', LONG_LIST, TODAY)).not.toContain('Long-list allergen 02');
    });

    it('an allergy with a future end date is shown, although FHIR says inactive (BM-016)', () => {
        expect(firstThree('TP-LONG', LONG_LIST, TODAY)).toEqual(['Long-list allergen 01', 'Long-list allergen 03']);
    });

    it('an allergy whose end date has passed is hidden', () => {
        expect(firstThree('TP-LONG', LONG_LIST, '2028-01-01')).toEqual(['Long-list allergen 01']);
    });

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

    it('without list dates, inactive entries are hidden', () => {
        expect(mapAllergies(recorded('TP-HISTORY'))).toEqual([]);
    });

    it('no brackets without a risk level (BM-015): the view carries an empty risk', () => {
        expect(mapAllergies(recorded('TP-TYPICAL'))[1]?.risk).toBe('');
    });

    it('entry order is kept (BM-014)', () => {
        // Allergen 02 is resolved, so 24 of the 25 remain, still in entry order.
        const names = mapAllergies(recorded('TP-LONG'), LONG_LIST, TODAY).map((a) => a.name);
        expect(names).toHaveLength(24);
        expect(names[0]).toBe('Long-list allergen 01');
        expect(names[23]).toBe('Long-list allergen 25');
    });

    it('an allergy with no name anywhere is "Unnamed allergy"', () => {
        expect(mapAllergies([allergy({ id: 'x' })])[0]?.name).toBe('Unnamed allergy');
    });

    it("the name is the title the clinician entered, as the old card shows it, not the code's description (Opus review 4)", () => {
        const coded = allergy({
            id: 'coded',
            code: {
                coding: [
                    { system: 'http://www.nlm.nih.gov/research/umls/rxnorm', code: '7980', display: 'Penicillin G' },
                ],
            },
        });
        const dates = new Map([
            ['coded', { enddate: null, outcome: 0, title: 'Penicillin (childhood rash)', begdate: null }],
        ]);

        expect(mapAllergies([coded], dates, '2026-09-26 12:00:00')[0]?.name).toBe('Penicillin (childhood rash)');
        expect(mapAllergies([coded])[0]?.name).toBe('Penicillin G');
    });
});
