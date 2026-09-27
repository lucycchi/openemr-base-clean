/**
 * The box every clinical card is drawn in, copied from the old dashboard's shared card template
 * (templates/patient/card/card_base.html.twig) so the port keeps the old look rather than redesigning it.
 * In: the card's name for the tests (`card`), its title, its loading state, the patient id and the card's
 * contents. Out: a bordered Bootstrap card with a blue, bold title; clicking the title hides or shows the
 * contents, as the old card's title does.
 *
 * The old card remembers, per user, whether it was collapsed. This one does the same through the user's
 * saved layout (hooks/useCardLayout.ts), which the dashboard server keeps because the API cannot reach
 * OpenEMR's own user settings. Without a layout (for example in a unit test) it starts open and keeps
 * the choice only while the page is open.
 */
import { useContext, useId, useState } from 'react';
import type { ReactNode } from 'react';
import { CardLayoutContext } from '../hooks/useCardLayout';

export function CardFrame({
    card,
    title,
    state,
    patientId,
    children,
    actions,
}: {
    /** The label the tests use to find the card, for example "allergies". */
    card: string;
    title: string;
    state: 'loading' | 'error' | 'ready';
    patientId: string;
    children: ReactNode;
    /** Buttons or links at the right of the title bar, where the old card has its pencil (ARC-06). */
    actions?: ReactNode;
}) {
    // The user's saved layout, when App provides one; otherwise this card's own open state.
    const layout = useContext(CardLayoutContext);
    const [localOpen, setLocalOpen] = useState(true);
    const open = layout === undefined ? localOpen : layout.isOpen(card);
    const toggle = () => {
        if (layout === undefined) {
            setLocalOpen(!open);
        } else {
            layout.setOpen(card, !open);
        }
    };
    // A page-unique id so the title can say which box it opens and closes (for screen readers).
    const bodyId = useId();
    return (
        <section
            className="card flex-fill"
            data-card={card}
            data-state={state}
            // The patient id is only stamped on a ready card: the tests use it to prove whose data is shown.
            data-patient-id={state === 'ready' ? patientId : undefined}
            aria-label={title}
        >
            <div className="card-body p-1">
                <h3 className="card-title h6 mb-0 d-flex p-1 justify-content-between">
                    <button
                        type="button"
                        className="btn btn-link p-0 text-left font-weight-bolder"
                        aria-expanded={open}
                        aria-controls={bodyId}
                        onClick={toggle}
                    >
                        {title}
                        {/* A small arrow showing whether the card is open; screen readers skip it. */}
                        <span aria-hidden="true" className="ml-1">
                            {open ? '▾' : '▸'}
                        </span>
                    </button>
                    {actions}
                </h3>
                {/* Bootstrap's `collapse` class hides the box unless `show` is also present. */}
                <div id={bodyId} className={open ? 'card-text collapse show' : 'card-text collapse'}>
                    <div className="clearfix pt-2">{children}</div>
                </div>
            </div>
        </section>
    );
}
