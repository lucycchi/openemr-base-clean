import { expect, test } from '@playwright/test';
import { fixture } from '../support/fixtures';
import { logInThroughOpenEmr } from '../support/login';

// BM-013: the old cards showed Edit to users without write access. The new dashboard is read-only,
// so no clinical card offers an edit or add control at all; the only button is the collapse toggle.
test('clinical cards have no edit or add controls (BM-013)', async ({ page }) => {
    await logInThroughOpenEmr(page);
    await page.goto(`/patient/${fixture('TP-TYPICAL').fhirId}`);

    for (const name of ['allergies', 'problems', 'medications', 'prescriptions', 'care-team']) {
        const card = page.locator(`[data-card="${name}"]`);
        await expect(card, name).toHaveAttribute('data-state', 'ready');
        // The one button a card has is its title, which only opens and closes the card (CardFrame).
        await expect(card.getByRole('button'), name).toHaveCount(1);
        await expect(card.getByRole('button'), name).toHaveAttribute('aria-expanded', 'true');
        await expect(card.getByRole('link', { name: /edit|add/i }), name).toHaveCount(0);
        await expect(card.locator('input, select, textarea, form'), name).toHaveCount(0);
    }
});
