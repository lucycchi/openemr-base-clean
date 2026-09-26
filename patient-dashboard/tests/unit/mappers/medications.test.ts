import { describe, expect, it } from 'vitest';
import type { Bundle, MedicationRequest } from 'fhir/r4';
import { splitMedications } from '../../../web/src/mappers/medications';
import { loadFixture } from '../fixtures/load';

// MedicationRequest bundles recorded from the dev stack with spike/fhir-get.mjs. TP-TYPICAL holds two
// list medications (intent plan), two prescriptions (intent order), and Atorvastatin, a list row whose
// lists_medication entry was set to Order / Outpatient (BM-019). Amlodipine is a list row linked to its
// prescription, so FHIR returns it once, as the prescription (BM-020).
function recorded(key: string): MedicationRequest[] {
    const bundle = loadFixture<Bundle<MedicationRequest>>(`medications-${key}.json`);
    return (bundle.entry ?? []).flatMap((entry) => (entry.resource === undefined ? [] : [entry.resource]));
}

describe('splitMedications', () => {
    it('plan goes to Medications, order goes to Prescriptions (BM-019)', () => {
        const { medications, prescriptions } = splitMedications(recorded('TP-TYPICAL'));

        expect(medications.map((m) => m.name)).toEqual(['Metformin 500 mg', 'Lisinopril 10 mg']);
        expect(prescriptions.map((m) => m.name)).toEqual(['Amlodipine 5 mg', 'Omeprazole 20 mg', 'Atorvastatin 20 mg']);
    });

    it('linked Amlodipine appears once, under Prescriptions (BM-020)', () => {
        const { medications, prescriptions } = splitMedications(recorded('TP-TYPICAL'));
        const all = [...medications, ...prescriptions].map((m) => m.name);

        expect(all.filter((name) => name === 'Amlodipine 5 mg')).toHaveLength(1);
        expect(prescriptions.map((m) => m.name)).toContain('Amlodipine 5 mg');
    });

    it('prescriptions carry quantity, added date and dosage text, but not refills (BM-041)', () => {
        const { prescriptions } = splitMedications(recorded('TP-TYPICAL'));

        expect(prescriptions[0]).toEqual({
            id: 'a2d69579-9eef-47aa-a929-4ba32977bca3',
            name: 'Amlodipine 5 mg',
            dosage: '',
            quantity: '30',
            added: '2026-09-26 11:58:36',
        });
        expect(prescriptions[2]?.dosage).toBe('1 tablet at night');
    });

    it('a prescription with an end date is still shown, although FHIR calls it completed (BM-044)', () => {
        // TP-TYPICAL Omeprazole: active, end date 2027-03-31. The old card ignores the end date.
        const { prescriptions } = splitMedications(recorded('TP-TYPICAL'));

        expect(prescriptions.map((m) => m.name)).toContain('Omeprazole 20 mg');
    });

    it('prescriptions are sorted by date added, newest first', () => {
        const added = splitMedications(recorded('TP-TYPICAL')).prescriptions.map((m) => m.added);
        expect(added).toEqual([...added].sort().reverse());
    });

    it('only active requests are shown (TP-HISTORY: one stopped prescription, one completed list medication)', () => {
        expect(splitMedications(recorded('TP-HISTORY'))).toEqual({ medications: [], prescriptions: [] });
    });

    it('TP-LONG shows all 60 in FHIR order (BM-036)', () => {
        const names = splitMedications(recorded('TP-LONG')).medications.map((m) => m.name);

        expect(names).toHaveLength(60);
        expect(names[0]).toBe('Long-list medication 01');
    });
});
