import type { Page } from '@playwright/test';

export interface NewRow {
    item: string;
    text: string;
    title: string | null;
    highlight: boolean;
    /** data-field name → text inside the row. */
    fields: Record<string, string>;
}

export interface NewCard {
    state: string | null;
    patientId: string | null;
    /** data-item name → text, in document order. */
    items: [string, string][];
    /** The same rows with their tooltip and highlight. */
    rows: NewRow[];
    /** Text of the empty-state element, if shown. */
    empty: string | null;
}

/** Reads a card of the new app by its data-card name (for example "header"). */
export async function readNewCard(page: Page, card: string): Promise<NewCard> {
    const root = page.locator(`[data-card="${card}"]`);
    await root.waitFor();
    await page.locator(`[data-card="${card}"]:not([data-state="loading"])`).waitFor();
    return root.evaluate((element) => {
        const clean = (text: string | null) => (text ?? '').replace(/\s+/g, ' ').trim();
        const rows = Array.from(element.querySelectorAll('[data-item]')).map((node) => ({
            item: node.getAttribute('data-item') ?? '',
            text: clean(node.textContent),
            title: node.getAttribute('title'),
            highlight: node.hasAttribute('data-highlight'),
            fields: Object.fromEntries(
                Array.from(node.querySelectorAll('[data-field]')).map((field) => [
                    field.getAttribute('data-field') ?? '',
                    clean(field.textContent),
                ]),
            ),
        }));
        const empty = element.querySelector('[data-empty]');
        return {
            state: element.getAttribute('data-state'),
            patientId: element.getAttribute('data-patient-id'),
            items: rows.map((row) => [row.item, row.text] as [string, string]),
            rows,
            empty: empty === null ? null : clean(empty.textContent),
        };
    });
}
