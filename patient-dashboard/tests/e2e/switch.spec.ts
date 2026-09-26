import { expect, test } from '@playwright/test';
import { fixture } from '../support/fixtures';
import { logInThroughOpenEmr } from '../support/login';

const TYPICAL = fixture('TP-TYPICAL');
const HISTORY = fixture('TP-HISTORY');

// Text that only TP-TYPICAL's cards contain, one per clinical card, so a stale card is caught by its
// content rather than by attributes the app sets itself.
const TYPICAL_MARKERS = [
    'Tessa',
    'Penicillin',
    'Essential hypertension',
    'Metformin',
    'Amlodipine',
    'practitioner',
    'Diabetes review',
];

test.describe('switching patients', () => {
    test('switching patients never shows the previous patient in any card', async ({ page }) => {
        await logInThroughOpenEmr(page);
        await page.goto(`/patient/${TYPICAL.fhirId}`);
        await expect(page.locator('[data-card="header"] [data-item="name"]')).toHaveText('Tessa Typical');
        await expect(page.locator('[data-card="encounter-history"][data-state="ready"]')).toBeVisible();
        await expect(page.locator('[data-card="care-team"][data-state="ready"]')).toBeVisible();
        const before = (await page.locator('[data-card]').allTextContents()).join(' | ');
        expect(TYPICAL_MARKERS.filter((marker) => !before.includes(marker))).toEqual([]);

        // Slow down every read for the next patient so any stale frame would be visible.
        await page.route('**/api/fhir/**', async (route) => {
            if (route.request().url().includes(HISTORY.fhirId)) {
                await new Promise((resolve) => setTimeout(resolve, 800));
            }
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
        await expect(page.locator('[data-card="encounter-history"][data-state="ready"]')).toBeVisible();
        expect(page.url()).toContain(`/patient/${HISTORY.fhirId}`);
        const frames = await page.evaluate(() => (window as unknown as { __cardFrames: string[] }).__cardFrames);
        expect(frames.length).toBeGreaterThan(5); // the 800 ms delay spans many frames
        const stale = frames.flatMap((frame) => TYPICAL_MARKERS.filter((marker) => frame.includes(marker)));
        expect([...new Set(stale)]).toEqual([]);
    });

    // BM-004: every card checks that each resource references the header patient. Each search is
    // answered with TP-TYPICAL's real bundle rewritten to point at another patient; the card must
    // show a load error and none of that data.
    for (const [card, resource, marker] of [
        ['allergies', 'AllergyIntolerance', 'Penicillin'],
        ['medications', 'MedicationRequest', 'Metformin'],
        ['care-team', 'CareTeam', 'practitioner'],
        ['encounter-history', 'Encounter', 'Diabetes review'],
    ] as const) {
        test(`${card}: a resource for another patient is a load error, never shown (BM-004)`, async ({ page }) => {
            await logInThroughOpenEmr(page);
            // A URL predicate, not a glob: "?" is a wildcard in Playwright globs.
            const isSearch = (url: URL) =>
                url.pathname === `/api/fhir/${resource}` && url.searchParams.get('patient') === TYPICAL.fhirId;
            await page.route(isSearch, async (route) => {
                const real = await route.fetch();
                // Parse first: OpenEMR's JSON escapes slashes ("Patient\\/<id>"), so the raw text never matches.
                const body = JSON.stringify(await real.json()).replaceAll(
                    `Patient/${TYPICAL.fhirId}`,
                    `Patient/${HISTORY.fhirId}`,
                );
                await route.fulfill({ response: real, body });
            });

            await page.goto(`/patient/${TYPICAL.fhirId}`);

            const element = page.locator(`[data-card="${card}"]`);
            await expect(element).toHaveAttribute('data-state', 'error');
            await expect(element).not.toContainText(marker);
        });
    }

    // The problem card reads the standard API's problem list (BM-051); its answer names the patient.
    test('problems: a problem list for another patient is a load error, never shown (BM-004)', async ({ page }) => {
        await logInThroughOpenEmr(page);
        const isList = (url: URL) =>
            url.pathname === '/api/list-dates' &&
            url.searchParams.get('list') === 'medical_problem' &&
            url.searchParams.get('patient') === TYPICAL.fhirId;
        await page.route(isList, async (route) => {
            const real = await route.fetch();
            const body = (await real.json()) as { patient: string };
            await route.fulfill({ response: real, body: JSON.stringify({ ...body, patient: HISTORY.fhirId }) });
        });

        await page.goto(`/patient/${TYPICAL.fhirId}`);

        const element = page.locator('[data-card="problems"]');
        await expect(element).toHaveAttribute('data-state', 'error');
        await expect(element).not.toContainText('Essential hypertension');
    });
});
