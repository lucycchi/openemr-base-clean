import { expect, test } from '@playwright/test';
import { fixture } from '../support/fixtures';
import type { FixtureKey } from '../support/fixtures';
import { logInThroughOpenEmr } from '../support/login';
import { readNewCard } from '../support/newApp';
import { openOldSession, readOldCard, showOldPatient } from '../support/oldDashboard';

// Compared (modules/allergies.md, section 2): which allergies are shown, in order, and each reaction.
// Approved exceptions, applied below:
//   BM-011  severity words become FHIR risk levels, so the bracketed text is not compared
//   BM-012  both empty states ("Nothing Recorded", "No Known Allergies") become "No allergies recorded"
//   BM-015  no empty "()" when there is no risk level
const FIXTURES: FixtureKey[] = ['TP-TYPICAL', 'TP-EMPTY', 'TP-NKA', 'TP-HISTORY', 'TP-LONG', 'TP-ESCAPING'];
const OLD_EMPTY = ['Nothing Recorded', 'No Known Allergies'];

interface Row {
    name: string;
    reaction: string;
}

const reactionOf = (tooltip: string | null) => /Reaction: (.*?) -/.exec(tooltip ?? '')?.[1]?.trim() ?? '';

test('allergies match the old dashboard for every fixture, with the approved exceptions', async ({ page, browser }) => {
    test.setTimeout(180_000);
    await logInThroughOpenEmr(page);
    const oldSession = await openOldSession(browser);

    for (const key of FIXTURES) {
        const patient = fixture(key);
        await showOldPatient(oldSession, patient.pid);
        const old = await readOldCard(oldSession, 'allergy_ps_expand');
        const oldRows: Row[] = old.items
            .filter((item) => !OLD_EMPTY.includes(item))
            .map((item, index) => ({
                // The stored title, shown as text on both cards; markup-like characters are displayed, never
                // rendered (the list title supersedes BM-009's narrative text, Opus review 4).
                name: item
                    .replace(/ \([^)]*\)$/, '')
                    .replace(/\s+/g, ' ')
                    .trim(),
                reaction: reactionOf(old.tooltips[index] ?? null),
            }));

        await page.goto(`/patient/${patient.fhirId}`);
        const card = await readNewCard(page, 'allergies');
        const newRows: Row[] = card.rows.map((row) => ({
            name: row.text.replace(/ \((Low risk|High risk|Unable to assess)\)$/, ''),
            reaction: reactionOf(row.title),
        }));

        expect(card.state, key).toBe('ready');
        expect(card.patientId, key).toBe(patient.fhirId);
        expect(newRows, key).toEqual(oldRows);
        if (oldRows.length === 0) {
            expect(card.empty, key).toBe('No allergies recorded');
        }
    }

    await oldSession.context().close();
});
