/**
 * The page's side of prescription editing (ARC-06). Sends the form to the BFF (server/prescriptionWrites.ts)
 * and turns its answer into one of four outcomes the card can show:
 * - saved
 * - partly saved: a change added the new prescription but could not discontinue the old one
 * - invalid: a message for each field the server rejected
 * - failed: nothing was changed
 */
import type { WriteClient } from './client';

/** The prescription form as the page sends it; the server checks every field again. */
export interface PrescriptionForm {
    drug: string;
    /** Directions, e.g. "1 capsule three times daily". */
    dosage: string;
    /** A whole number as text, or ''. */
    quantity: string;
    refills: number;
    /** YYYY-MM-DD */
    startDate: string;
    /** "YYYY-MM-DD HH:MM:SS", the clinic's local time when Save was pressed. */
    dateAdded: string;
    /** Typed name, saved in the prescription's note; '' for none. */
    prescriber: string;
}

export type WriteOutcome =
    | { kind: 'saved' }
    | { kind: 'partly-saved'; message: string }
    | { kind: 'invalid'; errors: Record<string, string> }
    | { kind: 'failed'; message: string };

const FAILED: WriteOutcome = { kind: 'failed', message: "Couldn't save. Nothing was changed; try again." };

/** Reads the BFF's answer: 200/201 saved, 207 partly saved, 400 with field messages, anything else failed. */
function outcome(answer: { status: number; body: unknown }): WriteOutcome {
    if (answer.status === 200 || answer.status === 201) {
        return { kind: 'saved' };
    }
    if (answer.status === 207) {
        return {
            kind: 'partly-saved',
            message:
                'The new prescription was saved, but the old one could not be discontinued. Discontinue it from the list.',
        };
    }
    const errors =
        typeof answer.body === 'object' && answer.body !== null && 'errors' in answer.body
            ? answer.body.errors
            : undefined;
    if (answer.status === 400 && typeof errors === 'object' && errors !== null) {
        return { kind: 'invalid', errors: errors as Record<string, string> };
    }
    return FAILED;
}

/** The query naming the patient on screen, e.g. "patient=a2d6...". */
const q = (patientId: string) => `patient=${encodeURIComponent(patientId)}`;

export async function addPrescription(
    client: WriteClient,
    patientId: string,
    form: PrescriptionForm,
): Promise<WriteOutcome> {
    return outcome(await client.sendJson('POST', `prescriptions?${q(patientId)}`, form));
}

export async function discontinuePrescription(
    client: WriteClient,
    patientId: string,
    rxId: string,
): Promise<WriteOutcome> {
    return outcome(
        await client.sendJson('POST', `prescriptions/${encodeURIComponent(rxId)}/discontinue?${q(patientId)}`, {}),
    );
}

export async function replacePrescription(
    client: WriteClient,
    patientId: string,
    rxId: string,
    form: PrescriptionForm,
): Promise<WriteOutcome> {
    return outcome(
        await client.sendJson('POST', `prescriptions/${encodeURIComponent(rxId)}/replace?${q(patientId)}`, form),
    );
}
