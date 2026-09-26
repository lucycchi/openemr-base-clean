import { expect, test } from '@playwright/test';

test('the SPA shell loads from the BFF origin', async ({ page }) => {
    const response = await page.goto('/');

    expect(response?.status()).toBe(200);
    await expect(page).toHaveTitle('Patient Dashboard');
    await expect(page.getByRole('heading', { level: 1, name: 'Patient Dashboard' })).toBeVisible();
});
