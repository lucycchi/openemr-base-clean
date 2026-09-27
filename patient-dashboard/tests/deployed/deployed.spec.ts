import { expect, test } from '@playwright/test';
import { logInThroughOpenEmr } from '../support/login';

// The droplet's seeded non-admin clinician (fixtures/seed-demo.php); override for another site.
const USER = {
    user: process.env.DEPLOYED_USER ?? 'tp-physician',
    pass: process.env.DEPLOYED_PASS ?? 'tp-physician-pass',
};

test('/healthz returns 200', async ({ request }) => {
    const res = await request.get('/healthz');
    expect(res.status()).toBe(200);
    expect(await res.json()).toEqual({ ok: true });
});

test('droplet login reaches the dashboard shell', async ({ page }) => {
    await logInThroughOpenEmr(page, USER);

    await expect(page.getByLabel('Find a patient')).toBeVisible();
});

test('TP-TYPICAL renders on the droplet, names included, for a non-admin clinician', async ({ page }) => {
    await logInThroughOpenEmr(page, USER);
    await page.getByLabel('Find a patient').fill('Typical');
    await page.getByRole('search').evaluate((form) => (form as HTMLFormElement).requestSubmit());
    await page.getByRole('button', { name: /^Tessa Typical/ }).click();

    await expect(page.locator('[data-card="header"] [data-item="name"]')).toHaveText('Tessa Typical');
    for (const card of ['allergies', 'problems', 'medications', 'prescriptions', 'care-team', 'encounter-history']) {
        await expect(page.locator(`[data-card="${card}"]`), card).toHaveAttribute('data-state', 'ready');
    }
    const diabetesReview = page.locator('[data-item="encounter"]', { hasText: 'Diabetes review' });
    await expect(diabetesReview.locator('[data-field="provider"]')).toHaveText('Lee, Donna');
});

test('TP-DECEASED shows its death date on the droplet', async ({ page }) => {
    await logInThroughOpenEmr(page, USER);
    await page.getByLabel('Find a patient').fill('Deceased');
    await page.getByRole('search').evaluate((form) => (form as HTMLFormElement).requestSubmit());
    await page.getByRole('button', { name: /^Dora Deceased/ }).click();

    await expect(page.locator('[data-card="header"]')).toContainText('Deceased (2025-11-02)');
    await expect(page.locator('[data-card="header"]')).toContainText('Age at death: 93');
});

test('the droplet recognises the user and saves their card layout to its volume', async ({ page }) => {
    await logInThroughOpenEmr(page, USER);
    const read = await page.request.get('/api/card-settings');
    expect(read.status()).toBe(200);
    expect(read.headers()['content-type']).toContain('application/json');
    const { collapsed } = (await read.json()) as { collapsed: string[] };
    const wasCollapsed = collapsed.includes('encounter-history');

    // Flip one card and flip it back, so the user's layout ends as it started.
    for (const open of [wasCollapsed, !wasCollapsed]) {
        const saved = await page.request.put('/api/card-settings', { data: { card: 'encounter-history', open } });
        expect(saved.status()).toBe(200);
    }
    const after = (await (await page.request.get('/api/card-settings')).json()) as { collapsed: string[] };
    expect(after.collapsed.includes('encounter-history')).toBe(wasCollapsed);
});
