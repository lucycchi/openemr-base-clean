import { expect, test } from '@playwright/test';
import type { Bundle, MedicationRequest } from 'fhir/r4';
import { fixture } from '../support/fixtures';
import type { FixtureKey } from '../support/fixtures';
import { logInThroughOpenEmr } from '../support/login';
import { readNewCard } from '../support/newApp';
import { openOldSession, readOldCard, showOldPatient } from '../support/oldDashboard';

// Compared (modules/medications.md): the set of medication-list entries, not their order.
// Approved exceptions, applied below:
//   BM-012  the old "Nothing Recorded" / "None" become "None recorded"
//   BM-019  entries FHIR reports with intent "order" appear under Prescriptions, not here
//   BM-020  a list entry linked to a prescription comes back once, as the prescription (intent "order")
//   BM-036  the old start-date order is not in FHIR; the new card keeps the API order
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

        // A moved entry (BM-019, BM-020) must land on the Prescriptions card, not disappear from both.
        const prescriptionNames = (await readNewCard(page, 'prescriptions')).rows.map((row) => row.fields.drug ?? '');

        expect(card.state, key).toBe('ready');
        expect(card.patientId, key).toBe(patient.fhirId);
        // Nothing invented: every new row is on the old medication list.
        expect(
            newNames.filter((name) => !oldNames.includes(name)),
            key,
        ).toEqual([]);
        // Nothing lost, and each entry on the card its FHIR intent says: an entry FHIR marks as an order
        // (BM-019, BM-020) is on Prescriptions, every other old entry on Medications.
        const bundle = (await (
            await page.request.get(`/api/fhir/MedicationRequest?patient=${patient.fhirId}`)
        ).json()) as Bundle<MedicationRequest>;
        const orders = new Set(
            (bundle.entry ?? [])
                .map((entry) => entry.resource)
                .filter((request) => request?.intent === 'order')
                .map((request) => request?.medicationCodeableConcept?.text ?? ''),
        );
        expect(
            oldNames.filter((name) =>
                orders.has(name) ? !prescriptionNames.includes(name) : !newNames.includes(name),
            ),
            key,
        ).toEqual([]);
        expect(new Set(newNames).size, key).toBe(newNames.length);
        // Dosage text matches the old card's for every entry on the new Medications card.
        const oldDosage = new Map(old.parts.map((parts) => [parts[0] ?? '', parts[1] ?? '']));
        card.rows.forEach((row) => {
            const name = row.fields.name ?? '';
            expect(row.fields.dosage ?? '', `${key} ${name} dosage`).toBe(oldDosage.get(name) ?? '');
        });
        if (oldNames.length === 0) {
            expect(card.empty, key).toBe('None recorded');
        }
    }

    await oldSession.context().close();
});
