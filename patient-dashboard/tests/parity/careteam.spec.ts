import { expect, test } from '@playwright/test';
import { fixture } from '../support/fixtures';
import type { FixtureKey } from '../support/fixtures';
import { logInThroughOpenEmr } from '../support/login';
import { readNewCard } from '../support/newApp';
import { openOldSession, readOldCareTeam, showOldPatient } from '../support/oldDashboard';

// Compared (modules/care-team.md): team name and status; each member's type, role and facility, in order;
// since where FHIR has it. Approved exceptions, applied below:
//   BM-028  a member FHIR cannot resolve is shown as "Name unavailable" (the old card shows the name)
//   BM-030  no team shows "No care team recorded" (old: a header row and no message)
//   BM-037  since is shown only when FHIR has a period; member status, note and the Remove column are left out
const FIXTURES: FixtureKey[] = ['TP-TYPICAL', 'TP-EMPTY'];

test('care team matches the old dashboard for every fixture, with the approved exceptions', async ({
    page,
    browser,
}) => {
    test.setTimeout(180_000);
    await logInThroughOpenEmr(page);
    const oldSession = await openOldSession(browser);

    for (const key of FIXTURES) {
        const patient = fixture(key);
        await showOldPatient(oldSession, patient.pid);
        const old = await readOldCareTeam(oldSession);

        await page.goto(`/patient/${patient.fhirId}`);
        const card = await readNewCard(page, 'care-team');
        const teams = card.rows.filter((row) => row.item === 'team').map((row) => row.fields);
        const members = card.rows.filter((row) => row.item === 'member').map((row) => row.fields);

        expect(card.state, key).toBe('ready');
        expect(card.patientId, key).toBe(patient.fhirId);
        if (old.team === null) {
            expect(teams, key).toEqual([]);
            expect(card.empty, key).toBe('No care team recorded');
            continue;
        }
        expect(teams, key).toEqual([{ name: old.team.name, status: old.team.status }]);
        expect(
            members.map(({ type, role, facility }) => ({ type, role, facility })),
            key,
        ).toEqual(old.members.map(({ type, role, facility }) => ({ type, role, facility })));
        members.forEach((member, index) => {
            const was = old.members[index];
            expect([was?.member, 'Name unavailable'], `${key} member ${index}`).toContain(member.name);
            if (member.since !== '') {
                expect(member.since, `${key} member ${index}`).toBe(was?.since);
            }
        });
    }

    await oldSession.context().close();
});
