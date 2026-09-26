import { describe, expect, it } from 'vitest';
import { parseSiteConfig } from '../../../web/src/app/siteConfig';

const valid = {
    hiddenCards: ['card_prescriptions'],
    ageDisplay: { format: 0, limitYears: 3 },
    encounterPageSize: 20,
};

describe('parseSiteConfig', () => {
    it('accepts the settings the BFF serves', () => {
        expect(parseSiteConfig(valid)).toEqual(valid);
    });

    it('rejects anything malformed, so the app never guesses which cards the site hid', () => {
        const broken: unknown[] = [
            null,
            'nope',
            { error: 'Internal Server Error' },
            { ...valid, hiddenCards: 'card_prescriptions' },
            { ...valid, hiddenCards: ['card_allergys'] },
            { ...valid, ageDisplay: { format: 2, limitYears: 3 } },
            { ...valid, ageDisplay: { format: 0, limitYears: -1 } },
            { ...valid, encounterPageSize: -1 },
            { ...valid, encounterPageSize: 2.5 },
            { hiddenCards: [], ageDisplay: { format: 0, limitYears: 3 } },
        ];
        for (const body of broken) {
            expect(parseSiteConfig(body), JSON.stringify(body)).toBeUndefined();
        }
    });
});
