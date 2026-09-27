/**
 * Checks the prescription form a page sends and turns it into the body OpenEMR's POST /api/prescription
 * takes (runs on the server). OpenEMR itself checks only that `drug` and `patient_id` are present
 * (PrescriptionService.php:404), so everything else is checked here. It also sets what the old
 * prescription form sets on its own (Prescription.class.php:225-275).
 *
 * The prescriber goes in the note as "Prescriber: <text>", because OpenEMR's prescriber field only takes
 * OpenEMR's internal user number, which non-admin users cannot look up through the API (ARC-06
 * decision 2).
 */

/** The prescription form, once checked. */
export interface PrescriptionInput {
    drug: string;
    /** Directions, e.g. "1 capsule three times daily"; '' when none. */
    dosage: string;
    /** A whole number as text, or '' when none. */
    quantity: string;
    refills: number;
    /** YYYY-MM-DD */
    startDate: string;
    /** "YYYY-MM-DD HH:MM:SS" in the clinic's local time, from the page */
    dateAdded: string;
    /** Free text, one line; '' when none. */
    prescriber: string;
}

/** The only fields a page may send; anything else is refused rather than passed to OpenEMR. */
const FIELDS = ['drug', 'dosage', 'quantity', 'refills', 'startDate', 'dateAdded', 'prescriber'];
const DATE = /^\d{4}-\d{2}-\d{2}$/;
const DATETIME = /^(\d{4}-\d{2}-\d{2}) (\d{2}):(\d{2}):(\d{2})$/;
const DAY_MS = 86_400_000;

/** True when "YYYY-MM-DD" is a real calendar date (so 2026-02-30 is refused). */
function isRealDate(text: string): boolean {
    if (!DATE.test(text)) {
        return false;
    }
    const date = new Date(`${text}T00:00:00Z`);
    return !Number.isNaN(date.getTime()) && date.toISOString().slice(0, 10) === text;
}

/**
 * The checked form, or a message per field that is wrong. `serverNowMs` is the server's clock: the
 * page's "date added" must be within a day of it, so a wrong page clock cannot backdate a prescription
 * (a day either way allows for the clinic's time zone).
 */
export function parsePrescriptionInput(
    body: unknown,
    serverNowMs: number,
): { ok: true; value: PrescriptionInput } | { ok: false; errors: Record<string, string> } {
    if (typeof body !== 'object' || body === null || Array.isArray(body)) {
        return { ok: false, errors: { form: 'expected an object' } };
    }
    const record = body as Record<string, unknown>;
    const errors: Record<string, string> = {};
    for (const key of Object.keys(record)) {
        if (!FIELDS.includes(key)) {
            errors[key] = 'not a prescription field';
        }
    }
    // A text field's value with spaces trimmed from both ends, or undefined when it is not text.
    const text = (key: string) => (typeof record[key] === 'string' ? record[key].trim() : undefined);

    const drug = text('drug');
    if (drug === undefined || drug.length < 2 || drug.length > 255) {
        errors.drug = 'Enter the drug (2 to 255 characters)';
    }
    const dosage = text('dosage') ?? '';
    if (dosage.length > 255) {
        errors.dosage = 'Keep directions under 255 characters';
    }
    const quantity = text('quantity') ?? '';
    // A whole number from 1 to 999999, or nothing: FHIR only carries a numeric quantity.
    if (quantity !== '' && !/^[1-9]\d{0,5}$/.test(quantity)) {
        errors.quantity = 'Quantity must be a whole number';
    }
    const refills = record.refills;
    if (typeof refills !== 'number' || !Number.isInteger(refills) || refills < 0 || refills > 20) {
        errors.refills = 'Refills must be 0 to 20';
    }
    const startDate = text('startDate') ?? '';
    if (!isRealDate(startDate)) {
        errors.startDate = 'Enter a real start date';
    }
    const dateAdded = text('dateAdded') ?? '';
    const added = DATETIME.exec(dateAdded);
    if (
        added === null ||
        !isRealDate(added[1] ?? '') ||
        Math.abs(Date.parse(dateAdded.replace(' ', 'T')) - serverNowMs) > DAY_MS
    ) {
        errors.dateAdded = 'The page clock is wrong; reload and try again';
    }
    const prescriber = text('prescriber') ?? '';
    if (prescriber.length > 100 || /[\r\n]/.test(typeof record.prescriber === 'string' ? record.prescriber : '')) {
        errors.prescriber = 'Prescriber: one line, up to 100 characters';
    }

    if (Object.keys(errors).length > 0 || drug === undefined || typeof refills !== 'number') {
        return { ok: false, errors };
    }
    return { ok: true, value: { drug, dosage, quantity, refills, startDate, dateAdded, prescriber } };
}

/** The body for OpenEMR's POST /api/prescription: the form, the patient's number, and the old form's defaults. */
export function openemrPrescriptionBody(input: PrescriptionInput, pid: number): Record<string, string | number> {
    return {
        patient_id: pid,
        drug: input.drug,
        drug_dosage_instructions: input.dosage,
        quantity: input.quantity,
        refills: input.refills,
        per_refill: 0,
        start_date: input.startDate,
        date_added: input.dateAdded,
        date_modified: input.dateAdded,
        active: 1,
        request_intent: 'order',
        request_intent_title: 'Order',
        usage_category: 'outpatient',
        usage_category_title: 'Outpatient',
        note: input.prescriber === '' ? '' : `Prescriber: ${input.prescriber}`,
    };
}
