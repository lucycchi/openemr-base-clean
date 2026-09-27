import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

// Dev stack defaults for the development-easy OpenEMR (https://localhost:9300).
const OEMR_USER = process.env.OEMR_USER ?? 'admin';
const OEMR_PASS = process.env.OEMR_PASS ?? 'pass';

/** The seeded non-admin clinician (fixtures/seed-parity-gaps.php), in OpenEMR's Physicians group. */
export const PHYSICIAN = { user: 'tp-physician', pass: 'tp-physician-pass' };

/** Logs in through the app's "Log in with OpenEMR" link, OpenEMR's login page and its consent page. */
export async function logInThroughOpenEmr(
    page: Page,
    credentials: { user: string; pass: string } = { user: OEMR_USER, pass: OEMR_PASS },
): Promise<void> {
    await page.goto('/');
    await page.getByRole('link', { name: 'Log in with OpenEMR' }).click();

    await page.waitForURL(/\/oauth2\//);
    await page.locator('input[name="username"]:visible').fill(credentials.user);
    await page.locator('input[name="password"]:visible').fill(credentials.pass);
    await page.getByRole('button', { name: /OpenEMR Login/ }).click();

    await page.locator('#authorize-btn').click();

    // Back on the app's own host (the dev BFF on :5180, or the deployed dashboard).
    const appHost = new URL(String(test.info().project.use.baseURL)).host;
    await page.waitForURL((url) => url.host === appHost);
    await expect(page.getByRole('button', { name: 'Log out' })).toBeVisible();
}
