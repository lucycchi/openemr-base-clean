/**
 * The page's side of prescription editing (ARC-06). Sends the form to the BFF (server/prescriptionWrites.ts)
 * and turns its answer into one of four outcomes the card can show:
 * - saved
 * - partly saved: a change added the new prescription but could not discontinue the old one
 * - invalid: a message for each field the server rejected
 * - failed: the server refused before writing, so nothing was changed
 * - uncertain: no clear answer (a timeout, a lost connection, a server fault): OpenEMR may have saved it,
 *   so the user is told to check the list before trying again, rather than risk a duplicate
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
    | { kind: 'failed'; message: string }
    | { kind: 'uncertain'; message: string };

const FAILED: WriteOutcome = { kind: 'failed', message: "Couldn't save. Nothing was changed; try again." };
const UNCERTAIN: WriteOutcome = {
    kind: 'uncertain',
    message: 'This may not have saved: OpenEMR did not answer clearly. Check the list before trying again.',
};
/** Answers that mean the BFF refused the request before anything was written. */
const REFUSED = [400, 401, 403, 404, 415];

/**
 * Reads the BFF's answer: 200/201 saved, 207 partly saved, 400 with field messages, other refusals
 * failed, and anything else (0 = no answer, 5xx) uncertain.
 */
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
    return REFUSED.includes(answer.status) ? FAILED : UNCERTAIN;
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

/**
 * True when the prescription list should be read again after a write: anything that may have changed it,
 * including an unclear answer, so the user can see whether an uncertain save went through before retrying.
 */
export function shouldReload(outcome: WriteOutcome): boolean {
    return outcome.kind === 'saved' || outcome.kind === 'partly-saved' || outcome.kind === 'uncertain';
}
