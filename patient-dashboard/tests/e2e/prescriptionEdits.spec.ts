import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';
import { fixture } from '../support/fixtures';
import { logInThroughOpenEmr, PHYSICIAN } from '../support/login';
import { openOldDashboard, readOldCard } from '../support/oldDashboard';

// Prescriptions are added, changed and discontinued from the dashboard (ARC-06), and the old dashboard
// must see the same result. Only TP-RXEDIT is written to; the parity patients are never changed.
const RX = fixture('TP-RXEDIT');
const card = (page: Page) => page.locator('[data-card="prescriptions"]');
const rows = (page: Page, drug: string) => card(page).locator('[data-item="prescription"]', { hasText: drug });

/** Discontinues every prescription on TP-RXEDIT through the card, so each test starts from none. */
async function clearPrescriptions(page: Page) {
    for (;;) {
        const discontinue = card(page)
            .getByRole('button', { name: /^Discontinue / })
            .first();
        if ((await discontinue.count()) === 0) {
            return;
        }
        const before = await card(page).locator('[data-item="prescription"]').count();
        await discontinue.click();
        await page.getByRole('button', { name: 'Yes, discontinue' }).click();
        await expect(card(page).locator('[data-item="prescription"]')).toHaveCount(before - 1);
    }
}

/** Adds one prescription through the card's form. */
async function add(page: Page, drug: string, quantity = '') {
    await card(page).getByRole('button', { name: 'Add prescription' }).click();
    await page.getByLabel('Drug').fill(drug);
    if (quantity !== '') {
        await page.getByLabel('Quantity').fill(quantity);
    }
    await page.getByRole('button', { name: 'Save' }).click();
    await expect(rows(page, drug)).toHaveCount(1);
}

test.beforeEach(async ({ page }) => {
    await logInThroughOpenEmr(page, PHYSICIAN);
    await page.goto(`/patient/${RX.fhirId}`);
    await expect(card(page)).toHaveAttribute('data-state', 'ready');
    await clearPrescriptions(page);
});

test('a physician adds a prescription, and the old dashboard shows it', async ({ page, browser }) => {
    const drug = `E2E add ${Date.now().toString(36)}`;
    await add(page, drug, '21');
    await expect(rows(page, drug).locator('[data-field="quantity"]')).toHaveText('21');

    const old = await openOldDashboard(browser, RX.pid);
    const oldRx = await readOldCard(old, 'prescriptions_ps_expand');
    expect(oldRx.items.join('\n')).toContain(drug);
    await old.context().close();
});

test('Change adds the corrected prescription and discontinues the old one', async ({ page }) => {
    const drug = `E2E change ${Date.now().toString(36)}`;
    await add(page, drug, '21');
    await card(page)
        .getByRole('button', { name: `Change ${drug}` })
        .click();
    await page.getByLabel('Quantity').fill('30');
    await page.getByRole('button', { name: 'Save' }).click();
    await expect(rows(page, drug).locator('[data-field="quantity"]')).toHaveText('30');
    await expect(rows(page, drug)).toHaveCount(1);
});

test("Discontinue asks first and removes it from the card; OpenEMR's own card still lists it (BM-067)", async ({
    page,
    browser,
}) => {
    const drug = `E2E stop ${Date.now().toString(36)}`;
    await add(page, drug);
    await card(page)
        .getByRole('button', { name: `Discontinue ${drug}` })
        .click();
    await page.getByRole('button', { name: 'Keep it' }).click();
    await expect(rows(page, drug)).toHaveCount(1);
    await card(page)
        .getByRole('button', { name: `Discontinue ${drug}` })
        .click();
    await page.getByRole('button', { name: 'Yes, discontinue' }).click();
    await expect(rows(page, drug)).toHaveCount(0);

    // OpenEMR's API discontinue sets active = 0, which OpenEMR's legacy screens read back as active
    // (ORDataObject.php skips empty fields), so the old card still lists it. Kept by the user's decision
    // (ARC-06) and warned about in the Discontinue question; if OpenEMR fixes this, this line will fail.
    const old = await openOldDashboard(browser, RX.pid);
    expect((await readOldCard(old, 'prescriptions_ps_expand')).items.join('\n')).toContain(drug);
    await old.context().close();
});
