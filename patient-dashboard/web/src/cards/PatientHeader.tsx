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
        <section data-card="header" data-state="ready" aria-label="Patient">
            <h2>
                <span data-item="name">{header.name}</span> <small data-item="mrn">({header.mrn})</small>
            </h2>
            <div>
                <span data-item="dobLine">{header.dobLine}</span> <span data-item="sex">Sex: {header.sex}</span>{' '}
                <span data-item="status">{header.status}</span>
            </div>
        </section>
    );
}
