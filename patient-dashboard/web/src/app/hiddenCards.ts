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

export type CardKey = (typeof CARD_KEYS)[number];

export function isCardKey(value: string): value is CardKey {
    return (CARD_KEYS as readonly string[]).includes(value);
}

/** The cards to render, in dashboard order, after removing the hidden ones. */
export function visibleCards(hidden: readonly string[]): CardKey[] {
    return CARD_KEYS.filter((key) => !hidden.includes(key));
}
