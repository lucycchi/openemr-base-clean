/**
 * The patient identity bar at the top of the dashboard. In: the header text prepared by
 * mappers/header.ts (or a note that it is still loading or failed). Out: the patient's name and record
 * number, then a line with date of birth and age, sex, and "Active" or "Deceased (date)".
 * The markup looks like HTML; see AllergiesCard.tsx for a short reading guide.
 */
import type { HeaderView } from '../mappers/header';
import type { LoadState } from '../hooks/loadState';

/** The persistent identity bar. Fields carry data-item so the parity tests can read them. */
export function PatientHeader({ state }: { state: LoadState<HeaderView> }) {
    if (state.status === 'loading') {
        return (
            <section data-card="header" data-state="loading" aria-busy="true">
                Loading patient…
            </section>
        );
    }
    if (state.status === 'error') {
        return (
            <section data-card="header" data-state="error" role="alert">
                Couldn't load the patient
            </section>
        );
    }
    const header = state.data;
    return (
        <section data-card="header" data-state="ready" data-patient-id={header.id} aria-label="Patient">
            {/* Name with the record number in brackets, e.g. "Jane Doe (36)". */}
            <h2>
                <span data-item="name">{header.name}</span> <small data-item="mrn">({header.mrn})</small>
            </h2>
            {/* "DOB: … Age: …", then "Sex: …", then the status; `{' '}` puts a space between them. */}
            <div>
                <span data-item="dobLine">{header.dobLine}</span> <span data-item="sex">Sex: {header.sex}</span>{' '}
                <span data-item="status">{header.status}</span>
            </div>
        </section>
    );
}
