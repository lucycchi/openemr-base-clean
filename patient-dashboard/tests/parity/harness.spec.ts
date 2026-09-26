import { expect, test } from '@playwright/test';
import { fixture } from '../support/fixtures';
import { openOldDashboard, readOldCard } from '../support/oldDashboard';

test.describe('old dashboard reader', () => {
    test('old dashboard reads TP-TYPICAL allergies as ["Penicillin (Moderate)", "Peanuts ()"]', async ({ browser }) => {
        const old = await openOldDashboard(browser, fixture('TP-TYPICAL').pid);

        const card = await readOldCard(old, 'allergy_ps_expand');

        expect(card.items).toEqual(['Penicillin (Moderate)', 'Peanuts ()']);
        expect(card.tooltips).toEqual(['Penicillin Reaction: Hives - Moderate', 'Peanuts Reaction: -']);
        await old.context().close();
    });

    test('collapsed medications card items are readable', async ({ browser }) => {
        const old = await openOldDashboard(browser, fixture('TP-TYPICAL').pid);

        const card = await readOldCard(old, 'medication_ps_expand');

        // Each row is name then dosage (Metformin has seeded dosage text), so compare the name part.
        expect(card.parts.map((parts) => parts[0])).toContain('Metformin 500 mg');
        expect(card.items).toHaveLength(4);
        await old.context().close();
    });
});
