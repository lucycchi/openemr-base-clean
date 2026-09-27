import { existsSync, readFileSync } from 'node:fs';
import { isCardKey } from '../web/src/app/hiddenCards';
import { idleTimeoutSecondsFrom } from './config';
import type { CardKey } from '../web/src/app/hiddenCards';
import type { AgeSettings } from '../web/src/mappers/age';
import type { DateDisplayFormat } from '../web/src/mappers/dates';

export interface AppConfig {
    hiddenCards: CardKey[];
    /** Mirrors OpenEMR's age_display_format and age_display_limit globals (defaults: 0 and 3). */
    ageDisplay: AgeSettings;
    /** Mirrors OpenEMR's encounter_page_size global: visits shown before "Show all" (default 20; 0 shows all). */
    encounterPageSize: number;
    /** Mirrors OpenEMR's date_display_format global: 0 = Y-m-d (default), 1 = m/d/Y, 2 = d/m/Y. */
    dateDisplayFormat: DateDisplayFormat;
    /** Mirrors OpenEMR's timeout global: the page signs out after this many idle seconds. */
    idleTimeoutSeconds: number;
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

function encounterPageSizeFrom(env: NodeJS.ProcessEnv): number {
    const raw = env.ENCOUNTER_PAGE_SIZE ?? '20';
    if (!/^\d+$/.test(raw)) {
        throw new Error(`ENCOUNTER_PAGE_SIZE must be a whole number of 0 or more, got "${raw}"`);
    }
    return Number(raw);
}

function dateDisplayFormatFrom(env: NodeJS.ProcessEnv): DateDisplayFormat {
    const raw = env.DATE_DISPLAY_FORMAT ?? '0';
    if (raw !== '0' && raw !== '1' && raw !== '2') {
        throw new Error(`DATE_DISPLAY_FORMAT must be 0, 1 or 2, got "${raw}"`);
    }
    return raw === '1' ? 1 : raw === '2' ? 2 : 0;
}

function prescriptionsDisabled(env: NodeJS.ProcessEnv): boolean {
    const raw = env.DISABLE_PRESCRIPTIONS ?? '0';
    if (raw !== '0' && raw !== '1') {
        throw new Error(`DISABLE_PRESCRIPTIONS must be 0 or 1, got "${raw}"`);
    }
    return raw === '1';
}

function hiddenCardsFrom(path: string): CardKey[] {
    if (!existsSync(path)) {
        return [];
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
    return hiddenCards;
}

/**
 * Reads the site's hidden-cards list (the new app's equivalent of `hide_dashboard_cards`, which
 * FHIR does not expose) and the mirrored globals. A missing file hides nothing; an unknown key stops
 * start-up. DISABLE_PRESCRIPTIONS=1 hides the Prescriptions card, as disable_prescriptions does
 * (demographics.php:1098).
 */
export function loadAppConfig(path: string, env: NodeJS.ProcessEnv = process.env): AppConfig {
    const ageDisplay = ageSettingsFrom(env);
    const encounterPageSize = encounterPageSizeFrom(env);
    const dateDisplayFormat = dateDisplayFormatFrom(env);
    const hiddenCards = hiddenCardsFrom(path);
    if (prescriptionsDisabled(env) && !hiddenCards.includes('card_prescriptions')) {
        hiddenCards.push('card_prescriptions');
    }
    return {
        hiddenCards,
        ageDisplay,
        encounterPageSize,
        dateDisplayFormat,
        idleTimeoutSeconds: idleTimeoutSecondsFrom(env),
    };
}
