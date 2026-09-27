/**
 * The site's display settings that the page needs, read once when the server starts.
 *
 * Runs on the server. In: environment variables (named values set by Docker or the .env file) that copy
 * OpenEMR's own settings ("globals"), such as the date format and the idle timeout, plus an optional file,
 * config/hidden-cards.json, listing dashboard cards the site has switched off. Out: one AppConfig object,
 * which app.ts sends to the browser at /app-config so the page can lay itself out the same way the old
 * OpenEMR dashboard would. A bad value stops start-up with a message naming the setting.
 */
import { existsSync, readFileSync } from 'node:fs';
import { isCardKey } from '../web/src/app/hiddenCards';
import { idleTimeoutSecondsFrom } from './config';
import type { CardKey } from '../web/src/app/hiddenCards';
import type { AgeSettings } from '../web/src/mappers/age';
import type { DateDisplayFormat } from '../web/src/mappers/dates';

/** The settings sent to the page. */
export interface AppConfig {
    /** Cards the site does not want shown, by their key (for example card_prescriptions). */
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

/**
 * How ages are written (AGE_DISPLAY_FORMAT: 0 = years, 1 = years, months and days) and up to what age,
 * in years, format 1 applies (AGE_DISPLAY_LIMIT).
 */
function ageSettingsFrom(env: NodeJS.ProcessEnv): AgeSettings {
    const format = env.AGE_DISPLAY_FORMAT ?? '0';
    if (format !== '0' && format !== '1') {
        throw new Error(`AGE_DISPLAY_FORMAT must be 0 or 1, got "${format}"`);
    }
    const limitYears = Number(env.AGE_DISPLAY_LIMIT ?? 3);
    if (!Number.isFinite(limitYears) || limitYears < 0) {
        throw new Error('AGE_DISPLAY_LIMIT must be a non-negative number');
    }
    // `a ? b : c` means "if a, then b, otherwise c".
    return { format: format === '1' ? 1 : 0, limitYears };
}

/** How many visits the Encounters card lists before "Show all" (ENCOUNTER_PAGE_SIZE). */
function encounterPageSizeFrom(env: NodeJS.ProcessEnv): number {
    const raw = env.ENCOUNTER_PAGE_SIZE ?? '20';
    // The pattern accepts digits only: a whole number, 0 or more.
    if (!/^\d+$/.test(raw)) {
        throw new Error(`ENCOUNTER_PAGE_SIZE must be a whole number of 0 or more, got "${raw}"`);
    }
    return Number(raw);
}

/** Which order day, month and year are written in (DATE_DISPLAY_FORMAT, 0, 1 or 2). */
function dateDisplayFormatFrom(env: NodeJS.ProcessEnv): DateDisplayFormat {
    const raw = env.DATE_DISPLAY_FORMAT ?? '0';
    if (raw !== '0' && raw !== '1' && raw !== '2') {
        throw new Error(`DATE_DISPLAY_FORMAT must be 0, 1 or 2, got "${raw}"`);
    }
    return raw === '1' ? 1 : raw === '2' ? 2 : 0;
}

/** True when the site has switched prescriptions off (DISABLE_PRESCRIPTIONS=1). */
function prescriptionsDisabled(env: NodeJS.ProcessEnv): boolean {
    const raw = env.DISABLE_PRESCRIPTIONS ?? '0';
    if (raw !== '0' && raw !== '1') {
        throw new Error(`DISABLE_PRESCRIPTIONS must be 0 or 1, got "${raw}"`);
    }
    return raw === '1';
}

/**
 * Reads the hidden-cards file, which looks like {"hiddenCards": ["card_prescriptions", ...]}. No file means
 * nothing is hidden; a name that is not a real card stops start-up, so a typo cannot silently show a card.
 */
function hiddenCardsFrom(path: string): CardKey[] {
    if (!existsSync(path)) {
        return [];
    }
    // `as unknown`: treat the file's contents as "not yet checked" until the lines below have checked them.
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
