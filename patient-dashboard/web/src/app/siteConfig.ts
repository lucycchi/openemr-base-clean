import type { AgeSettings } from '../mappers/age';
import { isCardKey } from './hiddenCards';
import type { CardKey } from './hiddenCards';

/** The site settings the BFF serves at /app-config (server/appConfig.ts). */
export interface SiteConfig {
    hiddenCards: CardKey[];
    ageDisplay: AgeSettings;
    encounterPageSize: number;
}

function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null;
}

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
    const hiddenCards: CardKey[] = [];
    for (const key of value.hiddenCards) {
        if (typeof key !== 'string' || !isCardKey(key)) {
            return undefined;
        }
        hiddenCards.push(key);
    }
    const ageDisplay = parseAgeSettings(value.ageDisplay);
    const { encounterPageSize } = value;
    if (ageDisplay === undefined || typeof encounterPageSize !== 'number' || !Number.isInteger(encounterPageSize)) {
        return undefined;
    }
    if (encounterPageSize < 0) {
        return undefined;
    }
    return { hiddenCards, ageDisplay, encounterPageSize };
}
