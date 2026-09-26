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
    /** dispenseRequest.numberOfRepeatsAllowed as text; '' when absent. */
    refills: string;
    /** authoredOn as "YYYY-MM-DD HH:MM:SS" (the old card's date added, labelled "Added"; BM-023). */
    added: string;
}

export interface MedicationSplit {
    medications: MedicationView[];
    prescriptions: MedicationView[];
}

function toView(request: MedicationRequest): MedicationView {
    const quantity = request.dispenseRequest?.quantity?.value;
    const refills = request.dispenseRequest?.numberOfRepeatsAllowed;
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
        refills: refills === undefined ? '' : String(refills),
        // OpenEMR sends the stored local time with an offset; keep its date and time as written.
        added: (request.authoredOn ?? '').slice(0, 19).replace('T', ' '),
    };
}

/**
 * FHIR merges the medication list and prescriptions into one MedicationRequest feed with no field
 * that reliably says which is which (BM-019). Per the Gate 2 decision, intent "order" goes to
 * Prescriptions and everything else to Medications. Only active requests are shown. Medications
 * keep the API order (the old begdate order is not in FHIR; BM-036); prescriptions are newest first.
 */
export function splitMedications(resources: readonly MedicationRequest[]): MedicationSplit {
    const active = resources.filter((request) => request.status === 'active');
    return {
        medications: active.filter((request) => request.intent !== 'order').map(toView),
        prescriptions: active
            .filter((request) => request.intent === 'order')
            .map(toView)
            .map((view, index) => ({ view, index }))
            .sort((a, b) => (a.view.added === b.view.added ? a.index - b.index : a.view.added < b.view.added ? 1 : -1))
            .map(({ view }) => view),
    };
}
