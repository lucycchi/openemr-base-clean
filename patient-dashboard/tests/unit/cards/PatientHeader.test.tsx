// @vitest-environment jsdom
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { PatientHeader } from '../../../web/src/cards/PatientHeader';

afterEach(cleanup);

describe('PatientHeader', () => {
    it('shows every header field, tagged for the parity reader', () => {
        const { container } = render(
            <PatientHeader
                state={{
                    status: 'ready',
                    data: {
                        id: 'p1',
                        name: "Zoë O'Brien-Núñez",
                        mrn: '42',
                        dobLine: 'DOB: 1988-12-12 Age: 37',
                        sex: 'Female',
                        status: 'Active',
                    },
                }}
            />,
        );

        expect(screen.getByText("Zoë O'Brien-Núñez")).toBeTruthy();
        expect(container.querySelector('[data-card="header"]')?.getAttribute('data-patient-id')).toBe('p1');
        const items = Array.from(container.querySelectorAll('[data-card="header"] [data-item]')).map((node) => [
            node.getAttribute('data-item'),
            node.textContent,
        ]);
        expect(items).toEqual([
            ['name', "Zoë O'Brien-Núñez"],
            ['mrn', '(42)'],
            ['dobLine', 'DOB: 1988-12-12 Age: 37'],
            ['sex', 'Sex: Female'],
            ['status', 'Active'],
        ]);
    });

    it('a load error says so and shows no patient fields', () => {
        const { container } = render(<PatientHeader state={{ status: 'error', error: { kind: 'network' } }} />);

        expect(screen.getByText("Couldn't load the patient")).toBeTruthy();
        expect(container.querySelector('[data-card="header"]')?.getAttribute('data-state')).toBe('error');
        expect(container.querySelectorAll('[data-item]')).toHaveLength(0);
    });
});
