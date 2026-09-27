/**
 * The site's settings for the dashboard (hidden cards, how ages and dates are written, encounters per
 * page, idle timeout), and the check that reads them. App.tsx fetches /app-config from the BFF once the
 * user is signed in and passes the answer to parseSiteConfig. In: the raw answer. Out: a SiteConfig, or
 * undefined if any setting is missing or malformed, in which case the page shows an error rather than
 * guessing a setting (for example, showing a card the site has hidden).
 */
import type { AgeSettings } from '../mappers/age';
import type { DateDisplayFormat } from '../mappers/dates';
import { isCardKey } from './hiddenCards';
import type { CardKey } from './hiddenCards';

/** The site settings the BFF serves at /app-config (server/appConfig.ts). */
export interface SiteConfig {
    hiddenCards: CardKey[];
    ageDisplay: AgeSettings;
    encounterPageSize: number;
    dateDisplayFormat: DateDisplayFormat;
    idleTimeoutSeconds: number;
}

/** True when a value is an object with named fields (not text, a number, or nothing). */
function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null;
}

/** Reads the age display settings: a format of 0 or 1 and a non-negative year limit, or undefined. */
function parseAgeSettings(value: unknown): AgeSettings | undefined {
    if (!isRecord(value)) {
        return undefined;
    }
    const { format, limitYears } = value;
    if ((format !== 0 && format !== 1) || typeof limitYears !== 'number' || !(limitYears >= 0)) {
        return undefined;
    }
    return { format, limitYears };
}

/**
 * Parses /app-config. Anything malformed is undefined, and the app then shows an error instead of
 * guessing: old demographics.php always applies the site's hidden cards.
 */
export function parseSiteConfig(value: unknown): SiteConfig | undefined {
    if (!isRecord(value) || !Array.isArray(value.hiddenCards)) {
        return undefined;
    }
    // Every hidden card must be a known card name; one unknown name rejects the whole settings answer.
    const hiddenCards: CardKey[] = [];
    for (const key of value.hiddenCards) {
        if (typeof key !== 'string' || !isCardKey(key)) {
            return undefined;
        }
        hiddenCards.push(key);
    }
    // Age display settings must be valid, and encounters per page must be a whole number, zero or more.
    const ageDisplay = parseAgeSettings(value.ageDisplay);
    const { encounterPageSize } = value;
    if (ageDisplay === undefined || typeof encounterPageSize !== 'number' || !Number.isInteger(encounterPageSize)) {
        return undefined;
    }
    if (encounterPageSize < 0) {
        return undefined;
    }
    // Date format: one of OpenEMR's three choices, numbered 0, 1 and 2.
    const { dateDisplayFormat } = value;
    if (dateDisplayFormat !== 0 && dateDisplayFormat !== 1 && dateDisplayFormat !== 2) {
        return undefined;
    }
    // Idle timeout: a whole number of seconds, above zero.
    const { idleTimeoutSeconds } = value;
    if (typeof idleTimeoutSeconds !== 'number' || !Number.isInteger(idleTimeoutSeconds) || idleTimeoutSeconds <= 0) {
        return undefined;
    }
    return { hiddenCards, ageDisplay, encounterPageSize, dateDisplayFormat, idleTimeoutSeconds };
}
