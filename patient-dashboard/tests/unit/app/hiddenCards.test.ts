import { describe, expect, it } from 'vitest';
import { CARD_KEYS, visibleCards } from '../../../web/src/app/hiddenCards';

describe('hidden cards', () => {
    it('a key in hidden-cards.json removes that card', () => {
        const visible = visibleCards(['card_allergies', 'card_care_team']);

        expect(visible).not.toContain('card_allergies');
        expect(visible).not.toContain('card_care_team');
        expect(visible).toContain('card_medicalproblems');
        expect(visible).toHaveLength(CARD_KEYS.length - 2);
    });

    it('shows every card when nothing is hidden', () => {
        expect(visibleCards([])).toEqual([...CARD_KEYS]);
    });

    it('uses the old hide_dashboard_cards keys for the cards the old dashboard had', () => {
        expect(CARD_KEYS).toEqual(
            expect.arrayContaining([
                'card_allergies',
                'card_medicalproblems',
                'card_medication',
                'card_prescriptions',
                'card_care_team',
            ]),
        );
    });
});
