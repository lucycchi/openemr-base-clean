import type { MedicationRequest } from 'fhir/r4';
import type { MedicationListDates } from '../api/medicationListDates';
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

export interface MedicationSplit {
    medications: MedicationView[];
    prescriptions: MedicationView[];
}

function toView(request: MedicationRequest): MedicationView {
    const quantity = request.dispenseRequest?.quantity?.value;
    return {
        id: request.id ?? '',
        name:
            request.medicationCodeableConcept?.text?.trim() ||
            realCodingDisplay(request.medicationCodeableConcept?.coding) ||
            'Unnamed medication',
        dosage: (request.dosageInstruction ?? [])
            .map((instruction) => instruction.text?.trim() ?? '')
            .filter((text) => text !== '')
            .join('; '),
        quantity: quantity === undefined ? '' : String(quantity),
        // OpenEMR sends the stored local time with an offset; keep its date and time as written.
        added: (request.authoredOn ?? '').slice(0, 19).replace('T', ' '),
    };
}

/**
 * OpenEMR sends status "completed" for any active prescription with an end date, past or future, and
 * does not send the end date itself (BM-044). The old Prescriptions card ignores the end date and
 * lists every active prescription, so completed orders are shown too; stopped ones are not.
 */
function isCurrentOrder(request: MedicationRequest): boolean {
    return request.status === 'active' || request.status === 'completed';
}

/**
 * The old medication card's rule (filterActiveIssues, demographics.php:1111-1113): hide an entry
 * marked resolved or whose end date has passed. FHIR cannot apply it, because it sends "completed"
 * for past and future end dates alike (BM-044), so the list row from the standard API decides. The
 * old card compares the end date with now, so an entry ending today is already hidden. Without a
 * list row, only FHIR-active entries are shown.
 */
function isOnMedicationList(
    request: MedicationRequest,
    listDates: ReadonlyMap<string, MedicationListDates>,
    today: string,
): boolean {
    const row = listDates.get(request.id ?? '');
    if (row === undefined) {
        return request.status === 'active';
    }
    const ends = row.enddate?.slice(0, 10) ?? '';
    return row.outcome !== 1 && (ends === '' || ends > today);
}

/**
 * FHIR merges the medication list and prescriptions into one MedicationRequest feed with no field
 * that reliably says which is which (BM-019). Per the Gate 2 decision, intent "order" goes to
 * Prescriptions and everything else to Medications. Medications follow the old list rule using the
 * standard API's list dates (see isOnMedicationList); Prescriptions show active and completed
 * orders (see isCurrentOrder). Medications keep the API order (the old begdate order is not in
 * FHIR; BM-036); prescriptions are newest first. `today` is the local date, YYYY-MM-DD.
 */
export function splitMedications(
    resources: readonly MedicationRequest[],
    listDates: ReadonlyMap<string, MedicationListDates> = new Map(),
    today = '',
): MedicationSplit {
    return {
        medications: resources
            .filter((request) => request.intent !== 'order' && isOnMedicationList(request, listDates, today))
            .map(toView),
        prescriptions: resources
            .filter(
                (request) =>
                    request.intent === 'order' &&
                    // A list row marked Order (BM-019) keeps the list rule; a prescription keeps the Rx rule.
                    (listDates.has(request.id ?? '')
                        ? isOnMedicationList(request, listDates, today)
                        : isCurrentOrder(request)),
            )
            .map(toView)
            .map((view, index) => ({ view, index }))
            .sort((a, b) => (a.view.added === b.view.added ? a.index - b.index : a.view.added < b.view.added ? 1 : -1))
            .map(({ view }) => view),
    };
}
