// @vitest-environment jsdom
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { PrescriptionsCard } from '../../../web/src/cards/PrescriptionsCard';
import type { MedicationView } from '../../../web/src/mappers/medications';

afterEach(cleanup);

const omeprazole: MedicationView = {
    id: 'o',
    name: 'Omeprazole 20 mg',
    dosage: '',
    quantity: '30',
    added: '2026-09-26 11:58:32',
};

describe('PrescriptionsCard', () => {
    it('shows drug, details, quantity, refills and date added for each prescription', () => {
        const { container } = render(
            <PrescriptionsCard
                patientId="p1"
                state={{ status: 'ready', data: [{ ...omeprazole, dosage: '1 tablet daily' }] }}
            />,
        );

        expect(container.querySelector('[data-card="prescriptions"]')?.getAttribute('data-patient-id')).toBe('p1');
        const row = container.querySelector('[data-item="prescription"]');
        const field = (name: string) => row?.querySelector(`[data-field="${name}"]`)?.textContent;
        expect([field('drug'), field('details'), field('quantity'), field('refills'), field('added')]).toEqual([
            'Omeprazole 20 mg',
            '1 tablet daily',
            '30',
            'Not available',
            '2026-09-26 11:58:32',
        ]);
        expect(screen.getByText(/marked as an order appear under Prescriptions/)).toBeTruthy();
    });

    it('column label is "Added" (BM-023)', () => {
        render(<PrescriptionsCard patientId="p1" state={{ status: 'ready', data: [omeprazole] }} />);

        const headers = screen.getAllByRole('columnheader').map((th) => th.textContent);
        expect(headers).toEqual(['Drug', 'Details', 'Qty', 'Refills', 'Added']);
    });

    it('all-discontinued shows "No active prescriptions" (BM-024)', () => {
        // splitMedications drops stopped requests, so a patient whose prescriptions are all discontinued arrives empty.
        const { container } = render(<PrescriptionsCard patientId="p1" state={{ status: 'ready', data: [] }} />);

        expect(screen.getByText('No active prescriptions')).toBeTruthy();
        expect(container.querySelector('table')).toBeNull();
    });

    it('Details is blank when dosageInstruction has no text (BM-038)', () => {
        const { container } = render(
            <PrescriptionsCard patientId="p1" state={{ status: 'ready', data: [omeprazole] }} />,
        );

        expect(container.querySelector('[data-field="details"]')?.textContent).toBe('');
    });

    it('a load error says so', () => {
        render(<PrescriptionsCard patientId="p1" state={{ status: 'error', error: { kind: 'network' } }} />);
        expect(screen.getByText("Couldn't load prescriptions")).toBeTruthy();
    });
});
