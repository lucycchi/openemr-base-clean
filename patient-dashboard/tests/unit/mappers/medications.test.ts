import { describe, expect, it } from 'vitest';
import type { Bundle, MedicationRequest } from 'fhir/r4';
import type { ListDates } from '../../../web/src/api/listDates';
import { splitMedications } from '../../../web/src/mappers/medications';
import { loadFixture } from '../fixtures/load';

// FHIR MedicationRequest bundles and the Standard REST API medication lists, both recorded from the dev
// stack. The standard list is the old Medications card's own source; each row's uuid is the id FHIR
// gives the same list entry, so a FHIR request whose id is not in the list is a prescription
// (user decision 2026-09-26, replacing the split on intent; BM-019).
function requests(key: string): MedicationRequest[] {
    const bundle = loadFixture<Bundle<MedicationRequest>>(`medications-${key}.json`);
    return (bundle.entry ?? []).flatMap((entry) => (entry.resource === undefined ? [] : [entry.resource]));
}

interface Row {
    uuid: string;
    title: string;
    begdate: string | null;
    enddate: string | null;
    outcome: number;
}

function list(key: string): Map<string, ListDates> {
    const rows = loadFixture<Row[]>(`medication-list-${key}.json`);
    return new Map(
        rows.map((row) => [
            row.uuid,
            { title: row.title, begdate: row.begdate, enddate: row.enddate, outcome: row.outcome },
        ]),
    );
}

const NOW = '2026-09-26 12:00:00';
const split = (key: string, now = NOW, rows = list(key)) => splitMedications(requests(key), rows, now);
const LISINOPRIL = 'a2d6832a-bf83-4fd5-a6da-ee15b8d4283a';

describe('splitMedications', () => {
    it('Medications is every current list entry, whatever its intent, oldest start first as the old card (BM-019, BM-036)', () => {
        // Atorvastatin is marked Order and Amlodipine is linked to its prescription; the old card lists both.
        expect(split('TP-TYPICAL').medications.map((m) => m.name)).toEqual([
            'Amlodipine 5 mg',
            'Metformin 500 mg',
            'Lisinopril 10 mg',
            'Atorvastatin 20 mg',
        ]);
    });

    it('Prescriptions is every prescription: a FHIR request that is not a list entry, whatever its intent', () => {
        expect(split('TP-TYPICAL').prescriptions.map((m) => m.name)).toEqual(['Amlodipine 5 mg', 'Omeprazole 20 mg']);

        const planIntent: MedicationRequest = {
            resourceType: 'MedicationRequest',
            id: 'rx-plan',
            status: 'active',
            intent: 'plan',
            subject: { reference: 'Patient/p1' },
            medicationCodeableConcept: { text: 'Imported plan prescription' },
        };
        const withPlan = splitMedications([...requests('TP-TYPICAL'), planIntent], list('TP-TYPICAL'), NOW);
        expect(withPlan.prescriptions.map((m) => m.name)).toContain('Imported plan prescription');
        expect(withPlan.medications.map((m) => m.name)).not.toContain('Imported plan prescription');
    });

    it('dosage comes from the FHIR request for the same list entry; a linked entry FHIR leaves out has none (BM-020)', () => {
        const dosage = new Map(split('TP-TYPICAL').medications.map((m) => [m.name, m.dosage]));

        expect(dosage.get('Metformin 500 mg')).toBe('1 tablet twice daily');
        expect(dosage.get('Atorvastatin 20 mg')).toBe('1 tablet at night');
        expect(dosage.get('Amlodipine 5 mg')).toBe('');
    });

    it('a list entry with a future end date is shown; once it has passed it is not (BM-044)', () => {
        expect(split('TP-TYPICAL').medications.map((m) => m.name)).toContain('Lisinopril 10 mg');
        expect(split('TP-TYPICAL', '2027-07-01 00:00:00').medications.map((m) => m.name)).not.toContain(
            'Lisinopril 10 mg',
        );
    });

    it('a list entry marked resolved is hidden', () => {
        const rows = list('TP-TYPICAL');
        const lisinopril = rows.get(LISINOPRIL);
        if (lisinopril !== undefined) {
            rows.set(LISINOPRIL, { ...lisinopril, outcome: 1 });
        }
        expect(split('TP-TYPICAL', NOW, rows).medications.map((m) => m.name)).not.toContain('Lisinopril 10 mg');
    });

    it('prescriptions: active and completed (an end date) are shown, stopped are not; newest first; no refills (BM-041, BM-044)', () => {
        const { prescriptions } = split('TP-TYPICAL');

        expect(prescriptions[0]).toEqual({
            id: 'a2d69579-9eef-47aa-a929-4ba32977bca3',
            name: 'Amlodipine 5 mg',
            dosage: '',
            quantity: '30',
            added: '2026-09-26 11:58:36',
        });
        expect(prescriptions.map((m) => m.added)).toEqual([...prescriptions.map((m) => m.added)].sort().reverse());
        expect(split('TP-HISTORY')).toEqual({ medications: [], prescriptions: [] });
    });

    it('TP-LONG lists all 60 in start-date order, as the old card (BM-036 resolved)', () => {
        const names = split('TP-LONG').medications.map((m) => m.name);

        expect(names).toHaveLength(60);
        expect(names[0]).toBe('Long-list medication 60');
        expect(names[59]).toBe('Long-list medication 01');
    });

    it('TP-EMPTY has nothing on either card', () => {
        expect(split('TP-EMPTY')).toEqual({ medications: [], prescriptions: [] });
    });
});
