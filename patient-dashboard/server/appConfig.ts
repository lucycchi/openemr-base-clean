import { existsSync, readFileSync } from 'node:fs';
import { isCardKey } from '../web/src/app/hiddenCards';
import type { CardKey } from '../web/src/app/hiddenCards';
import type { AgeSettings } from '../web/src/mappers/age';

export interface AppConfig {
    hiddenCards: CardKey[];
    /** Mirrors OpenEMR's age_display_format and age_display_limit globals (defaults: 0 and 3). */
    ageDisplay: AgeSettings;
}

function ageSettingsFrom(env: NodeJS.ProcessEnv): AgeSettings {
    const format = env.AGE_DISPLAY_FORMAT ?? '0';
    if (format !== '0' && format !== '1') {
        throw new Error(`AGE_DISPLAY_FORMAT must be 0 or 1, got "${format}"`);
    }
    const limitYears = Number(env.AGE_DISPLAY_LIMIT ?? 3);
    if (!Number.isFinite(limitYears) || limitYears < 0) {
        throw new Error('AGE_DISPLAY_LIMIT must be a non-negative number');
    }
    return { format: format === '1' ? 1 : 0, limitYears };
}

/**
 * Reads the site's hidden-cards list (the new app's equivalent of `hide_dashboard_cards`, which
 * FHIR does not expose). A missing file hides nothing; an unknown key stops start-up.
 */
export function loadAppConfig(path: string, env: NodeJS.ProcessEnv = process.env): AppConfig {
    const ageDisplay = ageSettingsFrom(env);
    if (!existsSync(path)) {
        return { hiddenCards: [], ageDisplay };
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
    return { hiddenCards, ageDisplay };
}
