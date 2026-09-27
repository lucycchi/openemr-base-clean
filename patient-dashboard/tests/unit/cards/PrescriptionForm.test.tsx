// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { PrescriptionFormPanel } from '../../../web/src/cards/PrescriptionForm';
import type { PrescriptionForm, WriteOutcome } from '../../../web/src/api/prescriptionWrites';

afterEach(cleanup);
const base = {
    prescriberDefault: 'Donna Lee',
    today: '2026-09-27',
    nowText: () => '2026-09-27 12:00:00',
    onCancel: vi.fn(),
};

describe('PrescriptionFormPanel', () => {
    it('starts with today, 0 refills and the signed-in prescriber, and sends what was typed', async () => {
        const onSave = vi.fn(async (): Promise<WriteOutcome> => ({ kind: 'saved' }));
        render(<PrescriptionFormPanel {...base} initial={{}} onSave={onSave} />);
        expect((screen.getByLabelText('Prescriber') as HTMLInputElement).value).toBe('Donna Lee');
        expect((screen.getByLabelText('Start date') as HTMLInputElement).value).toBe('2026-09-27');
        expect((screen.getByLabelText('Refills') as HTMLSelectElement).value).toBe('0');
        fireEvent.change(screen.getByLabelText('Drug'), { target: { value: 'Amoxicillin 500 mg' } });
        fireEvent.change(screen.getByLabelText('Quantity'), { target: { value: '21' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save' }));
        await waitFor(() => expect(onSave).toHaveBeenCalledTimes(1));
        expect(onSave).toHaveBeenCalledWith({
            drug: 'Amoxicillin 500 mg',
            dosage: '',
            quantity: '21',
            refills: 0,
            startDate: '2026-09-27',
            dateAdded: '2026-09-27 12:00:00',
            prescriber: 'Donna Lee',
        });
    });

    it('saves once however often Save is clicked while saving (Review Focus 1)', async () => {
        let finish: (value: WriteOutcome) => void = () => undefined;
        const onSave = vi.fn(() => new Promise<WriteOutcome>((resolve) => (finish = resolve)));
        render(<PrescriptionFormPanel {...base} initial={{ drug: 'Amoxicillin' }} onSave={onSave} />);
        const save = screen.getByRole('button', { name: 'Save' });
        fireEvent.click(save);
        fireEvent.click(save);
        fireEvent.submit(save.closest('form') as HTMLFormElement);
        expect(onSave).toHaveBeenCalledTimes(1);
        expect((save as HTMLButtonElement).disabled).toBe(true);
        finish({ kind: 'saved' });
        await waitFor(() => expect((save as HTMLButtonElement).disabled).toBe(false));
    });

    it('shows the server message beside each field it names, and keeps what was typed', async () => {
        const onSave = vi.fn(async (): Promise<WriteOutcome> => ({
            kind: 'invalid',
            errors: { quantity: 'Quantity must be a whole number' },
        }));
        render(<PrescriptionFormPanel {...base} initial={{ drug: 'Amoxicillin', quantity: '2.5' }} onSave={onSave} />);
        fireEvent.click(screen.getByRole('button', { name: 'Save' }));
        expect(await screen.findByText('Quantity must be a whole number')).toBeTruthy();
        expect((screen.getByLabelText('Quantity') as HTMLInputElement).value).toBe('2.5');
    });

    it('shows a failure as an alert and keeps the form open', async () => {
        const onSave = vi.fn(async (): Promise<WriteOutcome> => ({
            kind: 'failed',
            message: "Couldn't save. Nothing was changed.",
        }));
        render(<PrescriptionFormPanel {...base} initial={{ drug: 'Amoxicillin' }} onSave={onSave} />);
        fireEvent.click(screen.getByRole('button', { name: 'Save' }));
        expect((await screen.findByRole('alert')).textContent).toContain("Couldn't save");
        expect(screen.getByLabelText('Drug')).toBeTruthy();
    });

    it('takes the date added when Save is pressed, not when the form opened', async () => {
        let clock = '2026-09-27 12:00:00';
        const onSave = vi.fn<(form: PrescriptionForm) => Promise<WriteOutcome>>(async () => ({ kind: 'saved' }));
        render(
            <PrescriptionFormPanel {...base} nowText={() => clock} initial={{ drug: 'Amoxicillin' }} onSave={onSave} />,
        );
        clock = '2026-09-27 12:05:00';
        fireEvent.click(screen.getByRole('button', { name: 'Save' }));
        await waitFor(() => expect(onSave).toHaveBeenCalled());
        expect(onSave.mock.calls[0]?.[0]).toMatchObject({ dateAdded: '2026-09-27 12:05:00' });
    });

    it('explains where the prescriber is saved, and Cancel closes without saving', () => {
        const onSave = vi.fn();
        const onCancel = vi.fn();
        render(<PrescriptionFormPanel {...base} onCancel={onCancel} initial={{}} onSave={onSave} />);
        expect(screen.getByText(/saved in the prescription's note/i)).toBeTruthy();
        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
        expect(onCancel).toHaveBeenCalledTimes(1);
        expect(onSave).not.toHaveBeenCalled();
    });
});
