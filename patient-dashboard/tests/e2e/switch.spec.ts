import { expect, test } from '@playwright/test';
import { fixture } from '../support/fixtures';
import { logInThroughOpenEmr } from '../support/login';

const TYPICAL = fixture('TP-TYPICAL');
const HISTORY = fixture('TP-HISTORY');

test.describe('switching patients', () => {
    test('switching patients clears every card before new data arrives', async ({ page }) => {
        await logInThroughOpenEmr(page);
        await page.goto(`/patient/${TYPICAL.fhirId}`);
        await expect(page.locator('[data-card="header"] [data-item="name"]')).toHaveText('Tessa Typical');

        // Slow down the next patient's data so any stale frame would be visible.
        await page.route(`**/api/fhir/Patient/${HISTORY.fhirId}`, async (route) => {
            await new Promise((resolve) => setTimeout(resolve, 800));
            await route.continue();
        });
        // From the moment of the click (capture phase, before React handles it), sample every card's
        // text on every animation frame. Sampling, not DOM mutations: a stale card that simply stays on
        // screen produces no mutation, and must still be caught.
        await page.evaluate(() => {
            const w = window as unknown as { __clicked: boolean; __cardFrames: string[] };
            w.__clicked = false;
            w.__cardFrames = [];
            document.addEventListener(
                'click',
                (event) => {
                    if ((event.target as HTMLElement).closest('[role="search"] li button') !== null) {
                        w.__clicked = true;
                    }
                },
                true,
            );
            const sample = () => {
                if (w.__clicked) {
                    w.__cardFrames.push(
                        Array.from(document.querySelectorAll('[data-card]'))
                            .map((card) => card.textContent ?? '')
                            .join(' | '),
                    );
                }
                requestAnimationFrame(sample);
            };
            requestAnimationFrame(sample);
        });

        await page.getByLabel('Find a patient').fill('History');
        await page.getByRole('search').evaluate((form) => (form as HTMLFormElement).requestSubmit());
        await page.getByRole('button', { name: /^Hugo History/ }).click();

        await expect(page.locator('[data-card="header"] [data-item="name"]')).toHaveText('Hugo History');
        expect(page.url()).toContain(`/patient/${HISTORY.fhirId}`);
        const frames = await page.evaluate(() => (window as unknown as { __cardFrames: string[] }).__cardFrames);
        expect(frames.length).toBeGreaterThan(5); // the 800 ms delay spans many frames
        expect(frames.filter((frame) => frame.includes('Tessa'))).toEqual([]);
    });

    test('every rendered resource references the header patient', async ({ page }) => {
        await logInThroughOpenEmr(page);

        for (const patient of [TYPICAL, HISTORY]) {
            await page.goto(`/patient/${patient.fhirId}`);
            await expect(page.locator('[data-card="header"][data-state="ready"]')).toBeVisible();

            const owners = await page
                .locator('[data-card][data-state="ready"]')
                .evaluateAll((cards) => cards.map((card) => card.getAttribute('data-patient-id')));
            expect(owners.length).toBeGreaterThan(0);
            expect(owners.every((owner) => owner === patient.fhirId)).toBe(true);
        }
    });
});
