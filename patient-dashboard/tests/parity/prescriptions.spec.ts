import { expect, test } from '@playwright/test';
import { fixture } from '../support/fixtures';
import type { FixtureKey } from '../support/fixtures';
import { logInThroughOpenEmr } from '../support/login';
import { readNewCard } from '../support/newApp';
import { openOldSession, readOldCard, showOldPatient } from '../support/oldDashboard';

// Compared (modules/prescriptions.md): drug, quantity and date added of every active prescription, in order,
// and nothing else: list entries are never shown here (BM-019 resolved).
// Approved exceptions, applied below:
//   BM-023  the old "Filled" column is labelled "Added"
//   BM-024  no active prescriptions shows "No active prescriptions" (old: "None", or an empty table)
//   BM-038  Details is not compared (the old size, unit and dose are not in FHIR)
//   BM-041  Refills are not in FHIR (always 0), so every row says "Not available" instead
//   BM-044  a prescription with an end date is shown (FHIR calls it completed; the old card ignores the date)
const FIXTURES: FixtureKey[] = ['TP-TYPICAL', 'TP-EMPTY', 'TP-HISTORY', 'TP-LONG'];

test('prescriptions match the old dashboard for every fixture, with the approved exceptions', async ({
    page,
    browser,
}) => {
    test.setTimeout(180_000);
    await logInThroughOpenEmr(page);
    const oldSession = await openOldSession(browser);

    for (const key of FIXTURES) {
        const patient = fixture(key);
        await showOldPatient(oldSession, patient.pid);
        const oldRx = await readOldCard(oldSession, 'prescriptions_ps_expand');
        // Old rows are Drug | Details | Qty | Refills | Filled; the header row and "None" are not prescriptions.
        const oldRows = oldRx.parts
            .filter((parts) => parts.length === 5 && parts[0] !== 'Drug')
            .map(([drug = '', , quantity = '', , added = '']) => ({ drug, quantity, added }));
        await page.goto(`/patient/${patient.fhirId}`);
        const card = await readNewCard(page, 'prescriptions');
        const newRows = card.rows.map(({ fields }) => ({
            drug: fields.drug ?? '',
            quantity: fields.quantity ?? '',
            added: fields.added ?? '',
        }));
        const refills = card.rows.map(({ fields }) => fields.refills ?? '');

        expect(card.state, key).toBe('ready');
        expect(card.patientId, key).toBe(patient.fhirId);
        // Exactly the old card's prescriptions, same values, same order, nothing extra.
        expect(newRows, key).toEqual(oldRows);
        expect(
            refills.filter((value) => value !== 'Not available'),
            key,
        ).toEqual([]);
        if (newRows.length === 0) {
            expect(card.empty, key).toBe('No active prescriptions');
        }
    }

    await oldSession.context().close();
});
