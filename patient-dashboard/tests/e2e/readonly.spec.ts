import { expect, test } from '@playwright/test';
import { fixture } from '../support/fixtures';
import { logInThroughOpenEmr } from '../support/login';

// BM-013: the old cards showed Edit to users without write access. ARC-06: the dashboard edits
// prescriptions only; the other clinical cards link to OpenEMR for editing, and nothing offers delete.
test('clinical cards offer no delete, and edit only where decided (BM-013, ARC-06)', async ({ page }) => {
    await logInThroughOpenEmr(page);
    await page.goto(`/patient/${fixture('TP-TYPICAL').fhirId}`);

    for (const name of ['allergies', 'problems', 'medications', 'care-team']) {
        const card = page.locator(`[data-card="${name}"]`);
        await expect(card, name).toHaveAttribute('data-state', 'ready');
        // The one button such a card has is its title, which only opens and closes the card (CardFrame).
        await expect(card.getByRole('button'), name).toHaveCount(1);
        await expect(card.getByRole('button'), name).toHaveAttribute('aria-expanded', 'true');
        await expect(card.getByRole('link', { name: 'Edit in OpenEMR' }), name).toHaveCount(1);
        await expect(card.locator('input, select, textarea, form'), name).toHaveCount(0);
    }
    await expect(page.locator('[data-card="prescriptions"]')).toHaveAttribute('data-state', 'ready');
    await expect(page.getByRole('button', { name: /delete/i })).toHaveCount(0);
});
