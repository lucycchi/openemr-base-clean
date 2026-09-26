import { expect, test } from '@playwright/test';

// Real login against the development-easy OpenEMR stack (https://localhost:9300) with the
// app's confidential client from .env. Credentials are the dev stack defaults.
const OEMR_USER = process.env.OEMR_USER ?? 'admin';
const OEMR_PASS = process.env.OEMR_PASS ?? 'pass';

test('login then logout returns to the login page', async ({ page }) => {
    await page.goto('/');
    await page.getByRole('link', { name: 'Log in with OpenEMR' }).click();

    await page.waitForURL(/localhost:9300\/oauth2\//);
    await page.locator('input[name="username"]:visible').fill(OEMR_USER);
    await page.locator('input[name="password"]:visible').fill(OEMR_PASS);
    await page.getByRole('button', { name: /OpenEMR Login/ }).click();

    await page.locator('#authorize-btn').click();

    await page.waitForURL((url) => url.origin === new URL(page.url()).origin && url.port === '5180');
    await expect(page.getByRole('button', { name: 'Log out' })).toBeVisible();

    await page.getByRole('button', { name: 'Log out' }).click();
    await expect(page.getByRole('link', { name: 'Log in with OpenEMR' })).toBeVisible();
});
