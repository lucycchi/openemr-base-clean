// @vitest-environment jsdom
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { AllergiesCard } from '../../../web/src/cards/AllergiesCard';

afterEach(cleanup);

describe('AllergiesCard', () => {
    it('shows each allergy with its risk, a reaction tooltip, and highlights high risk', () => {
        const { container } = render(
            <AllergiesCard
                patientId="p1"
                state={{
                    status: 'ready',
                    data: [
                        { id: 'a', name: 'Penicillin', reaction: 'Hives', risk: 'Low risk', high: false },
                        { id: 'b', name: 'Peanuts', reaction: '', risk: '', high: false },
                        { id: 'c', name: 'Shellfish', reaction: 'Anaphylaxis', risk: 'High risk', high: true },
                    ],
                }}
            />,
        );

        const card = container.querySelector('[data-card="allergies"]');
        expect(card?.getAttribute('data-patient-id')).toBe('p1');
        const rows = Array.from(container.querySelectorAll('[data-item="allergy"]'));
        expect(rows.map((row) => row.textContent)).toEqual([
            'Penicillin (Low risk)',
            'Peanuts',
            'Shellfish (High risk)',
        ]);
        expect(rows.map((row) => row.getAttribute('title'))).toEqual([
            'Penicillin Reaction: Hives - Low risk',
            'Peanuts Reaction: -',
            'Shellfish Reaction: Anaphylaxis - High risk',
        ]);
        expect(rows.map((row) => row.hasAttribute('data-highlight'))).toEqual([false, false, true]);
    });

    it('an empty list says "No allergies recorded", never "No Known Allergies" (BM-012)', () => {
        render(<AllergiesCard patientId="p1" state={{ status: 'ready', data: [] }} />);

        expect(screen.getByText('No allergies recorded')).toBeTruthy();
        expect(screen.queryByText(/No Known Allergies/i)).toBeNull();
    });

    it('a load error says so', () => {
        const { container } = render(
            <AllergiesCard patientId="p1" state={{ status: 'error', error: { kind: 'network' } }} />,
        );

        expect(screen.getByText("Couldn't load allergies")).toBeTruthy();
        expect(container.querySelector('[data-card="allergies"]')?.getAttribute('data-state')).toBe('error');
        expect(screen.queryByText('No allergies recorded')).toBeNull();
    });
});
