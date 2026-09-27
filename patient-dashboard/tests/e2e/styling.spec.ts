import { expect, test } from '@playwright/test';
import { fixture } from '../support/fixtures';
import { logInThroughOpenEmr } from '../support/login';

// The old card marks a high-risk allergy's severity with Bootstrap's bg-warning and font-weight-bold
// (templates/patient/card/allergies.html.twig:40, 45); TP-LONG's "Long-list allergen 01" is severe.
// A class alone proves nothing: the emphasis must actually render, which needs the stylesheet
// loaded (BM-011, BM-014).
test('a high-risk allergy is visibly highlighted', async ({ page }) => {
    await logInThroughOpenEmr(page);
    await page.goto(`/patient/${fixture('TP-LONG').fhirId}`);

    // As on the old card, only the risk in brackets is highlighted, not the whole row.
    const high = page.locator('[data-card="allergies"] [data-highlight] [data-field="risk"]').first();
    await expect(high).toBeVisible();
    const style = await high.evaluate((node) => {
        const computed = getComputedStyle(node);
        return { background: computed.backgroundColor, weight: Number(computed.fontWeight) };
    });

    expect(style.background).not.toBe('rgba(0, 0, 0, 0)');
    expect(style.weight).toBeGreaterThanOrEqual(700);
});
