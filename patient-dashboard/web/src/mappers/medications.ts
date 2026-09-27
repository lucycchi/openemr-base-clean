/**
 * Medications and Prescriptions card rules. In: the patient's FHIR MedicationRequest records (FHIR's
 * record of a medicine ordered or taken, each with a status and an "intent" such as order or plan)
 * and OpenEMR's own medication list from the Standard REST API. Out: two lists, one for the
 * Medications card (the patient's medication list) and one for the Prescriptions card (prescriptions
 * that are not on that list), each filtered and ordered the way the old dashboard did.
 */
import type { MedicationRequest } from 'fhir/r4';
import { isCurrentListRow } from '../api/listDates';
import type { ListDates } from '../api/listDates';
import { realCodingDisplay } from './narrative';

/** One row of the Medications or Prescriptions card. See modules/medications.md and prescriptions.md. */
export interface MedicationView {
    id: string;
    name: string;
    /** dosageInstruction text; '' when FHIR has none (BM-038). */
    dosage: string;
    /** dispenseRequest.quantity.value as text; '' when absent. */
    quantity: string;
    /** authoredOn as "YYYY-MM-DD HH:MM:SS" (the old card's date added, labelled "Added"; BM-023). */
    added: string;
}

/** The two lists splitMedications hands back: one per card. */
export interface MedicationSplit {
    medications: MedicationView[];
    prescriptions: MedicationView[];
}

/** Turns one FHIR prescription into a Prescriptions card row. */
function toView(request: MedicationRequest): MedicationView {
    const quantity = request.dispenseRequest?.quantity?.value;
    return {
        id: request.id ?? '',
        // The medicine's free-text name, else its coded name, else "Unnamed medication".
        name:
            request.medicationCodeableConcept?.text?.trim() ||
            realCodingDisplay(request.medicationCodeableConcept?.coding) ||
            'Unnamed medication',
        dosage: dosageText(request),
        quantity: quantity === undefined ? '' : String(quantity),
        // OpenEMR sends the stored local time with an offset; keep its date and time as written.
        // `slice(0, 19)` keeps "YYYY-MM-DDTHH:MM:SS" (dropping the offset), then the "T" becomes a space.
        added: (request.authoredOn ?? '').slice(0, 19).replace('T', ' '),
    };
}

/**
 * OpenEMR sends status "completed" for any active prescription with an end date, past or future, and
 * does not send the end date itself (BM-044). The old Prescriptions card ignores the end date and
 * lists every active prescription, so completed prescriptions are shown too; stopped ones are not.
 */
function isCurrentPrescription(request: MedicationRequest): boolean {
    return request.status === 'active' || request.status === 'completed';
}

/** Every dosage instruction's text, blanks dropped, joined with "; "; '' when there is no request. */
function dosageText(request: MedicationRequest | undefined): string {
    return (request?.dosageInstruction ?? [])
        .map((instruction) => instruction.text?.trim() ?? '')
        .filter((text) => text !== '')
        .join('; ');
}

/**
 * Splits the dashboard's medications as the old cards did (user decision 2026-09-26, replacing the
 * split on intent; BM-019). `listRows` is the Standard REST API medication list, keyed by uuid: it is
 * the old Medications card's own source, and each uuid is the id FHIR gives the same list entry.
 * - Medications: every list entry the old rule keeps (isCurrentListRow), whatever its intent, oldest
 *   start date first with a missing start first, as ORDER BY begdate (BM-036). Dosage comes from the
 *   FHIR request for the same entry; a list entry linked to a prescription has none in FHIR (BM-020).
 * - Prescriptions: every FHIR request that is not a list entry, whatever its intent, active or
 *   completed, newest first.
 * `now` is the local date and time, "YYYY-MM-DD HH:MM:SS".
 * (The old rule, isCurrentListRow: hide an entry marked resolved or whose end date has passed.)
 */
export function splitMedications(
    resources: readonly MedicationRequest[],
    listRows: ReadonlyMap<string, ListDates>,
    now: string,
): MedicationSplit {
    // A lookup table from each FHIR request's id to the request, to find an entry's dosage quickly.
    const byId = new Map(resources.map((request) => [request.id ?? '', request]));
    // Medications card: take every list row as an (id, row) pair, keep the current ones, note each
    // one's position, sort by start date (oldest first; blank start dates sort first as '' comes before
    // any date; equal dates keep list order), then turn each into a card row.
    const medications = [...listRows]
        .filter(([, row]) => isCurrentListRow(row, now))
        .map(([id, row], index) => ({ id, row, index }))
        .sort((a, b) => {
            const startA = a.row.begdate ?? '';
            const startB = b.row.begdate ?? '';
            return startA === startB ? a.index - b.index : startA < startB ? -1 : 1;
        })
        .map(({ id, row }) => ({
            id,
            name: row.title?.trim() || 'Unnamed medication',
            dosage: dosageText(byId.get(id)),
            quantity: '',
            added: '',
        }));
    // Prescriptions card: keep FHIR requests that are not on the medication list and are active or
    // completed, turn each into a row, then sort by date added, newest first (ties keep API order).
    const prescriptions = resources
        .filter((request) => !listRows.has(request.id ?? '') && isCurrentPrescription(request))
        .map(toView)
        .map((view, index) => ({ view, index }))
        .sort((a, b) => (a.view.added === b.view.added ? a.index - b.index : a.view.added < b.view.added ? 1 : -1))
        .map(({ view }) => view);
    return { medications, prescriptions };
}
