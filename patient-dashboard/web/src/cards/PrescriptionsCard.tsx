/**
 * The Prescriptions card on screen. In: the patient's id and the prescription rows prepared by
 * mappers/medications.ts (the "prescriptions" half of splitMedications, newest first), or a note that
 * they are still loading or failed. Out: a table with one row per prescription (drug, details,
 * quantity, refills, date added). The markup looks like HTML; see AllergiesCard.tsx for a reading guide.
 */
import type { LoadState } from '../hooks/loadState';
import { CardFrame } from './CardFrame';
import type { MedicationView } from '../mappers/medications';

/**
 * Active prescriptions, newest first. "Added" is the date the prescription was entered; the old card
 * labelled it "Filled" (BM-023). Details is dosageInstruction text, blank when FHIR has none (BM-038).
 * Refills say "Not available": OpenEMR's FHIR query never reads the refills column and always sends 0 (BM-041).
 */
export function PrescriptionsCard({ patientId, state }: { patientId: string; state: LoadState<MedicationView[]> }) {
    if (state.status !== 'ready') {
        // Still loading or failed: the heading and a loading or error message (`a ? b : c` chooses).
        return (
            <CardFrame card="prescriptions" title="Prescriptions" state={state.status} patientId={patientId}>
                {state.status === 'loading' ? (
                    <p aria-busy="true">Loading prescriptions…</p>
                ) : (
                    <p role="alert">Couldn't load prescriptions</p>
                )}
            </CardFrame>
        );
    }
    return (
        <CardFrame card="prescriptions" title="Prescriptions" state="ready" patientId={patientId}>
            {state.data.length === 0 ? (
                // Also shown when every prescription is discontinued; the old card showed an empty table (BM-024).
                <p data-empty>No active prescriptions</p>
            ) : (
                // A table: <thead> is the header row, <tbody> holds one <tr> row per prescription.
                // Striped, and scrolls sideways on a narrow screen, as the old table (general_fragment.html) does.
                <div className="table-responsive">
                    <table className="table table-sm table-striped">
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
                </div>
            )}
        </CardFrame>
    );
}
