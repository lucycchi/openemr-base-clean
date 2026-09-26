import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';
import { fixture } from '../support/fixtures';
import type { FixtureKey } from '../support/fixtures';
import { logInThroughOpenEmr } from '../support/login';
import { readNewCard } from '../support/newApp';
import type { OldVisit } from '../support/oldDashboard';
import { openOldSession, readOldVisitHistory } from '../support/oldDashboard';

// Compared (modules/extra-encounter-history.md): date, reason and provider of each visit, in order, first
// the default page (encounter_page_size, 20 here) and then, when there are more, every visit.
// Approved exceptions, applied below:
//   BM-032  a provider FHIR does not send (no NPI) is "Name unavailable" (the old page shows the name)
//   BM-034  "Show all" replaces the old page picker and Prev / Next
//   BM-035  no visits shows "No encounters recorded" (old: "1-0 of 0" and a header row)
//   BM-039  Issue, forms, Billing, Insurance and interleaved documents are not compared
const FIXTURES: FixtureKey[] = ['TP-TYPICAL', 'TP-HISTORY', 'TP-LONG', 'TP-EMPTY'];

async function newVisits(page: Page): Promise<OldVisit[]> {
    const card = await readNewCard(page, 'encounter-history');
    return card.rows.map(({ fields }) => ({
        date: fields.date ?? '',
        reason: fields.reason ?? '',
        provider: fields.provider ?? '',
    }));
}

function expectSameVisits(shown: OldVisit[], old: OldVisit[], label: string): void {
    expect(
        shown.map(({ date, reason }) => ({ date, reason })),
        label,
    ).toEqual(old.map(({ date, reason }) => ({ date, reason })));
    shown.forEach((visit, index) => {
        expect([old[index]?.provider, 'Name unavailable'], `${label} visit ${index}`).toContain(visit.provider);
    });
}

test('encounter history matches the old Visit History page for every fixture, with the approved exceptions', async ({
    page,
    browser,
}) => {
    test.setTimeout(180_000);
    await logInThroughOpenEmr(page);
    const oldSession = await openOldSession(browser);

    for (const key of FIXTURES) {
        const patient = fixture(key);
        const oldPage = await readOldVisitHistory(oldSession, patient.pid);

        await page.goto(`/patient/${patient.fhirId}`);
        const card = await readNewCard(page, 'encounter-history');
        expect(card.state, key).toBe('ready');
        expect(card.patientId, key).toBe(patient.fhirId);
        expectSameVisits(await newVisits(page), oldPage, `${key} first page`);

        const showAll = page.locator('[data-card="encounter-history"]').getByRole('button', { name: /^Show all/ });
        if ((await showAll.count()) > 0) {
            await showAll.click();
            expectSameVisits(
                await newVisits(page),
                await readOldVisitHistory(oldSession, patient.pid, 0),
                `${key} all`,
            );
        }
        if (oldPage.length === 0) {
            expect(card.empty, key).toBe('No encounters recorded');
        }
    }

    await oldSession.context().close();
});
