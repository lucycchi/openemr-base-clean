import { expect, test } from '@playwright/test';
import { fixture } from '../support/fixtures';
import { logInThroughOpenEmr } from '../support/login';

// The port reimplements the old dashboard's interface rather than redesigning it. These tests pin
// the parts of the old look that carry meaning: the identity bar stays in view (it sits in the tab
// frame above the scrolling dashboard), the first three cards share a row (demographics.php:1091-1102,
// one col-md-N per visible card), and each card's title collapses it (card_base.html.twig).

test.beforeEach(async ({ page }) => {
    await page.setViewportSize({ width: 1400, height: 900 });
    await logInThroughOpenEmr(page);
    await page.goto(`/patient/${fixture('TP-LONG').fhirId}`);
    await expect(page.locator('[data-card="medications"]')).toHaveAttribute('data-state', 'ready');
});

test('the patient header stays on screen while a long chart scrolls', async ({ page }) => {
    await page.mouse.wheel(0, 4000);
    await expect.poll(() => page.evaluate(() => window.scrollY)).toBeGreaterThan(1000);

    const header = page.locator('[data-card="header"]');
    await expect(header).toBeInViewport();
    const box = await header.boundingBox();
    expect(box?.y).toBeGreaterThanOrEqual(0);
    expect(box?.y).toBeLessThan(5);
});

test('Allergies, Medical Problems and Medications sit side by side, as on the old dashboard', async ({ page }) => {
    const box = async (card: string) => {
        const found = await page.locator(`[data-card="${card}"]`).boundingBox();
        if (found === null) {
            throw new Error(`${card} card not drawn`);
        }
        return found;
    };
    const allergies = await box('allergies');
    const problems = await box('problems');
    const medications = await box('medications');
    expect(Math.abs(allergies.y - problems.y)).toBeLessThan(2);
    expect(Math.abs(problems.y - medications.y)).toBeLessThan(2);
    expect(allergies.x).toBeLessThan(problems.x);
    expect(problems.x).toBeLessThan(medications.x);
});

test('a card title collapses the card and opens it again', async ({ page }) => {
    const card = page.locator('[data-card="allergies"]');
    const firstAllergy = card.locator('[data-item="allergy"]').first();
    await expect(firstAllergy).toBeVisible();

    await card.getByRole('button', { name: 'Allergies' }).click();
    await expect(firstAllergy).toBeHidden();

    await card.getByRole('button', { name: 'Allergies' }).click();
    await expect(firstAllergy).toBeVisible();
});
