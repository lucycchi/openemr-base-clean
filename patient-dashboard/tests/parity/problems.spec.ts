import { expect, test } from '@playwright/test';
import { fixture } from '../support/fixtures';
import type { FixtureKey } from '../support/fixtures';
import { logInThroughOpenEmr } from '../support/login';
import { readNewCard } from '../support/newApp';
import { openOldSession, readOldCard, showOldPatient } from '../support/oldDashboard';

// Compared (modules/problem-list.md, section 2): which problems are shown, in order (BM-018).
// Approved exceptions, applied below:
//   BM-012  the old "Nothing Recorded" / "None" become "None recorded"
// The card reads the old card's own source, the standard API's problem list (BM-051), so nothing else differs.
const FIXTURES: FixtureKey[] = ['TP-TYPICAL', 'TP-EMPTY', 'TP-HISTORY', 'TP-LONG'];
const OLD_EMPTY = ['Nothing Recorded', 'None'];

test('problems match the old dashboard for every fixture, with the approved exceptions', async ({ page, browser }) => {
    test.setTimeout(180_000);
    await logInThroughOpenEmr(page);
    const oldSession = await openOldSession(browser);

    for (const key of FIXTURES) {
        const patient = fixture(key);
        await showOldPatient(oldSession, patient.pid);
        const old = await readOldCard(oldSession, 'medical_problem_ps_expand');
        const oldNames = old.items.filter((item) => !OLD_EMPTY.includes(item));

        await page.goto(`/patient/${patient.fhirId}`);
        const card = await readNewCard(page, 'problems');
        const newNames = card.rows.map((row) => row.text);

        expect(card.state, key).toBe('ready');
        expect(card.patientId, key).toBe(patient.fhirId);
        expect(newNames, key).toEqual(oldNames);
        if (oldNames.length === 0) {
            expect(card.empty, key).toBe('None recorded');
        }
    }

    await oldSession.context().close();
});
