import { expect, test } from '@playwright/test';
import { fixture } from '../support/fixtures';
import { logInThroughOpenEmr } from '../support/login';

// A session the BFF does not recognise (expired, or revoked by a restart) must land on the login
// page, never on a dashboard of empty cards. The 401 path inside the API client is unit-tested with
// the OpenEMR error bodies recorded in API-SPIKE.md.
test('rejected session shows the login page, not empty cards', async ({ page, context }) => {
    await context.addCookies([{ name: 'pd_sid', value: 'not-a-live-session', domain: 'localhost', path: '/' }]);

    await page.goto(`/patient/${fixture('TP-TYPICAL').fhirId}`);

    await expect(page.getByRole('link', { name: 'Log in with OpenEMR' })).toBeVisible();
    await expect(page.locator('[data-card]')).toHaveCount(0);
});

test('allergies card shows "Couldn\'t load" on API failure', async ({ page }) => {
    await logInThroughOpenEmr(page);
    await page.route('**/api/fhir/AllergyIntolerance**', (route) =>
        route.fulfill({ status: 502, contentType: 'application/json', body: '{"error":"OpenEMR did not respond"}' }),
    );

    await page.goto(`/patient/${fixture('TP-TYPICAL').fhirId}`);

    const card = page.locator('[data-card="allergies"]');
    await expect(card).toHaveAttribute('data-state', 'error');
    await expect(card).toContainText("Couldn't load allergies");
    await expect(card).not.toContainText('No allergies recorded');
});

test('problem list shows "Couldn\'t load" on API failure', async ({ page }) => {
    await logInThroughOpenEmr(page);
    await page.route('**/api/fhir/Condition**', (route) =>
        route.fulfill({ status: 502, contentType: 'application/json', body: '{"error":"OpenEMR did not respond"}' }),
    );

    await page.goto(`/patient/${fixture('TP-TYPICAL').fhirId}`);

    const card = page.locator('[data-card="problems"]');
    await expect(card).toHaveAttribute('data-state', 'error');
    await expect(card).toContainText("Couldn't load medical problems");
});

test('medications show "Couldn\'t load" on API failure', async ({ page }) => {
    await logInThroughOpenEmr(page);
    await page.route('**/api/fhir/MedicationRequest**', (route) =>
        route.fulfill({ status: 502, contentType: 'application/json', body: '{"error":"OpenEMR did not respond"}' }),
    );

    await page.goto(`/patient/${fixture('TP-TYPICAL').fhirId}`);

    const card = page.locator('[data-card="medications"]');
    await expect(card).toHaveAttribute('data-state', 'error');
    await expect(card).toContainText("Couldn't load medications");
});

test('prescriptions show "Couldn\'t load" on API failure', async ({ page }) => {
    await logInThroughOpenEmr(page);
    await page.route('**/api/fhir/MedicationRequest**', (route) =>
        route.fulfill({ status: 502, contentType: 'application/json', body: '{"error":"OpenEMR did not respond"}' }),
    );

    await page.goto(`/patient/${fixture('TP-TYPICAL').fhirId}`);

    const card = page.locator('[data-card="prescriptions"]');
    await expect(card).toHaveAttribute('data-state', 'error');
    await expect(card).toContainText("Couldn't load prescriptions");
});

test('care team shows "Couldn\'t load" on API failure', async ({ page }) => {
    await logInThroughOpenEmr(page);
    await page.route('**/api/fhir/CareTeam**', (route) =>
        route.fulfill({ status: 502, contentType: 'application/json', body: '{"error":"OpenEMR did not respond"}' }),
    );

    await page.goto(`/patient/${fixture('TP-TYPICAL').fhirId}`);

    const card = page.locator('[data-card="care-team"]');
    await expect(card).toHaveAttribute('data-state', 'error');
    await expect(card).toContainText("Couldn't load the care team");
});

test('encounter history shows "Couldn\'t load" on API failure', async ({ page }) => {
    await logInThroughOpenEmr(page);
    await page.route('**/api/fhir/Encounter**', (route) =>
        route.fulfill({ status: 502, contentType: 'application/json', body: '{"error":"OpenEMR did not respond"}' }),
    );

    await page.goto(`/patient/${fixture('TP-TYPICAL').fhirId}`);

    const card = page.locator('[data-card="encounter-history"]');
    await expect(card).toHaveAttribute('data-state', 'error');
    await expect(card).toContainText("Couldn't load encounters");
});

// Old demographics.php always applies the site's hidden cards. If the settings cannot be loaded the
// new app must not guess: it shows an error and no cards, rather than every card.
test('a failed settings load shows an error, never cards the site may have hidden', async ({ page }) => {
    await logInThroughOpenEmr(page);
    await page.route('**/app-config', (route) =>
        route.fulfill({ status: 500, contentType: 'application/json', body: '{"error":"boom"}' }),
    );

    await page.goto(`/patient/${fixture('TP-TYPICAL').fhirId}`);

    await expect(page.getByRole('alert')).toContainText("Couldn't load the dashboard settings");
    await expect(page.locator('[data-card]')).toHaveCount(0);
});
