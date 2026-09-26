import type { Browser, Page } from '@playwright/test';

/** The old PHP dashboard on the development-easy stack, over plain HTTP so no certificate is involved. */
export const OLD_DASHBOARD_URL = process.env.OLD_DASHBOARD_URL ?? 'http://localhost:8300';

const OEMR_USER = process.env.OEMR_USER ?? 'admin';
const OEMR_PASS = process.env.OEMR_PASS ?? 'pass';

export interface OldCard {
    /** Text of each list row (or table row), whitespace collapsed. Read even when the card is collapsed. */
    items: string[];
    /** Tooltip text (title / data-original-title), whitespace collapsed. */
    tooltips: string[];
    /** Text of elements carrying the old highlight classes (for example bg-warning). */
    highlighted: string[];
    collapsed: boolean;
    /** The card body's full text, whitespace collapsed. */
    bodyText: string;
}

/**
 * Logs in to the old dashboard in its own browser context (its session cookie must not mix with the
 * new app's) and opens the dashboard for one patient. Clinical-reminder alerts are accepted.
 */
/** Logs in to the old OpenEMR UI in its own browser context and returns the page on the tab frame (main.php). */
export async function openOldSession(browser: Browser): Promise<Page> {
    const context = await browser.newContext({ baseURL: OLD_DASHBOARD_URL });
    const page = await context.newPage();
    page.on('dialog', (dialog) => void dialog.accept());

    await page.goto('/interface/login/login.php?site=default');
    await page.locator('#authUser').fill(OEMR_USER);
    await page.locator('#clearPass').fill(OEMR_PASS);
    await page.locator('#login-button, button[type=submit]').first().click();
    await page.waitForURL(/interface\/main\/tabs\/main\.php/);
    return page;
}

/**
 * Logs in to the old dashboard in its own browser context (its session cookie must not mix with the
 * new app's) and opens the dashboard for one patient. Clinical-reminder alerts are accepted.
 */
export async function openOldDashboard(browser: Browser, pid: number): Promise<Page> {
    const page = await openOldSession(browser);
    await page.goto(`/interface/patient_file/summary/demographics.php?set_pid=${pid}`);
    await page.waitForLoadState('networkidle');
    return page;
}

/** Reads one old dashboard card by the id of its collapsible body (for example allergy_ps_expand). */
export async function readOldCard(page: Page, cardId: string): Promise<OldCard> {
    return page.evaluate((id) => {
        const clean = (text: string | null | undefined) => (text ?? '').replace(/\s+/g, ' ').trim();
        const body = document.getElementById(id);
        if (body === null) {
            throw new Error(`Old dashboard card #${id} not found`);
        }
        const card = body.closest('.card') ?? body;
        const listItems = Array.from(body.querySelectorAll('.list-group-item'));
        const rows = listItems.length > 0 ? listItems : Array.from(body.querySelectorAll('tbody tr'));
        return {
            items: rows.map((row) => clean(row.textContent)).filter((text) => text !== ''),
            tooltips: Array.from(card.querySelectorAll('[title],[data-original-title]'))
                .map((node) => clean(node.getAttribute('data-original-title') ?? node.getAttribute('title')))
                .filter((text) => text !== ''),
            highlighted: Array.from(card.querySelectorAll('.bg-warning,.text-danger,.badge'))
                .map((node) => clean(node.textContent))
                .filter((text) => text !== ''),
            collapsed: body.classList.contains('collapse') && !body.classList.contains('show'),
            bodyText: clean(body.textContent),
        };
    }, cardId);
}

export interface OldIdentityBar {
    name: string;
    mrn: string;
    dobLine: string;
}

/**
 * Reads the identity bar in the tab frame (patient_data_template.php) for one patient, using a page
 * from openOldSession(). The bar is filled by demographics.php's setPatient() call, so the dashboard is
 * opened inside the frame through left_nav.loadFrame (the page must stay on main.php as login left it,
 * because reloading main.php without its token_main loses left_nav).
 */
export async function readOldIdentityBar(oldSession: Page, pid: number): Promise<OldIdentityBar> {
    await oldSession.evaluate(
        (patientPid) =>
            (
                window as unknown as { left_nav: { loadFrame: (a: string, b: string, c: string) => void } }
            ).left_nav.loadFrame('dem1', 'pat', `patient_file/summary/demographics.php?set_pid=${patientPid}`),
        pid,
    );
    const bar = oldSession.locator('#attendantData');
    await bar.getByText(new RegExp(`\\(${pid}\\)`)).waitFor();
    await bar.getByText(/DOB:/).waitFor();
    const text = (await bar.innerText()).replace(/[ \t]+/g, ' ');

    const match = /^\s*(.+?)\s*\((\S*)\)\s*\n\s*(DOB:[^\n]*?)\s*(?:\n|$)/.exec(text);
    if (match === null) {
        throw new Error(`Could not parse the old identity bar: ${JSON.stringify(text)}`);
    }
    return { name: match[1] ?? '', mrn: match[2] ?? '', dobLine: (match[3] ?? '').trim() };
}
