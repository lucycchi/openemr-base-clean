/**
 * The patient identity bar at the top of the dashboard. In: the header text prepared by
 * mappers/header.ts (or a note that it is still loading or failed). Out: the patient's name and record
 * number, then a line with date of birth and age, sex, and "Active" or "Deceased (date)".
 * The markup looks like HTML; see AllergiesCard.tsx for a short reading guide.
 */
import type { HeaderView } from '../mappers/header';
import type { LoadState } from '../hooks/loadState';

/**
 * The header's box. `sticky-top` pins it to the top of the window while the cards scroll beneath it, as
 * the old identity bar stays in view above the scrolling dashboard; the white background and bottom
 * border stop the cards showing through it.
 */
const HEADER_CLASS = 'sticky-top bg-white border-bottom px-2 py-1 mb-2';

/** The persistent identity bar. Fields carry data-item so the parity tests can read them. */
export function PatientHeader({ state }: { state: LoadState<HeaderView> }) {
    if (state.status === 'loading') {
        return (
            <section className={HEADER_CLASS} data-card="header" data-state="loading" aria-busy="true">
                Loading patient…
            </section>
        );
    }
    if (state.status === 'error') {
        return (
            <section className={HEADER_CLASS} data-card="header" data-state="error" role="alert">
                Couldn't load the patient
            </section>
        );
    }
    const header = state.data;
    return (
        <section
            className={HEADER_CLASS}
            data-card="header"
            data-state="ready"
            data-patient-id={header.id}
            aria-label="Patient"
        >
            {/* Name with the record number in brackets, e.g. "Jane Doe (36)". */}
            <h2 className="h3 mb-0">
                <span data-item="name" className="text-primary">
                    {header.name}
                </span>{' '}
                <small data-item="mrn" className="text-muted">
                    ({header.mrn})
                </small>
            </h2>
            {/* "DOB: … Age: …", then "Sex: …", then the status; `{' '}` puts a space between them. */}
            <div>
                <span data-item="dobLine" className="mr-3">
                    {header.dobLine}
                </span>{' '}
                <span data-item="sex" className="mr-3">
                    Sex: {header.sex}
                </span>{' '}
                <span data-item="status">{header.status}</span>
            </div>
        </section>
    );
}
