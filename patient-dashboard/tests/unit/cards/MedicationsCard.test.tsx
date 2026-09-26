// @vitest-environment jsdom
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { MedicationsCard } from '../../../web/src/cards/MedicationsCard';

afterEach(cleanup);

const row = (id: string, name: string, dosage = '') => ({ id, name, dosage, quantity: '', refills: '', added: '' });

describe('MedicationsCard', () => {
    it('lists each medication with its dosage, and notes how the split works (BM-019)', () => {
        const { container } = render(
            <MedicationsCard
                patientId="p1"
                state={{
                    status: 'ready',
                    data: [row('a', 'Metformin 500 mg'), row('b', 'Atorvastatin 20 mg', '1 tablet at night')],
                }}
            />,
        );

        expect(container.querySelector('[data-card="medications"]')?.getAttribute('data-patient-id')).toBe('p1');
        const rows = Array.from(container.querySelectorAll('[data-item="medication"]'));
        expect(rows.map((r) => r.querySelector('[data-field="name"]')?.textContent)).toEqual([
            'Metformin 500 mg',
            'Atorvastatin 20 mg',
        ]);
        expect(rows.map((r) => r.querySelector('[data-field="dosage"]')?.textContent ?? '')).toEqual([
            '',
            '1 tablet at night',
        ]);
        expect(screen.getByText(/marked as an order appear under Prescriptions/)).toBeTruthy();
    });

    it('an empty list says "None recorded"', () => {
        render(<MedicationsCard patientId="p1" state={{ status: 'ready', data: [] }} />);
        expect(screen.getByText('None recorded')).toBeTruthy();
    });

    it('a load error says so', () => {
        render(<MedicationsCard patientId="p1" state={{ status: 'error', error: { kind: 'network' } }} />);
        expect(screen.getByText("Couldn't load medications")).toBeTruthy();
    });
});
