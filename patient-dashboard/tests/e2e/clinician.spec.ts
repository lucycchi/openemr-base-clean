import { expect, test } from '@playwright/test';
import { fixture } from '../support/fixtures';
import { logInThroughOpenEmr, PHYSICIAN } from '../support/login';

// Every other test logs in as admin. OpenEMR's API lets only administrators read Practitioner and
// Organization, so a clinician saw "Name unavailable" for every provider and facility until the BFF
// got its server-only name lookup (Fable review F1). This test logs in as a Physicians-group user.
test('a non-admin physician sees provider and facility names, as on the old dashboard', async ({ page }) => {
    await logInThroughOpenEmr(page, PHYSICIAN);
    await page.goto(`/patient/${fixture('TP-TYPICAL').fhirId}`);

    for (const card of ['allergies', 'problems', 'medications', 'prescriptions', 'care-team', 'encounter-history']) {
        await expect(page.locator(`[data-card="${card}"]`), card).toHaveAttribute('data-state', 'ready');
    }
    const diabetesReview = page.locator('[data-item="encounter"]', { hasText: 'Diabetes review' });
    await expect(diabetesReview.locator('[data-field="provider"]')).toHaveText('Lee, Donna');
    await expect(
        page.locator('[data-card="care-team"] [data-item="member"]').first().locator('[data-field="facility"]'),
    ).toHaveText('Great Clinic');
});
