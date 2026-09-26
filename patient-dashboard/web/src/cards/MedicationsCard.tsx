import type { LoadState } from '../hooks/loadState';
import type { MedicationView } from '../mappers/medications';

/** The medication list. Entries FHIR marks as orders are shown under Prescriptions instead (BM-019). */
export function MedicationsCard({ patientId, state }: { patientId: string; state: LoadState<MedicationView[]> }) {
    if (state.status !== 'ready') {
        return (
            <section data-card="medications" data-state={state.status} aria-label="Medications">
                <h3>Medications</h3>
                {state.status === 'loading' ? (
                    <p aria-busy="true">Loading medications…</p>
                ) : (
                    <p role="alert">Couldn't load medications</p>
                )}
            </section>
        );
    }
    return (
        <section data-card="medications" data-state="ready" data-patient-id={patientId} aria-label="Medications">
            <h3>Medications</h3>
            {state.data.length === 0 ? (
                <p data-empty>None recorded</p>
            ) : (
                <ul>
                    {state.data.map((medication) => (
                        <li key={medication.id} data-item="medication">
                            <span data-field="name">{medication.name}</span>
                            {medication.dosage !== '' && (
                                <>
                                    {' '}
                                    <span data-field="dosage">{medication.dosage}</span>
                                </>
                            )}
                        </li>
                    ))}
                </ul>
            )}
            <p className="small text-muted">
                OpenEMR's FHIR API cannot tell list entries from prescriptions. Entries marked as an order appear under
                Prescriptions.
            </p>
        </section>
    );
}
