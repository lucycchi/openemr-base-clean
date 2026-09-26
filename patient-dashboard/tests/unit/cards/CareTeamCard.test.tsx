// @vitest-environment jsdom
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { CareTeamCard } from '../../../web/src/cards/CareTeamCard';
import type { CareTeamView } from '../../../web/src/mappers/careTeam';

afterEach(cleanup);

const typical: CareTeamView = {
    id: 't1',
    name: 'practitioner',
    status: 'Active',
    members: [
        {
            key: '0',
            type: 'Provider',
            name: 'Name unavailable',
            role: 'Nurse Practitioner',
            facility: '',
            since: '2026-09-26',
        },
        { key: '1', type: 'Related Person', name: 'Name unavailable', role: '', facility: '', since: '' },
    ],
};

describe('CareTeamCard', () => {
    it('shows each team with its status and members', () => {
        const { container } = render(<CareTeamCard patientId="p1" state={{ status: 'ready', data: [typical] }} />);

        expect(container.querySelector('[data-card="care-team"]')?.getAttribute('data-patient-id')).toBe('p1');
        const heading = container.querySelector('[data-item="team"]');
        expect(heading?.querySelector('[data-field="name"]')?.textContent).toBe('practitioner');
        expect(heading?.querySelector('[data-field="status"]')?.textContent).toBe('Active');
        const rows = Array.from(container.querySelectorAll('[data-item="member"]')).map((row) =>
            ['type', 'name', 'role', 'facility', 'since'].map(
                (field) => row.querySelector(`[data-field="${field}"]`)?.textContent,
            ),
        );
        expect(rows).toEqual([
            ['Provider', 'Name unavailable', 'Nurse Practitioner', '', '2026-09-26'],
            ['Related Person', 'Name unavailable', '', '', ''],
        ]);
        expect(screen.getAllByRole('columnheader').map((th) => th.textContent)).toEqual([
            'Type',
            'Member',
            'Role',
            'Facility',
            'Since',
        ]);
    });

    it('empty shows "No care team recorded" (BM-030)', () => {
        const { container } = render(<CareTeamCard patientId="p1" state={{ status: 'ready', data: [] }} />);

        expect(screen.getByText('No care team recorded')).toBeTruthy();
        expect(container.querySelector('table')).toBeNull();
    });

    it('a team with no members says so', () => {
        render(<CareTeamCard patientId="p1" state={{ status: 'ready', data: [{ ...typical, members: [] }] }} />);
        expect(screen.getByText('No members recorded')).toBeTruthy();
    });

    it('a load error says so', () => {
        render(<CareTeamCard patientId="p1" state={{ status: 'error', error: { kind: 'network' } }} />);
        expect(screen.getByText("Couldn't load the care team")).toBeTruthy();
    });
});
