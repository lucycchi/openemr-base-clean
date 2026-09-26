import type { Result } from './client';

export type ListName = 'medication' | 'allergy' | 'medical_problem';

/** What the dashboard needs from one list row (server/listDates.ts). */
export interface ListDates {
    /** lists.enddate as stored, "YYYY-MM-DD HH:MM:SS", or null. */
    enddate: string | null;
    /** 1 means resolved. */
    outcome: number;
    /** Problems only (the card is built from the list, BM-051). */
    title?: string;
    begdate?: string | null;
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
        const { enddate, title, begdate } = entry;
        if (enddate !== null && typeof enddate !== 'string') {
            return invalid;
        }
        if (list !== 'medical_problem') {
            dates.set(entry.uuid, { enddate, outcome: entry.outcome });
            continue;
        }
        if (typeof title !== 'string' || (begdate !== null && typeof begdate !== 'string')) {
            return invalid;
        }
        dates.set(entry.uuid, { enddate, outcome: entry.outcome, title, begdate });
    }
    return { ok: true, value: dates };
}

/** A stored date or datetime as "YYYY-MM-DD HH:MM:SS"; a bare date means midnight. */
function asDateTime(value: string): string {
    return value.length === 10 ? `${value} 00:00:00` : value.slice(0, 19).replace('T', ' ');
}

/**
 * The old cards' rule for a list row (filterActiveIssues, demographics.php:1111-1113): hide it when
 * marked resolved or when its end has passed. The end is stored with a time of day
 * (add_edit_issue.php:218) and compared with now, so a row ending tonight still shows this afternoon.
 * `now` is the local date and time, "YYYY-MM-DD HH:MM:SS".
 */
export function isCurrentListRow(row: ListDates, now: string): boolean {
    const ends = row.enddate === null || row.enddate === '' ? '' : asDateTime(row.enddate);
    return row.outcome !== 1 && (ends === '' || ends > asDateTime(now));
}
