import { readFileSync } from 'node:fs';
import { describe, expect, it } from 'vitest';
import type { Patient } from 'fhir/r4';
import { mapHeader } from '../../../web/src/mappers/header';

// Patient resources recorded from the dev stack with spike/fhir-get.mjs.
function recorded(key: string): Patient {
    return JSON.parse(readFileSync(new URL(`../fixtures/patient-${key}.json`, import.meta.url), 'utf8')) as Patient;
}

const OPTIONS = { asOf: '2026-09-26', age: { format: 0, limitYears: 3 } } as const;

describe('mapHeader', () => {
    it('TP-TYPICAL: name, MRN, DOB with age, sex and status', () => {
        expect(mapHeader(recorded('TP-TYPICAL'), OPTIONS)).toEqual({
            id: 'a2d68325-7821-4a53-aa27-816ce437150f',
            name: 'Tessa Typical',
            mrn: '36',
            dobLine: 'DOB: 1958-03-14 Age: 68',
            sex: 'Female',
            status: 'Active',
        });
    });

    it('TP-TYPICAL status is "Active"', () => {
        expect(mapHeader(recorded('TP-TYPICAL'), OPTIONS).status).toBe('Active');
    });

    it('TP-DECEASED status is "Deceased (2025-11-02)" although Patient.active is true', () => {
        const patient = recorded('TP-DECEASED');
        expect(patient.active).toBe(true);

        const view = mapHeader(patient, OPTIONS);

        expect(view.status).toBe('Deceased (2025-11-02)');
        expect(view.dobLine).toBe('DOB: 1932-05-09 Age at death: 93');
    });

    it("TP-ESCAPING name renders O'Brien-Núñez as text", () => {
        expect(mapHeader(recorded('TP-ESCAPING'), OPTIONS).name).toBe("Zoë O'Brien-Núñez");
    });

    it('missing fields have explicit fallbacks, never undefined', () => {
        const bare: Patient = { resourceType: 'Patient', id: 'p9' };

        expect(mapHeader(bare, OPTIONS)).toEqual({
            id: 'p9',
            name: 'Name not recorded',
            mrn: '',
            dobLine: 'DOB: not recorded',
            sex: 'Unknown',
            status: 'Active',
        });
    });

    it('dates follow the site date format, as oeFormatShortDate does on the old header', () => {
        const us = { ...OPTIONS, dateFormat: 1 } as const;

        expect(mapHeader(recorded('TP-TYPICAL'), us).dobLine).toBe('DOB: 03/14/1958 Age: 68');
        expect(mapHeader(recorded('TP-DECEASED'), { ...OPTIONS, dateFormat: 2 }).status).toBe('Deceased (02/11/2025)');
    });
});
