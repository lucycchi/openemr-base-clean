// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { PrescriptionsCard } from '../../../web/src/cards/PrescriptionsCard';
import type { MedicationView } from '../../../web/src/mappers/medications';
import type { WriteOutcome } from '../../../web/src/api/prescriptionWrites';
import type { LoadState } from '../../../web/src/hooks/loadState';

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
        // The cards are now split by list membership, not intent, so the old caveat is gone (BM-019).
        expect(screen.queryByText(/marked as an order appear under Prescriptions/)).toBeNull();
    });

    it('draws a striped table that scrolls sideways on a narrow screen, as the old one does', () => {
        const { container } = render(
            <PrescriptionsCard patientId="p1" state={{ status: 'ready', data: [{ ...omeprazole, dosage: '' }] }} />,
        );
        expect(container.querySelector('.table-responsive > table.table.table-sm.table-striped')).not.toBeNull();
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
    const editing = () => ({
        prescriberDefault: 'Donna Lee',
        today: '2026-09-27',
        nowText: () => '2026-09-27 12:00:00',
        add: vi.fn(async (): Promise<WriteOutcome> => ({ kind: 'saved' })),
        change: vi.fn(async (): Promise<WriteOutcome> => ({ kind: 'saved' })),
        discontinue: vi.fn(async (): Promise<WriteOutcome> => ({ kind: 'saved' })),
    });
    const oneRow: LoadState<MedicationView[]> = { status: 'ready', data: [{ ...omeprazole, dosage: '1 daily' }] };

    it('offers Add in the title bar, and Change and Discontinue on each row, when editing is allowed', () => {
        render(<PrescriptionsCard patientId="p1" state={oneRow} editing={editing()} />);
        expect(screen.getByRole('button', { name: 'Add prescription' })).toBeTruthy();
        expect(screen.getByRole('button', { name: `Change ${omeprazole.name}` })).toBeTruthy();
        expect(screen.getByRole('button', { name: `Discontinue ${omeprazole.name}` })).toBeTruthy();
    });

    it('asks before discontinuing, and only discontinues on Yes', async () => {
        const e = editing();
        render(<PrescriptionsCard patientId="p1" state={oneRow} editing={e} />);
        fireEvent.click(screen.getByRole('button', { name: `Discontinue ${omeprazole.name}` }));
        expect(screen.getByRole('alertdialog').textContent).toContain(`Discontinue ${omeprazole.name}?`);
        // OpenEMR's own screens misread the API's discontinue as still active (BM-067); the question says so.
        expect(screen.getByRole('alertdialog').textContent).toContain(
            "OpenEMR's own prescription screens will still list it as active",
        );
        expect(screen.getByRole('alertdialog').textContent).toContain('saving it there makes it active again');
        fireEvent.click(screen.getByRole('button', { name: 'Keep it' }));
        expect(e.discontinue).not.toHaveBeenCalled();
        expect(screen.queryByRole('alertdialog')).toBeNull();
        fireEvent.click(screen.getByRole('button', { name: `Discontinue ${omeprazole.name}` }));
        fireEvent.click(screen.getByRole('button', { name: 'Yes, discontinue' }));
        await waitFor(() => expect(e.discontinue).toHaveBeenCalledWith(omeprazole.id));
    });

    it('opens Change with the row filled in and sends the change for that row', async () => {
        const e = editing();
        render(<PrescriptionsCard patientId="p1" state={oneRow} editing={e} />);
        fireEvent.click(screen.getByRole('button', { name: `Change ${omeprazole.name}` }));
        expect((screen.getByLabelText('Drug') as HTMLInputElement).value).toBe(omeprazole.name);
        expect((screen.getByLabelText('Directions') as HTMLInputElement).value).toBe('1 daily');
        expect((screen.getByLabelText('Quantity') as HTMLInputElement).value).toBe('30');
        fireEvent.click(screen.getByRole('button', { name: 'Save' }));
        await waitFor(() =>
            expect(e.change).toHaveBeenCalledWith(omeprazole.id, expect.objectContaining({ drug: omeprazole.name })),
        );
    });

    it('opens Add with an empty form and sends the new prescription', async () => {
        const e = editing();
        render(<PrescriptionsCard patientId="p1" state={oneRow} editing={e} />);
        fireEvent.click(screen.getByRole('button', { name: 'Add prescription' }));
        expect((screen.getByLabelText('Drug') as HTMLInputElement).value).toBe('');
        fireEvent.change(screen.getByLabelText('Drug'), { target: { value: 'Amoxicillin' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save' }));
        await waitFor(() => expect(e.add).toHaveBeenCalledWith(expect.objectContaining({ drug: 'Amoxicillin' })));
        await waitFor(() => expect(screen.queryByLabelText('Drug')).toBeNull());
    });

    it('names the old prescription when a change saved the new one but kept the old one (final review, Important 2)', async () => {
        const e = editing();
        e.change.mockResolvedValue({
            kind: 'partly-saved',
            message: 'The new prescription was saved, but the old one could not be discontinued.',
        });
        render(<PrescriptionsCard patientId="p1" state={oneRow} editing={e} />);
        fireEvent.click(screen.getByRole('button', { name: `Change ${omeprazole.name}` }));
        fireEvent.click(screen.getByRole('button', { name: 'Save' }));
        const alert = await screen.findByRole('alert');
        expect(alert.textContent).toContain(`${omeprazole.name}, 1 daily, added ${omeprazole.added}`);
        expect(alert.textContent).toContain('is still active');
    });

    it('says in the Change form that refills are not carried over, and warns about OpenEMR screens (final review, Critical 1 and Important 3)', () => {
        render(<PrescriptionsCard patientId="p1" state={oneRow} editing={editing()} />);
        fireEvent.click(screen.getByRole('button', { name: `Change ${omeprazole.name}` }));
        const card = screen.getByRole('region', { name: 'Prescriptions' });
        expect(card.textContent).toContain('Refills are not carried over');
        expect(card.textContent).toContain("OpenEMR's own prescription screens will still list it as active");
    });

    it('locks Add, Change and Discontinue while a write is on its way (final review, Important 5)', async () => {
        const e = editing();
        let finish: (value: WriteOutcome) => void = () => undefined;
        e.add.mockImplementation(() => new Promise<WriteOutcome>((resolve) => (finish = resolve)));
        render(<PrescriptionsCard patientId="p1" state={oneRow} editing={e} />);
        fireEvent.click(screen.getByRole('button', { name: 'Add prescription' }));
        fireEvent.change(screen.getByLabelText('Drug'), { target: { value: 'Amoxicillin' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save' }));
        for (const name of ['Add prescription', `Change ${omeprazole.name}`, `Discontinue ${omeprazole.name}`]) {
            expect((screen.getByRole('button', { name }) as HTMLButtonElement).disabled, name).toBe(true);
        }
        finish({ kind: 'saved' });
        await waitFor(() =>
            expect((screen.getByRole('button', { name: 'Add prescription' }) as HTMLButtonElement).disabled).toBe(
                false,
            ),
        );
    });

    it('keeps the form open with the warning when a save may not have gone through', async () => {
        const e = editing();
        e.add.mockResolvedValue({
            kind: 'uncertain',
            message: 'May not have saved. Check the list before trying again.',
        });
        render(<PrescriptionsCard patientId="p1" state={oneRow} editing={e} />);
        fireEvent.click(screen.getByRole('button', { name: 'Add prescription' }));
        fireEvent.change(screen.getByLabelText('Drug'), { target: { value: 'Amoxicillin' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save' }));
        expect((await screen.findByRole('alert')).textContent).toContain('Check the list before trying again');
        expect(screen.getByLabelText('Drug')).toBeTruthy();
    });

    it('says so above the list when a change saved the new prescription but kept the old one', async () => {
        const e = editing();
        e.change.mockResolvedValue({
            kind: 'partly-saved',
            message: 'The new prescription was saved, but the old one could not be discontinued.',
        });
        render(<PrescriptionsCard patientId="p1" state={oneRow} editing={e} />);
        fireEvent.click(screen.getByRole('button', { name: `Change ${omeprazole.name}` }));
        fireEvent.click(screen.getByRole('button', { name: 'Save' }));
        expect((await screen.findByRole('alert')).textContent).toContain('is still active');
        expect(screen.queryByLabelText('Drug')).toBeNull();
    });

    it('offers Add on an empty card too', () => {
        render(<PrescriptionsCard patientId="p1" state={{ status: 'ready', data: [] }} editing={editing()} />);
        expect(screen.getByRole('button', { name: 'Add prescription' })).toBeTruthy();
    });

    it('stays read-only with no editing prop, so nothing changes for other callers', () => {
        render(<PrescriptionsCard patientId="p1" state={oneRow} />);
        expect(screen.queryByRole('button', { name: 'Add prescription' })).toBeNull();
        expect(screen.queryByRole('button', { name: /^Change / })).toBeNull();
    });
});
