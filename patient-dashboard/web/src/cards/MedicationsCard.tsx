/**
 * The Medications card on screen. In: the patient's id and the medication rows prepared by
 * mappers/medications.ts (the "medications" half of splitMedications), or a note that they are still
 * loading or failed. Out: a list with one line per medication, the name followed by its dosage when
 * recorded. The markup looks like HTML; see AllergiesCard.tsx for a short reading guide.
 */
import type { LoadState } from '../hooks/loadState';
import { CardFrame } from './CardFrame';
import { EditInOpenEmr, EditInOpenEmrNote } from './EditInOpenEmr';
import type { MedicationView } from '../mappers/medications';

/** The medication list: every current entry on the old card, from the Standard REST API list (BM-019). */
export function MedicationsCard({ patientId, state }: { patientId: string; state: LoadState<MedicationView[]> }) {
    if (state.status !== 'ready') {
        // Still loading or failed: the heading and a loading or error message (`a ? b : c` chooses).
        return (
            <CardFrame
                card="medications"
                title="Medications"
                state={state.status}
                patientId={patientId}
                actions={<EditInOpenEmr patientId={patientId} />}
            >
                {state.status === 'loading' ? (
                    <p aria-busy="true">Loading medications…</p>
                ) : (
                    <p role="alert">Couldn't load medications</p>
                )}
            </CardFrame>
        );
    }
    return (
        <CardFrame
            card="medications"
            title="Medications"
            state="ready"
            patientId={patientId}
            actions={<EditInOpenEmr patientId={patientId} />}
        >
            {state.data.length === 0 ? (
                <p data-empty>None recorded</p>
            ) : (
                // The old card's flush list: one thin row per entry.
                <ul className="list-group list-group-flush">
                    {/* One list item per medication, in the order given. */}
                    {state.data.map((medication) => (
                        <li key={medication.id} data-item="medication" className="list-group-item p-0 pl-1">
                            <span data-field="name">{medication.name}</span>
                            {/* `cond && …` draws a space and the dosage only when a dosage is recorded. */}
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
            <EditInOpenEmrNote />
        </CardFrame>
    );
}
