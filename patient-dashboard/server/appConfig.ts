import { existsSync, readFileSync } from 'node:fs';
import { isCardKey } from '../web/src/app/hiddenCards';
import type { CardKey } from '../web/src/app/hiddenCards';

export interface AppConfig {
    hiddenCards: CardKey[];
}

/**
 * Reads the site's hidden-cards list (the new app's equivalent of `hide_dashboard_cards`, which
 * FHIR does not expose). A missing file hides nothing; an unknown key stops start-up.
 */
export function loadAppConfig(path: string): AppConfig {
    if (!existsSync(path)) {
        return { hiddenCards: [] };
    }
    const parsed = JSON.parse(readFileSync(path, 'utf8')) as unknown;
    const list = (parsed as { hiddenCards?: unknown }).hiddenCards;
    if (!Array.isArray(list)) {
        throw new Error(`${path} must contain {"hiddenCards": [...]}`);
    }
    const hiddenCards: CardKey[] = [];
    for (const key of list) {
        if (typeof key !== 'string' || !isCardKey(key)) {
            throw new Error(`Unknown card key in ${path}: ${String(key)}`);
        }
        hiddenCards.push(key);
    }
    return { hiddenCards };
}
