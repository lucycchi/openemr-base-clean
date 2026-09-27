/**
 * Reads and checks the answer from the BFF's /api/list-dates route, and decides whether a list row is
 * still current.
 *
 * OpenEMR keeps medications, allergies and medical problems in its "lists" table. FHIR does not report
 * reliably whether a row has ended or been marked resolved, so the BFF fetches those fields from
 * OpenEMR's Standard REST API instead. The medication, allergy and problem card hooks use this file to
 * turn that answer into a lookup table (row id -> dates), and then to apply the old dashboard's rule for
 * which rows to show.
 *
 * In: the raw answer, plus the patient and list that were asked for. Out: a Result holding the table,
 * or an error if anything is off (wrong patient, wrong list, malformed data).
 */
import type { Result } from './client';

/** The three lists this route serves; a value of this type must be exactly one of these words. */
export type ListName = 'medication' | 'allergy' | 'medical_problem';

/** What the dashboard needs from one list row (server/listDates.ts). */
export interface ListDates {
    /** lists.enddate as stored, "YYYY-MM-DD HH:MM:SS", or null. */
    enddate: string | null;
    /** 1 means resolved. */
    outcome: number;
    /** The title the clinician entered, which the old cards show, and the start date. */
    title?: string;
    begdate?: string | null;
}

/** True when a value is an object with named fields (not text, a number, or nothing). */
function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null;
}

/**
 * Parses /api/list-dates into list dates by uuid (the FHIR id of the same list row). An answer for
 * another patient is a wrong-patient error, and an answer for the other list or anything malformed is
 * invalid-response: never an empty map, which would silently change what the card shows.
 * The result is a Map: a lookup table where each row's id finds that row's dates.
 */
export function parseListDates(body: unknown, patientId: string, list: ListName): Result<Map<string, ListDates>> {
    const invalid = { ok: false as const, error: { kind: 'invalid-response' as const } };
    // The answer must name a patient and carry a list of entries.
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
    // Check every row; a single malformed row rejects the whole answer rather than being skipped.
    for (const entry of body.entries) {
        if (!isRecord(entry) || typeof entry.uuid !== 'string' || typeof entry.outcome !== 'number') {
            return invalid;
        }
        const { enddate, title, begdate } = entry;
        if (enddate !== null && typeof enddate !== 'string') {
            return invalid;
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
    // A 10-character value is a date alone, so add midnight. Otherwise keep the first 19 characters
    // (date and time) and swap the "T" that some formats put between them for a space.
    return value.length === 10 ? `${value} 00:00:00` : value.slice(0, 19).replace('T', ' ');
}

/**
 * The old cards' rule for a list row (filterActiveIssues, demographics.php:1111-1113): hide it when
 * marked resolved or when its end has passed. The end is stored with a time of day
 * (add_edit_issue.php:218) and compared with now, so a row ending tonight still shows this afternoon.
 * `now` is the local date and time, "YYYY-MM-DD HH:MM:SS".
 */
export function isCurrentListRow(row: ListDates, now: string): boolean {
    // `a ? b : c` means "if a then b, otherwise c". No end date is stored as '' here.
    const ends = row.enddate === null || row.enddate === '' ? '' : asDateTime(row.enddate);
    // Dates in this fixed format sort as text, so a plain "greater than" compares them in time order.
    return row.outcome !== 1 && (ends === '' || ends > asDateTime(now));
}
