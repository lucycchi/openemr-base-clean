/**
 * Medical Problems card rules. In: OpenEMR's own problem list rows from the Standard REST API (title,
 * start and end dates, and whether each is resolved) and the current local time. Out: the problem
 * names the Medical Problems card lists, current ones only, oldest first.
 */
import { isCurrentListRow } from '../api/listDates';
import type { ListDates } from '../api/listDates';

/** One row of the problem list. See modules/problem-list.md. */
export interface ProblemView {
    id: string;
    name: string;
}

/**
 * The problem list, built from the standard API's list (the old card's own source) because FHIR's
 * leaves out any problem without activity = 1, reports status wrongly (BM-017) and splits a
 * visit-linked problem into one copy per visit (BM-043); see BM-051. The rows are keyed by the
 * problem's own uuid, so a problem the API repeats per linked visit appears once. The old card's rule
 * applies (hide resolved or past-ended), then oldest start date first with a missing start first, as
 * ORDER BY begdate (BM-018). `now` is the local date and time, "YYYY-MM-DD HH:MM:SS".
 */
export function mapProblemList(rows: ReadonlyMap<string, ListDates>, now: string): ProblemView[] {
    // Take every row as an (id, row) pair; keep the current ones; note each one's position; sort by
    // start date, oldest first (a blank start sorts first, equal dates keep list order); then keep
    // just the id and the title as the name.
    return [...rows]
        .filter(([, row]) => isCurrentListRow(row, now))
        .map(([id, row], index) => ({ id, row, index }))
        .sort((a, b) => {
            const startA = a.row.begdate ?? '';
            const startB = b.row.begdate ?? '';
            return startA === startB ? a.index - b.index : startA < startB ? -1 : 1;
        })
        .map(({ id, row }) => ({ id, name: row.title?.trim() || 'Unnamed problem' }));
}
