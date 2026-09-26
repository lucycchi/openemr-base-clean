import type { LoadState } from '../hooks/loadState';
import type { AllergyView } from '../mappers/allergies';

/** The allergies card. Empty means "none recorded", never "No Known Allergies" (BM-012). */
export function AllergiesCard({ patientId, state }: { patientId: string; state: LoadState<AllergyView[]> }) {
    if (state.status !== 'ready') {
        return (
            <section data-card="allergies" data-state={state.status} aria-label="Allergies">
                <h3>Allergies</h3>
                {state.status === 'loading' ? (
                    <p aria-busy="true">Loading allergies…</p>
                ) : (
                    <p role="alert">Couldn't load allergies</p>
                )}
            </section>
        );
    }
    return (
        <section data-card="allergies" data-state="ready" data-patient-id={patientId} aria-label="Allergies">
            <h3>Allergies</h3>
            {state.data.length === 0 ? (
                <p data-empty>No allergies recorded</p>
            ) : (
                <ul>
                    {state.data.map((allergy) => (
                        <li
                            key={allergy.id}
                            data-item="allergy"
                            data-highlight={allergy.high ? '' : undefined}
                            className={allergy.high ? 'bg-warning font-weight-bold' : undefined}
                            title={`${allergy.name} Reaction: ${allergy.reaction} - ${allergy.risk}`
                                .replace(/\s+/g, ' ')
                                .trim()}
                        >
                            {allergy.risk === '' ? allergy.name : `${allergy.name} (${allergy.risk})`}
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
