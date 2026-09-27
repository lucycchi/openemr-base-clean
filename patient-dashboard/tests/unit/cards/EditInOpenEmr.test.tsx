// @vitest-environment jsdom
import { cleanup, render, screen } from '@testing-library/react';
import type { ReactElement } from 'react';
import { afterEach, describe, expect, it } from 'vitest';
import { AllergiesCard } from '../../../web/src/cards/AllergiesCard';
import { CareTeamCard } from '../../../web/src/cards/CareTeamCard';
import { EditInOpenEmr, EDIT_IN_OPENEMR_NOTE } from '../../../web/src/cards/EditInOpenEmr';
import { MedicationsCard } from '../../../web/src/cards/MedicationsCard';
import { ProblemListCard } from '../../../web/src/cards/ProblemListCard';

afterEach(cleanup);

const PATIENT = 'a2d68325-7821-4a53-aa27-816ce437150f';

// OpenEMR's write API cannot record these cards' clinical fields, so they are edited in OpenEMR (ARC-06).
describe('EditInOpenEmr', () => {
    it("opens the patient's chart in OpenEMR in a new tab, with no link back to the dashboard", () => {
        render(<EditInOpenEmr patientId={PATIENT} />);
        const link = screen.getByRole('link', { name: 'Edit in OpenEMR' });
        expect(link.getAttribute('href')).toBe(`/openemr/patient/${PATIENT}`);
        expect(link.getAttribute('target')).toBe('_blank');
        expect(link.getAttribute('rel')).toBe('noopener noreferrer');
        expect(link.getAttribute('title')).toBe(EDIT_IN_OPENEMR_NOTE);
        expect(EDIT_IN_OPENEMR_NOTE).toBe(
            'Changes are made in OpenEMR. Opens in a new tab; you may need to sign in, then click again.',
        );
    });

    const ready = { status: 'ready' as const, data: [] };
    const cards: [string, ReactElement][] = [
        ['allergies', <AllergiesCard key="a" patientId={PATIENT} state={ready} />],
        ['problems', <ProblemListCard key="b" patientId={PATIENT} state={ready} />],
        ['medications', <MedicationsCard key="c" patientId={PATIENT} state={ready} />],
        ['care-team', <CareTeamCard key="d" patientId={PATIENT} state={ready} />],
    ];
    it.each(cards)('the %s card offers Edit in OpenEMR in its title bar, with the note visible', (card, element) => {
        const { container } = render(element);
        const frame = container.querySelector(`[data-card="${card}"]`);
        expect(frame?.querySelector('h3 a')?.textContent).toBe('Edit in OpenEMR');
        expect(frame?.textContent).toContain(EDIT_IN_OPENEMR_NOTE);
    });
});
