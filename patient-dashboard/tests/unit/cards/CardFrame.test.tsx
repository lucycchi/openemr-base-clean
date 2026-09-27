// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import type { ReactElement } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { AllergiesCard } from '../../../web/src/cards/AllergiesCard';
import { CardFrame } from '../../../web/src/cards/CardFrame';
import { CardLayoutContext } from '../../../web/src/hooks/useCardLayout';
import { CareTeamCard } from '../../../web/src/cards/CareTeamCard';
import { EncounterHistoryCard } from '../../../web/src/cards/EncounterHistoryCard';
import { MedicationsCard } from '../../../web/src/cards/MedicationsCard';
import { PrescriptionsCard } from '../../../web/src/cards/PrescriptionsCard';
import { ProblemListCard } from '../../../web/src/cards/ProblemListCard';

afterEach(cleanup);

// The old dashboard draws every card from templates/patient/card/card_base.html.twig: a Bootstrap
// card whose title toggles the body open and closed. The frame reproduces that markup.
describe('CardFrame', () => {
    it('draws a Bootstrap card titled like the old card, open at first', () => {
        const { container } = render(
            <CardFrame card="allergies" title="Allergies" state="ready" patientId="p1">
                <p>body</p>
            </CardFrame>,
        );

        const card = container.querySelector('section[data-card="allergies"]');
        expect(card?.classList.contains('card')).toBe(true);
        expect(card?.getAttribute('data-state')).toBe('ready');
        expect(card?.getAttribute('data-patient-id')).toBe('p1');
        expect(screen.getByRole('heading', { name: 'Allergies' })).toBeTruthy();
        const toggle = screen.getByRole('button', { name: 'Allergies' });
        expect(toggle.getAttribute('aria-expanded')).toBe('true');
        const body = document.getElementById(toggle.getAttribute('aria-controls') ?? 'missing');
        expect(body?.classList.contains('collapse')).toBe(true);
        expect(body?.classList.contains('show')).toBe(true);
        expect(body?.textContent).toBe('body');
    });

    it('collapses and reopens the body when the title is clicked, as the old toggle does', () => {
        render(
            <CardFrame card="allergies" title="Allergies" state="ready" patientId="p1">
                <p>body</p>
            </CardFrame>,
        );
        const toggle = screen.getByRole('button', { name: 'Allergies' });
        const body = () => document.getElementById(toggle.getAttribute('aria-controls') ?? 'missing');

        fireEvent.click(toggle);
        expect(toggle.getAttribute('aria-expanded')).toBe('false');
        expect(body()?.classList.contains('show')).toBe(false);

        fireEvent.click(toggle);
        expect(toggle.getAttribute('aria-expanded')).toBe('true');
        expect(body()?.classList.contains('show')).toBe(true);
    });

    it('leaves out the patient id until the card is ready', () => {
        const { container } = render(
            <CardFrame card="allergies" title="Allergies" state="loading" patientId="p1">
                <p>body</p>
            </CardFrame>,
        );
        expect(container.querySelector('[data-card="allergies"]')?.hasAttribute('data-patient-id')).toBe(false);
    });

    it("starts collapsed when the user's saved layout has the card collapsed", () => {
        render(
            <CardLayoutContext value={{ isOpen: (card) => card !== 'allergies', setOpen: vi.fn() }}>
                <CardFrame card="allergies" title="Allergies" state="ready" patientId="p1">
                    <p>body</p>
                </CardFrame>
            </CardLayoutContext>,
        );
        const toggle = screen.getByRole('button', { name: 'Allergies' });
        expect(toggle.getAttribute('aria-expanded')).toBe('false');
        expect(
            document.getElementById(toggle.getAttribute('aria-controls') ?? 'missing')?.classList.contains('show'),
        ).toBe(false);
    });

    it("saves the user's choice through the layout when the title is clicked", () => {
        const setOpen = vi.fn();
        render(
            <CardLayoutContext value={{ isOpen: () => true, setOpen }}>
                <CardFrame card="medications" title="Medications" state="ready" patientId="p1">
                    <p>body</p>
                </CardFrame>
            </CardLayoutContext>,
        );
        fireEvent.click(screen.getByRole('button', { name: 'Medications' }));
        expect(setOpen).toHaveBeenCalledWith('medications', false);
    });

    const loading = { status: 'loading' } as const;
    const cards: [string, string, ReactElement][] = [
        ['allergies', 'Allergies', <AllergiesCard key="a" patientId="p1" state={loading} />],
        ['problems', 'Medical Problems', <ProblemListCard key="b" patientId="p1" state={loading} />],
        ['medications', 'Medications', <MedicationsCard key="c" patientId="p1" state={loading} />],
        ['prescriptions', 'Prescriptions', <PrescriptionsCard key="d" patientId="p1" state={loading} />],
        ['care-team', 'Care Team', <CareTeamCard key="e" patientId="p1" state={loading} />],
        [
            'encounter-history',
            'Encounter history',
            <EncounterHistoryCard key="f" patientId="p1" state={loading} pageSize={20} />,
        ],
    ];
    it.each(cards)('the %s card is drawn in the frame', (card, title, element) => {
        const { container } = render(element);
        expect(container.querySelector(`section.card[data-card="${card}"]`)).not.toBeNull();
        expect(screen.getByRole('button', { name: title }).getAttribute('aria-expanded')).toBe('true');
    });
});
