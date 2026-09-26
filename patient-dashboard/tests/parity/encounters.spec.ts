import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';
import type { Bundle, Encounter } from 'fhir/r4';
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

/** date|reason of every visit FHIR sends with a primary performer, whose provider must therefore be named. */
async function visitsWithProvider(page: Page, fhirId: string): Promise<Set<string>> {
    const res = await page.request.get(`/api/fhir/Encounter?patient=${fhirId}`);
    const bundle = (await res.json()) as Bundle<Encounter>;
    return new Set(
        (bundle.entry ?? [])
            .map((entry) => entry.resource)
            .filter((encounter) =>
                (encounter?.participant ?? []).some((p) =>
                    p.type?.some((t) => t.coding?.some((c) => c.code === 'PPRF')),
                ),
            )
            .map(
                (encounter) =>
                    `${encounter?.period?.start?.slice(0, 10) ?? ''}|${encounter?.reasonCode?.[0]?.text ?? ''}`,
            ),
    );
}

function expectSameVisits(shown: OldVisit[], old: OldVisit[], named: Set<string>, label: string): void {
    expect(
        shown.map(({ date, reason }) => ({ date, reason })),
        label,
    ).toEqual(old.map(({ date, reason }) => ({ date, reason })));
    shown.forEach((visit, index) => {
        // "Name unavailable" only where FHIR sends no primary performer (BM-032); otherwise the name must match.
        const allowed = named.has(`${visit.date}|${visit.reason}`)
            ? [old[index]?.provider]
            : [old[index]?.provider, 'Name unavailable'];
        expect(allowed, `${label} visit ${index}`).toContain(visit.provider);
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
        const named = await visitsWithProvider(page, patient.fhirId);
        expectSameVisits(await newVisits(page), oldPage, named, `${key} first page`);

        const showAll = page.locator('[data-card="encounter-history"]').getByRole('button', { name: /^Show all/ });
        if ((await showAll.count()) > 0) {
            await showAll.click();
            expectSameVisits(
                await newVisits(page),
                await readOldVisitHistory(oldSession, patient.pid, 0),
                named,
                `${key} all`,
            );
        }
        if (oldPage.length === 0) {
            expect(card.empty, key).toBe('No encounters recorded');
        }
    }

    await oldSession.context().close();
});
