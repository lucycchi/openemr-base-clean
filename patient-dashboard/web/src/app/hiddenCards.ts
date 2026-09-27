/**
 * The list of cards the dashboard can show, in the order they appear, and the helpers that remove the
 * ones a site has chosen to hide. The site's choice arrives in the site settings (siteConfig.ts), and
 * App.tsx uses visibleCards to decide which cards to draw.
 */

/**
 * Cards a site can hide. The first five reuse the old dashboard's `hide_dashboard_cards` keys
 * (demographics.php:1095-1098, 1252), so a site can copy its old setting across.
 */
export const CARD_KEYS = [
    'card_allergies',
    'card_medicalproblems',
    'card_medication',
    'card_prescriptions',
    'card_care_team',
    'card_encounter_history',
] as const;

/** Any one of the card names above, and nothing else (a misspelt name is rejected before the page runs). */
export type CardKey = (typeof CARD_KEYS)[number];

/** True when a piece of text is one of the known card names. */
export function isCardKey(value: string): value is CardKey {
    return (CARD_KEYS as readonly string[]).includes(value);
}

/** The cards to render, in dashboard order, after removing the hidden ones. */
export function visibleCards(hidden: readonly string[]): CardKey[] {
    // Keep each card (`.filter`) whose name is not on the hidden list.
    return CARD_KEYS.filter((key) => !hidden.includes(key));
}
