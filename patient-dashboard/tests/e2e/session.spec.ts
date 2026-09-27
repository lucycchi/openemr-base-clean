import { expect, test } from '@playwright/test';
import { fixture } from '../support/fixtures';
import { logInThroughOpenEmr } from '../support/login';

// The old tab frame signs out on its timeout global and never leaves a chart up after the session
// has ended. The page rechecks its session on returning to the tab (and every minute), so a chart is
// cleared once the server session is gone (Opus review 4).
test('a chart is cleared when the server session has ended', async ({ page }) => {
    await logInThroughOpenEmr(page);
    await page.goto(`/patient/${fixture('TP-TYPICAL').fhirId}`);
    await expect(page.locator('[data-card="allergies"]')).toHaveAttribute('data-state', 'ready');

    await page.request.post('/auth/logout'); // the server session ends, as after its idle timeout
    await page.evaluate(() => document.dispatchEvent(new Event('visibilitychange')));

    await expect(page.getByRole('link', { name: 'Log in with OpenEMR' })).toBeVisible();
    await expect(page.locator('[data-card]')).toHaveCount(0);
});
