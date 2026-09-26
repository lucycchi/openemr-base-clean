import type { Result } from './client';

/** What the dashboard needs from one medication-list row (server/medicationEndDates.ts). */
export interface MedicationListDates {
    /** lists.enddate as stored, "YYYY-MM-DD HH:MM:SS", or null. */
    enddate: string | null;
    /** 1 means resolved. */
    outcome: number;
}

function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null;
}

/**
 * Parses /api/medication-end-dates into list dates by uuid (the FHIR MedicationRequest id of a list
 * row). An answer for another patient is a wrong-patient error, and anything malformed is
 * invalid-response: never an empty map, which would silently hide medications.
 */
export function parseMedicationListDates(body: unknown, patientId: string): Result<Map<string, MedicationListDates>> {
    const invalid = { ok: false as const, error: { kind: 'invalid-response' as const } };
    if (!isRecord(body) || typeof body.patient !== 'string' || !Array.isArray(body.entries)) {
        return invalid;
    }
    if (body.patient !== patientId) {
        return { ok: false, error: { kind: 'wrong-patient', expected: patientId, found: body.patient } };
    }
    const dates = new Map<string, MedicationListDates>();
    for (const entry of body.entries) {
        if (!isRecord(entry) || typeof entry.uuid !== 'string' || typeof entry.outcome !== 'number') {
            return invalid;
        }
        const { enddate } = entry;
        if (enddate !== null && typeof enddate !== 'string') {
            return invalid;
        }
        dates.set(entry.uuid, { enddate, outcome: entry.outcome });
    }
    return { ok: true, value: dates };
}
