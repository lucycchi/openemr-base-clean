import type { MedicationRequest } from 'fhir/r4';
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
 * FHIR merges the medication list and prescriptions into one MedicationRequest feed with no field
 * that reliably says which is which (BM-019). Per the Gate 2 decision, intent "order" goes to
 * Prescriptions and everything else to Medications. Medications shows active requests only;
 * Prescriptions also shows completed ones (see isCurrentOrder). Medications
 * keep the API order (the old begdate order is not in FHIR; BM-036); prescriptions are newest first.
 */
export function splitMedications(resources: readonly MedicationRequest[]): MedicationSplit {
    const active = resources.filter((request) => request.status === 'active');
    return {
        medications: active.filter((request) => request.intent !== 'order').map(toView),
        prescriptions: resources
            .filter((request) => request.intent === 'order' && isCurrentOrder(request))
            .map(toView)
            .map((view, index) => ({ view, index }))
            .sort((a, b) => (a.view.added === b.view.added ? a.index - b.index : a.view.added < b.view.added ? 1 : -1))
            .map(({ view }) => view),
    };
}
