import type { LoadState } from '../hooks/loadState';
import type { MedicationView } from '../mappers/medications';

/**
 * Active prescriptions, newest first. "Added" is the date the prescription was entered; the old card
 * labelled it "Filled" (BM-023). Details is dosageInstruction text, blank when FHIR has none (BM-038).
 * Refills say "Not available": OpenEMR's FHIR query never reads the refills column and always sends 0 (BM-041).
 */
export function PrescriptionsCard({ patientId, state }: { patientId: string; state: LoadState<MedicationView[]> }) {
    if (state.status !== 'ready') {
        return (
            <section data-card="prescriptions" data-state={state.status} aria-label="Prescriptions">
                <h3>Prescriptions</h3>
                {state.status === 'loading' ? (
                    <p aria-busy="true">Loading prescriptions…</p>
                ) : (
                    <p role="alert">Couldn't load prescriptions</p>
                )}
            </section>
        );
    }
    return (
        <section data-card="prescriptions" data-state="ready" data-patient-id={patientId} aria-label="Prescriptions">
            <h3>Prescriptions</h3>
            {state.data.length === 0 ? (
                // Also shown when every prescription is discontinued; the old card showed an empty table (BM-024).
                <p data-empty>No active prescriptions</p>
            ) : (
                <table className="table table-sm">
                    <thead>
                        <tr>
                            <th scope="col">Drug</th>
                            <th scope="col">Details</th>
                            <th scope="col">Qty</th>
                            <th scope="col">Refills</th>
                            <th scope="col">Added</th>
                        </tr>
                    </thead>
                    <tbody>
                        {state.data.map((prescription) => (
                            <tr key={prescription.id} data-item="prescription">
                                <td data-field="drug">{prescription.name}</td>
                                <td data-field="details">{prescription.dosage}</td>
                                <td data-field="quantity">{prescription.quantity}</td>
                                <td data-field="refills" className="text-muted">
                                    Not available
                                </td>
                                <td data-field="added">{prescription.added}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            )}
            <p className="small text-muted">
                OpenEMR's FHIR API cannot tell list entries from prescriptions. Medication-list entries marked as an
                order appear under Prescriptions.
            </p>
        </section>
    );
}
