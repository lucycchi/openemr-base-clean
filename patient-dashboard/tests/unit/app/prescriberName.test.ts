import { describe, expect, it } from 'vitest';
import { prescriberNameFrom } from '../../../web/src/app/prescriberName';

// The prescription form's Prescriber box starts with the signed-in user's name when it can be found (ARC-06).
describe('prescriberNameFrom', () => {
    it("reads the signed-in user's name from the names lookup", () => {
        expect(prescriberNameFrom({ names: { 'Practitioner/u1': 'Lee, Donna' }, failed: [] }, 'u1')).toBe('Lee, Donna');
    });

    it('starts empty when the name was not found, the lookup failed, or the answer is malformed', () => {
        expect(prescriberNameFrom({ names: {}, failed: [] }, 'u1')).toBe('');
        expect(prescriberNameFrom({ names: {}, failed: ['Practitioner/u1'] }, 'u1')).toBe('');
        expect(prescriberNameFrom({ names: { 'Practitioner/u1': 7 } }, 'u1')).toBe('');
        expect(prescriberNameFrom(undefined, 'u1')).toBe('');
    });
});
