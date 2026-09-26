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
 * ORDER BY begdate (BM-018). `today` is the local date, YYYY-MM-DD.
 */
export function mapProblemList(rows: ReadonlyMap<string, ListDates>, today: string): ProblemView[] {
    return [...rows]
        .filter(([, row]) => isCurrentListRow(row, today))
        .map(([id, row], index) => ({ id, row, index }))
        .sort((a, b) => {
            const startA = a.row.begdate ?? '';
            const startB = b.row.begdate ?? '';
            return startA === startB ? a.index - b.index : startA < startB ? -1 : 1;
        })
        .map(({ id, row }) => ({ id, name: row.title?.trim() || 'Unnamed problem' }));
}
