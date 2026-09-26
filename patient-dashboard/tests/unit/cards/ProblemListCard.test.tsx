// @vitest-environment jsdom
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { ProblemListCard } from '../../../web/src/cards/ProblemListCard';

afterEach(cleanup);

describe('ProblemListCard', () => {
    it('lists each problem by title, as the old card does', () => {
        const { container } = render(
            <ProblemListCard
                patientId="p1"
                state={{
                    status: 'ready',
                    data: [
                        { id: 'a', name: 'Type 2 diabetes mellitus' },
                        { id: 'b', name: 'Hyperlipidaemia' },
                    ],
                }}
            />,
        );

        expect(container.querySelector('[data-card="problems"]')?.getAttribute('data-patient-id')).toBe('p1');
        expect(Array.from(container.querySelectorAll('[data-item="problem"]')).map((row) => row.textContent)).toEqual([
            'Type 2 diabetes mellitus',
            'Hyperlipidaemia',
        ]);
    });

    it('an empty list says "None recorded"', () => {
        render(<ProblemListCard patientId="p1" state={{ status: 'ready', data: [] }} />);
        expect(screen.getByText('None recorded')).toBeTruthy();
    });

    it('a load error says so', () => {
        render(<ProblemListCard patientId="p1" state={{ status: 'error', error: { kind: 'network' } }} />);
        expect(screen.getByText("Couldn't load medical problems")).toBeTruthy();
        expect(screen.queryByText('None recorded')).toBeNull();
    });
});
