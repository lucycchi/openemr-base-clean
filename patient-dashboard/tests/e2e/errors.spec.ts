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
    // The card reads the standard API's problem list through the BFF (BM-051).
    await page.route('**/api/list-dates?list=medical_problem**', (route) =>
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

// BM-044: the medication list needs its end dates from the standard API. If they cannot be read, the
// Medications card must say so rather than guess, while Prescriptions still shows from FHIR.
test('medications show "Couldn\'t load" when the list end dates fail, prescriptions still show', async ({ page }) => {
    await logInThroughOpenEmr(page);
    await page.route('**/api/list-dates?list=medication**', (route) =>
        route.fulfill({ status: 502, contentType: 'application/json', body: '{"error":"boom"}' }),
    );

    await page.goto(`/patient/${fixture('TP-TYPICAL').fhirId}`);

    const medications = page.locator('[data-card="medications"]');
    await expect(medications).toHaveAttribute('data-state', 'error');
    await expect(medications).toContainText("Couldn't load medications");
    await expect(page.locator('[data-card="prescriptions"]')).toHaveAttribute('data-state', 'ready');
});

// BM-047: the allergy card needs each allergy's resolved flag and end date from the standard API.
// If they cannot be read, it must say so rather than show FHIR's status, which can be wrong.
test('allergies show "Couldn\'t load" when the allergy list dates fail', async ({ page }) => {
    await logInThroughOpenEmr(page);
    await page.route('**/api/list-dates?list=allergy**', (route) =>
        route.fulfill({ status: 502, contentType: 'application/json', body: '{"error":"boom"}' }),
    );

    await page.goto(`/patient/${fixture('TP-TYPICAL').fhirId}`);

    const card = page.locator('[data-card="allergies"]');
    await expect(card).toHaveAttribute('data-state', 'error');
    await expect(card).toContainText("Couldn't load allergies");
});

// Fable review 2: if the staff-name lookup itself fails, the card must say so, distinct from
// "Name unavailable", which means OpenEMR has no readable record for that person.
test('a failed staff-name lookup says "Name couldn\'t be loaded", never "Name unavailable"', async ({ page }) => {
    await logInThroughOpenEmr(page);
    await page.route('**/api/display-names**', (route) =>
        route.fulfill({ status: 502, contentType: 'application/json', body: '{"error":"boom"}' }),
    );

    await page.goto(`/patient/${fixture('TP-TYPICAL').fhirId}`);

    const visit = page.locator('[data-item="encounter"]', { hasText: 'Diabetes review' });
    await expect(visit.locator('[data-field="provider"]')).toHaveText("Name couldn't be loaded");
    await expect(page.locator('[data-card="care-team"]')).toHaveAttribute('data-state', 'ready');
});
