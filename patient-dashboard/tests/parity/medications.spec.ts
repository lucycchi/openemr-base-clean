import { expect, test } from '@playwright/test';
import { fixture } from '../support/fixtures';
import type { FixtureKey } from '../support/fixtures';
import { logInThroughOpenEmr } from '../support/login';
import { readNewCard } from '../support/newApp';
import { openOldSession, readOldCard, showOldPatient } from '../support/oldDashboard';

// Compared (modules/medications.md): the set of medication-list entries, not their order.
// Approved exceptions, applied below:
//   BM-012  the old "Nothing Recorded" / "None" become "None recorded"
//   BM-020  a list entry linked to a prescription has no dosage (FHIR only carries the prescription)
const FIXTURES: FixtureKey[] = ['TP-TYPICAL', 'TP-EMPTY', 'TP-HISTORY', 'TP-LONG'];
const OLD_EMPTY = ['Nothing Recorded', 'None'];

test('medications match the old dashboard for every fixture, with the approved exceptions', async ({
    page,
    browser,
}) => {
    test.setTimeout(180_000);
    await logInThroughOpenEmr(page);
    const oldSession = await openOldSession(browser);

    for (const key of FIXTURES) {
        const patient = fixture(key);
        await showOldPatient(oldSession, patient.pid);
        const old = await readOldCard(oldSession, 'medication_ps_expand');
        const oldNames = old.parts.map((parts) => parts[0] ?? '').filter((name) => !OLD_EMPTY.includes(name));

        await page.goto(`/patient/${patient.fhirId}`);
        const card = await readNewCard(page, 'medications');
        const newNames = card.rows.map((row) => row.fields.name ?? '');

        const prescriptionNames = (await readNewCard(page, 'prescriptions')).rows.map((row) => row.fields.drug ?? '');

        expect(card.state, key).toBe('ready');
        expect(card.patientId, key).toBe(patient.fhirId);
        // Exactly the old card's entries in the old order: the card reads the old card's own list
        // (BM-019 resolved), in start-date order (BM-036 resolved).
        expect(newNames, key).toEqual(oldNames);
        // Dosage matches, except for an entry linked to a prescription, whose list dosage FHIR does not
        // carry (BM-020); that entry is also on the Prescriptions card.
        const oldDosage = new Map(old.parts.map((parts) => [parts[0] ?? '', parts[1] ?? '']));
        card.rows.forEach((row) => {
            const name = row.fields.name ?? '';
            const dosage = row.fields.dosage ?? '';
            const allowed = prescriptionNames.includes(name)
                ? [oldDosage.get(name) ?? '', '']
                : [oldDosage.get(name) ?? ''];
            expect(allowed, `${key} ${name} dosage`).toContain(dosage);
        });
        if (oldNames.length === 0) {
            expect(card.empty, key).toBe('None recorded');
        }
    }

    await oldSession.context().close();
});
