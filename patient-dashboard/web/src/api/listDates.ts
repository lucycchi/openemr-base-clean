import type { Result } from './client';

export type ListName = 'medication' | 'allergy';

/** What the dashboard needs from one list row (server/listDates.ts). */
export interface ListDates {
    /** lists.enddate as stored, "YYYY-MM-DD HH:MM:SS", or null. */
    enddate: string | null;
    /** 1 means resolved. */
    outcome: number;
}

function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null;
}

/**
 * Parses /api/list-dates into list dates by uuid (the FHIR id of the same list row). An answer for
 * another patient is a wrong-patient error, and an answer for the other list or anything malformed is
 * invalid-response: never an empty map, which would silently change what the card shows.
 */
export function parseListDates(body: unknown, patientId: string, list: ListName): Result<Map<string, ListDates>> {
    const invalid = { ok: false as const, error: { kind: 'invalid-response' as const } };
    if (!isRecord(body) || typeof body.patient !== 'string' || !Array.isArray(body.entries)) {
        return invalid;
    }
    if (body.patient !== patientId) {
        return { ok: false, error: { kind: 'wrong-patient', expected: patientId, found: body.patient } };
    }
    if (body.list !== list) {
        return invalid;
    }
    const dates = new Map<string, ListDates>();
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

/**
 * The old cards' rule for a list row (filterActiveIssues, demographics.php:1111-1113): hide it when
 * marked resolved or when its end date has passed. The old card compares the end date with now, so a
 * row ending today is already hidden. `today` is the local date, YYYY-MM-DD.
 */
export function isCurrentListRow(row: ListDates, today: string): boolean {
    const ends = row.enddate?.slice(0, 10) ?? '';
    return row.outcome !== 1 && (ends === '' || ends > today);
}
