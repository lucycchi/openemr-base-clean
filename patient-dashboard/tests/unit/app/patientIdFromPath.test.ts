import { describe, expect, it } from 'vitest';
import { patientIdFromPath } from '../../../web/src/app/App';

describe('patientIdFromPath', () => {
    it('reads the FHIR id from /patient/:id', () => {
        expect(patientIdFromPath('/patient/a2d68325-7821-4a53-aa27-816ce437150f')).toBe(
            'a2d68325-7821-4a53-aa27-816ce437150f',
        );
        expect(patientIdFromPath('/patient/abc/')).toBe('abc');
    });

    it('returns undefined for any other path', () => {
        for (const path of ['/', '/patient', '/patient/', '/patient/a/b', '/other/abc']) {
            expect(patientIdFromPath(path), path).toBeUndefined();
        }
    });
});
