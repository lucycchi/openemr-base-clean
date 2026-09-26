import { expect, test } from '@playwright/test';
import { fixture } from '../support/fixtures';
import type { FixtureKey } from '../support/fixtures';
import { logInThroughOpenEmr } from '../support/login';
import { readNewCard } from '../support/newApp';
import { openOldSession, readOldIdentityBar } from '../support/oldDashboard';

// Compared fields (modules/header.md, section 2): name, MRN, DOB line with age. Sex and status are new
// fields the old header never showed (Gate 2), so they are checked in the unit tests instead.
const FIXTURES: FixtureKey[] = [
    'TP-TYPICAL',
    'TP-EMPTY',
    'TP-NKA',
    'TP-HISTORY',
    'TP-DECEASED',
    'TP-LONG',
    'TP-ESCAPING',
];

test('name, MRN, DOB, age match the old identity bar for all fixtures', async ({ page, browser }) => {
    test.setTimeout(180_000); // seven patients, each read from both dashboards
    await logInThroughOpenEmr(page);
    const oldSession = await openOldSession(browser);

    for (const key of FIXTURES) {
        const patient = fixture(key);
        const old = await readOldIdentityBar(oldSession, patient.pid);

        await page.goto(`/patient/${patient.fhirId}`);
        const card = await readNewCard(page, 'header');
        const items = Object.fromEntries(card.items);

        expect(card.state, key).toBe('ready');
        expect(items.name, key).toBe(old.name);
        expect(items.mrn, key).toBe(`(${old.mrn})`);
        expect(items.dobLine, key).toBe(old.dobLine);
    }

    await oldSession.context().close();
});
