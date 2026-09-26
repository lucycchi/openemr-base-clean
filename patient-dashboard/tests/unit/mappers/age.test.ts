import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import { ageAtDeath, ageDisplay } from '../../../web/src/mappers/age';

// Expected strings are OpenEMR's own output for each date pair, produced by running
// PatientService::getPatientAge / getPatientAgeYMD and oeFormatAge inside the dev container
// (tmp/age_oracle.php) and saved as age.oracle.json.
interface Oracle {
    living: [string, string, string][];
    ymd: [string, string, number, string][];
    death: [string, string, string][];
}
const oracle = JSON.parse(readFileSync(new URL('./age.oracle.json', import.meta.url), 'utf8')) as Oracle;

const DEFAULT_SETTINGS = { format: 0, limitYears: 3 } as const; // this stack's age_display_format and age_display_limit

describe('ageDisplay (port of PatientService::getPatientAgeDisplay)', () => {
    it('68 for 1958-03-14 on 2026-09-26', () => {
        expect(ageDisplay('1958-03-14', '2026-09-26', DEFAULT_SETTINGS)).toBe('68');
    });

    it.each(oracle.living)('matches OpenEMR for %s on %s → %s', (dob, asOf, expected) => {
        expect(ageDisplay(dob, asOf, DEFAULT_SETTINGS)).toBe(expected);
    });

    it('months under 2 years, and exactly 24 months still in months (OpenEMR uses > 24)', () => {
        expect(ageDisplay('2026-01-15', '2026-09-26', DEFAULT_SETTINGS)).toBe('8 month');
        expect(ageDisplay('2024-09-26', '2026-09-26', DEFAULT_SETTINGS)).toBe('24 month');
    });

    it('the day before a birthday', () => {
        expect(ageDisplay('1990-09-27', '2026-09-26', DEFAULT_SETTINGS)).toBe('35');
    });

    it('leap-day birthday', () => {
        expect(ageDisplay('2000-02-29', '2026-02-28', DEFAULT_SETTINGS)).toBe('25');
        expect(ageDisplay('2000-02-29', '2026-03-01', DEFAULT_SETTINGS)).toBe('26');
    });

    it.each(oracle.ymd)(
        'with age_display_format 1 and a high limit, matches getPatientAgeYMD for %s on %s',
        (dob, asOf, _decimal, expected) => {
            expect(ageDisplay(dob, asOf, { format: 1, limitYears: 200 })).toBe(expected);
        },
    );

    it('with age_display_format 1, ages above the limit fall back to the plain age', () => {
        expect(ageDisplay('1958-03-14', '2026-09-26', { format: 1, limitYears: 3 })).toBe('68');
        expect(ageDisplay('2025-10-01', '2026-09-26', { format: 1, limitYears: 3 })).toBe('0y 11m 25d');
    });
});

describe('ageAtDeath (port of oeFormatAge, format 0)', () => {
    it('age at death 93 for TP-DECEASED', () => {
        expect(ageAtDeath('1932-05-09', '2025-11-02')).toBe('93');
    });

    it('years from 24 months, as OpenEMR does (oeFormatAge uses >= 24)', () => {
        expect(ageAtDeath('2023-06-05', '2025-06-05')).toBe('2');
    });

    it('months under 2 years, with the space and plural OpenEMR intended (BM-040)', () => {
        // OpenEMR prints "4months", "23months" and "1month" because of an operator-precedence bug.
        expect(oracle.death.map(([, , old]) => old)).toEqual(['93', '4months', '2', '23months', '1month']);
        expect(ageAtDeath('2025-01-10', '2025-06-05')).toBe('4 months');
        expect(ageAtDeath('2023-06-06', '2025-06-05')).toBe('23 months');
        expect(ageAtDeath('2024-12-01', '2025-01-01')).toBe('1 month');
    });
});
