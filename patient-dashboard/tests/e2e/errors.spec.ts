import { expect, test } from '@playwright/test';
import { fixture } from '../support/fixtures';

// A session the BFF does not recognise (expired, or revoked by a restart) must land on the login
// page, never on a dashboard of empty cards. The 401 path inside the API client is unit-tested with
// the OpenEMR error bodies recorded in API-SPIKE.md.
test('rejected session shows the login page, not empty cards', async ({ page, context }) => {
    await context.addCookies([{ name: 'pd_sid', value: 'not-a-live-session', domain: 'localhost', path: '/' }]);

    await page.goto(`/patient/${fixture('TP-TYPICAL').fhirId}`);

    await expect(page.getByRole('link', { name: 'Log in with OpenEMR' })).toBeVisible();
    await expect(page.locator('[data-card]')).toHaveCount(0);
});
