// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import type { Bundle, Encounter } from 'fhir/r4';
import { EncounterHistoryCard } from '../../../web/src/cards/EncounterHistoryCard';
import { mapEncounters } from '../../../web/src/mappers/encounters';
import { loadFixture } from '../fixtures/load';

afterEach(cleanup);

function long() {
    const bundle = loadFixture<Bundle<Encounter>>('encounters-TP-LONG.json');
    const resources = (bundle.entry ?? []).flatMap((entry) => (entry.resource === undefined ? [] : [entry.resource]));
    return mapEncounters(resources, new Map());
}

const rows = (container: HTMLElement) =>
    Array.from(container.querySelectorAll('[data-item="encounter"]')).map(
        (row) => row.querySelector('[data-field="reason"]')?.textContent,
    );

describe('EncounterHistoryCard', () => {
    it('20 shown, "Show all" reveals 30 for TP-LONG (BM-034)', () => {
        const { container } = render(
            <EncounterHistoryCard patientId="p1" pageSize={20} state={{ status: 'ready', data: long() }} />,
        );

        expect(rows(container)).toHaveLength(20);
        expect(rows(container)[0]).toBe('Long-list visit 01');
        expect(screen.getByText('Showing the 20 most recent of 30')).toBeTruthy();

        fireEvent.click(screen.getByRole('button', { name: 'Show all 30' }));

        expect(rows(container)).toHaveLength(30);
        expect(rows(container)[29]).toBe('Long-list visit 30');
        fireEvent.click(screen.getByRole('button', { name: 'Show the 20 most recent' }));
        expect(rows(container)).toHaveLength(20);
    });

    it('a page size of 0 shows everything, as encounter_page_size does', () => {
        const { container } = render(
            <EncounterHistoryCard patientId="p1" pageSize={0} state={{ status: 'ready', data: long() }} />,
        );

        expect(rows(container)).toHaveLength(30);
        expect(screen.queryByRole('button')).toBeNull();
    });

    it('shows date, reason and provider columns', () => {
        const { container } = render(
            <EncounterHistoryCard
                patientId="p1"
                pageSize={20}
                state={{
                    status: 'ready',
                    data: [
                        { id: 'e', date: '2026-03-02', reason: 'Blood pressure check', provider: 'Name unavailable' },
                    ],
                }}
            />,
        );

        expect(container.querySelector('[data-card="encounter-history"]')?.getAttribute('data-patient-id')).toBe('p1');
        expect(screen.getAllByRole('columnheader').map((th) => th.textContent)).toEqual(['Date', 'Reason', 'Provider']);
        const row = container.querySelector('[data-item="encounter"]');
        expect(
            ['date', 'reason', 'provider'].map((f) => row?.querySelector(`[data-field="${f}"]`)?.textContent),
        ).toEqual(['2026-03-02', 'Blood pressure check', 'Name unavailable']);
        expect(screen.queryByRole('button')).toBeNull();
    });

    it('empty shows "No encounters recorded" (BM-035)', () => {
        const { container } = render(
            <EncounterHistoryCard patientId="p1" pageSize={20} state={{ status: 'ready', data: [] }} />,
        );

        expect(screen.getByText('No encounters recorded')).toBeTruthy();
        expect(container.querySelector('table')).toBeNull();
    });

    it('a load error says so', () => {
        render(
            <EncounterHistoryCard
                patientId="p1"
                pageSize={20}
                state={{ status: 'error', error: { kind: 'network' } }}
            />,
        );
        expect(screen.getByText("Couldn't load encounters")).toBeTruthy();
    });
});
