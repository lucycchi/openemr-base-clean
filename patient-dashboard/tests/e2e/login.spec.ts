import { expect, test } from '@playwright/test';
import { logInThroughOpenEmr } from '../support/login';

test('login then logout returns to the login page', async ({ page }) => {
    await logInThroughOpenEmr(page);

    await page.getByRole('button', { name: 'Log out' }).click();
    await expect(page.getByRole('link', { name: 'Log in with OpenEMR' })).toBeVisible();
});
