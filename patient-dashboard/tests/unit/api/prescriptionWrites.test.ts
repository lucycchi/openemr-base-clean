import { describe, expect, it } from 'vitest';
import {
    addPrescription,
    discontinuePrescription,
    replacePrescription,
    shouldReload,
} from '../../../web/src/api/prescriptionWrites';
import type { WriteClient } from '../../../web/src/api/client';

const form = {
    drug: 'Amoxicillin',
    dosage: '',
    quantity: '21',
    refills: 0,
    startDate: '2026-09-27',
    dateAdded: '2026-09-27 12:00:00',
    prescriber: '',
};
const clientAnswering = (status: number, body: unknown, seen: unknown[] = []): WriteClient => ({
    sendJson: async (method, path, sent) => {
        seen.push({ method, path, sent });
        return { status, body };
    },
});

describe('prescription writes from the page', () => {
    it('adds to the patient on screen', async () => {
        const seen: unknown[] = [];
        expect(await addPrescription(clientAnswering(201, { uuid: 'u' }, seen), 'p1', form)).toEqual({ kind: 'saved' });
        expect(seen).toEqual([{ method: 'POST', path: 'prescriptions?patient=p1', sent: form }]);
    });

    it("turns the server's 400 into the form's field messages", async () => {
        expect(await addPrescription(clientAnswering(400, { errors: { drug: 'Enter the drug' } }), 'p1', form)).toEqual(
            {
                kind: 'invalid',
                errors: { drug: 'Enter the drug' },
            },
        );
    });

    it('says plainly when a change added the new prescription but could not stop the old one', async () => {
        const outcome = await replacePrescription(
            clientAnswering(207, { added: 'n', discontinued: false }),
            'p1',
            'rx',
            form,
        );
        expect(outcome.kind).toBe('partly-saved');
    });

    it('sends discontinue and change to that prescription', async () => {
        const seen: { path: string }[] = [];
        await discontinuePrescription(clientAnswering(200, {}, seen), 'p1', 'rx-1');
        await replacePrescription(clientAnswering(201, {}, seen), 'p1', 'rx-1', form);
        expect(seen.map((call) => call.path)).toEqual([
            'prescriptions/rx-1/discontinue?patient=p1',
            'prescriptions/rx-1/replace?patient=p1',
        ]);
    });

    it('says nothing changed only when the server refused before writing', async () => {
        for (const status of [400, 403, 404, 415]) {
            const outcome = await discontinuePrescription(clientAnswering(status, {}), 'p1', 'rx');
            expect(outcome.kind, String(status)).toBe('failed');
        }
    });

    it('says it may not have saved after a timeout or a lost answer, so the user checks before retrying (final review, Important 4)', async () => {
        for (const status of [502, 500, 0]) {
            const outcome = await addPrescription(clientAnswering(status, undefined), 'p1', form);
            expect(outcome.kind, String(status)).toBe('uncertain');
            expect(outcome.kind === 'uncertain' ? outcome.message : '').toContain('Check the list before trying again');
        }
    });
});

describe('shouldReload', () => {
    it('reloads the list after anything that may have changed it, including an unclear answer', () => {
        expect(shouldReload({ kind: 'saved' })).toBe(true);
        expect(shouldReload({ kind: 'partly-saved', message: '' })).toBe(true);
        expect(shouldReload({ kind: 'uncertain', message: '' })).toBe(true);
        expect(shouldReload({ kind: 'failed', message: '' })).toBe(false);
        expect(shouldReload({ kind: 'invalid', errors: {} })).toBe(false);
    });
});
