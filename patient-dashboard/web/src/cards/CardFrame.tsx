/**
 * The box every clinical card is drawn in, copied from the old dashboard's shared card template
 * (templates/patient/card/card_base.html.twig) so the port keeps the old look rather than redesigning it.
 * In: the card's name for the tests (`card`), its title, its loading state, the patient id and the card's
 * contents. Out: a bordered Bootstrap card with a blue, bold title; clicking the title hides or shows the
 * contents, as the old card's title does.
 *
 * The old card also remembers whether it was collapsed in OpenEMR's user settings. This one keeps that
 * only while the page is open, because the port does not write to OpenEMR, and it always starts open.
 */
import { useId, useState } from 'react';
import type { ReactNode } from 'react';

export function CardFrame({
    card,
    title,
    state,
    patientId,
    children,
}: {
    /** The label the tests use to find the card, for example "allergies". */
    card: string;
    title: string;
    state: 'loading' | 'error' | 'ready';
    patientId: string;
    children: ReactNode;
}) {
    // Whether the card is open; it starts open, and setOpen flips it when the title is clicked.
    const [open, setOpen] = useState(true);
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
                        onClick={() => setOpen(!open)}
                    >
                        {title}
                        {/* A small arrow showing whether the card is open; screen readers skip it. */}
                        <span aria-hidden="true" className="ml-1">
                            {open ? '▾' : '▸'}
                        </span>
                    </button>
                </h3>
                {/* Bootstrap's `collapse` class hides the box unless `show` is also present. */}
                <div id={bodyId} className={open ? 'card-text collapse show' : 'card-text collapse'}>
                    <div className="clearfix pt-2">{children}</div>
                </div>
            </div>
        </section>
    );
}
