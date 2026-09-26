import type { Page } from '@playwright/test';

export interface NewCard {
    state: string | null;
    /** data-item name → text, in document order. */
    items: [string, string][];
}

/** Reads a card of the new app by its data-card name (for example "header"). */
export async function readNewCard(page: Page, card: string): Promise<NewCard> {
    const root = page.locator(`[data-card="${card}"]`);
    await root.waitFor();
    await page.locator(`[data-card="${card}"]:not([data-state="loading"])`).waitFor();
    return root.evaluate((element) => ({
        state: element.getAttribute('data-state'),
        items: Array.from(element.querySelectorAll('[data-item]')).map(
            (node) =>
                [node.getAttribute('data-item') ?? '', (node.textContent ?? '').replace(/\s+/g, ' ').trim()] as [
                    string,
                    string,
                ],
        ),
    }));
}
