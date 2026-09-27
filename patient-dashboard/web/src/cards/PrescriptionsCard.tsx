/**
 * The Prescriptions card on screen. In: the patient's id and the prescription rows prepared by
 * mappers/medications.ts (the "prescriptions" half of splitMedications, newest first), or a note that
 * they are still loading or failed. Out: a table with one row per prescription (drug, details,
 * quantity, refills, date added). The markup looks like HTML; see AllergiesCard.tsx for a reading guide.
 *
 * When `editing` is given (ARC-06), the card also lets the clinician:
 * - Add a prescription (the button in the title bar, where the old card has its pencil)
 * - Change one: the form opens filled in with that row; saving adds the corrected prescription and
 *   discontinues the old one, because OpenEMR's API cannot update a prescription
 * - Discontinue one, after an "are you sure?" question. OpenEMR marks it inactive, as unticking "active"
 *   on the old form does; nothing is deleted.
 * Without `editing` the card is read-only, as before.
 */
import { useState } from 'react';
import type { LoadState } from '../hooks/loadState';
import type { PrescriptionForm, WriteOutcome } from '../api/prescriptionWrites';
import { CardFrame } from './CardFrame';
import { PrescriptionFormPanel } from './PrescriptionForm';
import type { MedicationView } from '../mappers/medications';

/** What the card needs to edit prescriptions; each write answers with an outcome the card shows. */
export interface PrescriptionEditing {
    /** The signed-in user's name, the form's starting prescriber ('' when unknown). */
    prescriberDefault: string;
    /** Today as YYYY-MM-DD. */
    today: string;
    /** The local date and time as "YYYY-MM-DD HH:MM:SS". */
    nowText: () => string;
    add: (form: PrescriptionForm) => Promise<WriteOutcome>;
    change: (rxId: string, form: PrescriptionForm) => Promise<WriteOutcome>;
    discontinue: (rxId: string) => Promise<WriteOutcome>;
}

/** A row named so it can be told apart from a similar one: "Omeprazole 20 mg, 1 daily, added 2026-09-26 11:58:32". */
function describe(row: MedicationView): string {
    return [row.name, row.dosage, row.added === '' ? '' : `added ${row.added}`]
        .filter((part) => part !== '')
        .join(', ');
}

/** What the card is showing: the list, the Add or Change form, or the Discontinue question for one row. */
type Mode =
    | { kind: 'list' }
    | { kind: 'add' }
    | { kind: 'change'; row: MedicationView }
    | { kind: 'confirm'; row: MedicationView };

/**
 * Active prescriptions, newest first. "Added" is the date the prescription was entered; the old card
 * labelled it "Filled" (BM-023). Details is dosageInstruction text, blank when FHIR has none (BM-038).
 * Refills say "Not available": OpenEMR's FHIR query never reads the refills column and always sends 0 (BM-041).
 */
export function PrescriptionsCard({
    patientId,
    state,
    editing,
}: {
    patientId: string;
    state: LoadState<MedicationView[]>;
    editing?: PrescriptionEditing;
}) {
    const [mode, setMode] = useState<Mode>({ kind: 'list' });
    // A message kept above the list after a write, e.g. a change that could not discontinue the old one.
    const [notice, setNotice] = useState('');
    // True while any write (Add, Change or Discontinue) is on its way. Every button that starts a write is
    // locked meanwhile, so a second one cannot start or close the form that is waiting (final review).
    const [busy, setBusy] = useState(false);

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

    /**
     * Runs one Add or Change. On success goes back to the list. When a Change added the new prescription but
     * could not discontinue the old one, the message names the old one, so the right row gets discontinued.
     * Other outcomes keep the form open; it shows their message.
     */
    async function afterWrite(write: () => Promise<WriteOutcome>, old?: MedicationView): Promise<WriteOutcome> {
        setBusy(true);
        const outcome = await write().finally(() => setBusy(false));
        if (outcome.kind === 'saved') {
            setNotice('');
            setMode({ kind: 'list' });
        } else if (outcome.kind === 'partly-saved') {
            const details = old === undefined ? '' : describe(old);
            setNotice(
                old === undefined
                    ? outcome.message
                    : `The corrected prescription was saved, but the old one (${details}) is still active. Discontinue it below.`,
            );
            setMode({ kind: 'list' });
        }
        return outcome;
    }

    async function confirmDiscontinue(row: MedicationView) {
        if (editing === undefined || busy) {
            return;
        }
        setBusy(true);
        const outcome = await editing.discontinue(row.id).finally(() => setBusy(false));
        setMode({ kind: 'list' });
        setNotice(
            outcome.kind === 'saved'
                ? ''
                : outcome.kind === 'uncertain'
                  ? `${describe(row)}: ${outcome.message}`
                  : `${row.name} was not discontinued. Try again.`,
        );
    }

    const addButton =
        editing === undefined ? undefined : (
            <button
                type="button"
                className="btn btn-link btn-sm p-0"
                aria-label="Add prescription"
                disabled={busy}
                onClick={() => {
                    setNotice('');
                    setMode({ kind: 'add' });
                }}
            >
                Add
            </button>
        );

    return (
        <CardFrame card="prescriptions" title="Prescriptions" state="ready" patientId={patientId} actions={addButton}>
            {notice !== '' && (
                <p role="alert" className="text-danger small">
                    {notice}
                </p>
            )}
            {/* A Change cannot carry everything over, and OpenEMR's own screens misread the discontinue (BM-067). */}
            {editing !== undefined && mode.kind === 'change' && (
                <p className="small text-muted mb-1">
                    Changing adds a corrected prescription and discontinues this one. Refills are not carried over; set
                    them below. OpenEMR's own prescription screens will still list it as active.
                </p>
            )}
            {/* The Add or Change form opens above the list. */}
            {editing !== undefined && (mode.kind === 'add' || mode.kind === 'change') && (
                <PrescriptionFormPanel
                    key={mode.kind === 'change' ? mode.row.id : 'add'}
                    initial={
                        mode.kind === 'change'
                            ? { drug: mode.row.name, dosage: mode.row.dosage, quantity: mode.row.quantity }
                            : {}
                    }
                    prescriberDefault={editing.prescriberDefault}
                    today={editing.today}
                    nowText={editing.nowText}
                    onSave={(form) =>
                        mode.kind === 'change'
                            ? afterWrite(() => editing.change(mode.row.id, form), mode.row)
                            : afterWrite(() => editing.add(form))
                    }
                    onCancel={() => setMode({ kind: 'list' })}
                />
            )}
            {/* The "are you sure?" question for Discontinue, in the card rather than a pop-up. */}
            {editing !== undefined && mode.kind === 'confirm' && (
                <div role="alertdialog" aria-label="Discontinue prescription" className="border rounded p-2 mb-2">
                    <p className="mb-1">Discontinue {mode.row.name}?</p>
                    {/* OpenEMR's API marks it inactive in a way OpenEMR's own screens misread as active (BM-067). */}
                    <p className="small text-muted mb-2">
                        OpenEMR's own prescription screens will still list it as active, and saving it there makes it
                        active again; the dashboard and FHIR show it as stopped.
                    </p>
                    <button
                        type="button"
                        className="btn btn-danger btn-sm mr-2"
                        disabled={busy}
                        onClick={() => void confirmDiscontinue(mode.row)}
                    >
                        Yes, discontinue
                    </button>
                    <button
                        type="button"
                        className="btn btn-link btn-sm"
                        disabled={busy}
                        onClick={() => setMode({ kind: 'list' })}
                    >
                        Keep it
                    </button>
                </div>
            )}
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
                                {editing !== undefined && (
                                    <th scope="col">
                                        <span className="sr-only">Actions</span>
                                    </th>
                                )}
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
                                    {editing !== undefined && (
                                        <td className="text-nowrap">
                                            <button
                                                type="button"
                                                className="btn btn-link btn-sm p-0 mr-2"
                                                aria-label={`Change ${prescription.name}`}
                                                disabled={busy}
                                                onClick={() => {
                                                    setNotice('');
                                                    setMode({ kind: 'change', row: prescription });
                                                }}
                                            >
                                                Change
                                            </button>
                                            <button
                                                type="button"
                                                className="btn btn-link btn-sm p-0 text-danger"
                                                aria-label={`Discontinue ${prescription.name}`}
                                                disabled={busy}
                                                onClick={() => {
                                                    setNotice('');
                                                    setMode({ kind: 'confirm', row: prescription });
                                                }}
                                            >
                                                Discontinue
                                            </button>
                                        </td>
                                    )}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </CardFrame>
    );
}
