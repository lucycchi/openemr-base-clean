import { describe, expect, it } from 'vitest';
import { openemrPrescriptionBody, parsePrescriptionInput } from '../../../server/prescriptionInput';

const NOW = Date.parse('2026-09-27T12:00:00Z');
const valid = {
    drug: 'Amoxicillin 500 mg',
    dosage: '1 capsule three times daily',
    quantity: '21',
    refills: 0,
    startDate: '2026-09-27',
    dateAdded: '2026-09-27 12:00:00',
    prescriber: 'Dr Donna Lee',
};

describe('parsePrescriptionInput', () => {
    it('accepts a complete form and trims text', () => {
        const parsed = parsePrescriptionInput({ ...valid, drug: '  Amoxicillin 500 mg ' }, NOW);
        expect(parsed).toEqual({ ok: true, value: { ...valid } });
    });

    it('names each problem: no drug, a quantity that is not a whole number, refills outside 0-20, a bad date', () => {
        const parsed = parsePrescriptionInput(
            { ...valid, drug: ' ', quantity: '2.5', refills: 21, startDate: '2026-02-30' },
            NOW,
        );
        expect(parsed.ok).toBe(false);
        expect(Object.keys(parsed.ok ? {} : parsed.errors).sort()).toEqual([
            'drug',
            'quantity',
            'refills',
            'startDate',
        ]);
    });

    it('refuses a date added more than a day from the server clock, so a wrong page clock cannot backdate', () => {
        expect(parsePrescriptionInput({ ...valid, dateAdded: '2026-09-20 12:00:00' }, NOW).ok).toBe(false);
    });

    it('refuses a prescriber with a line break or over 100 characters, and unknown fields', () => {
        expect(parsePrescriptionInput({ ...valid, prescriber: 'a\nb' }, NOW).ok).toBe(false);
        expect(parsePrescriptionInput({ ...valid, prescriber: 'x'.repeat(101) }, NOW).ok).toBe(false);
        expect(parsePrescriptionInput({ ...valid, provider_id: 1 }, NOW).ok).toBe(false);
    });

    it('allows an empty quantity, dosage and prescriber', () => {
        expect(parsePrescriptionInput({ ...valid, quantity: '', dosage: '', prescriber: '' }, NOW).ok).toBe(true);
    });

    it('refuses something that is not an object', () => {
        expect(parsePrescriptionInput(undefined, NOW).ok).toBe(false);
        expect(parsePrescriptionInput(['drug'], NOW).ok).toBe(false);
    });
});

describe('openemrPrescriptionBody', () => {
    it('sets what the old form sets, and puts the typed prescriber in the note', () => {
        expect(openemrPrescriptionBody(valid, 42)).toEqual({
            patient_id: 42,
            drug: 'Amoxicillin 500 mg',
            drug_dosage_instructions: '1 capsule three times daily',
            quantity: '21',
            refills: 0,
            per_refill: 0,
            start_date: '2026-09-27',
            date_added: '2026-09-27 12:00:00',
            date_modified: '2026-09-27 12:00:00',
            active: 1,
            request_intent: 'order',
            request_intent_title: 'Order',
            usage_category: 'outpatient',
            usage_category_title: 'Outpatient',
            note: 'Prescriber: Dr Donna Lee',
        });
    });

    it('leaves the note empty when no prescriber is typed', () => {
        expect(openemrPrescriptionBody({ ...valid, prescriber: '' }, 42).note).toBe('');
    });
});
