/**
 * Supplies the Medical Problems card's data. Unlike the other cards it does not use FHIR: it reads the
 * problem list from the BFF's /api/list-dates route (useListDates), then keeps the current problems in
 * start-date order (mapProblemList). In: the API client, the patient on screen and the current local
 * time. Out: a LoadState for the card.
 */
import { useMemo } from 'react';
import type { ApiClient } from '../api/client';
import { mapProblemList } from '../mappers/problems';
import type { ProblemView } from '../mappers/problems';
import type { LoadState } from './loadState';
import { useListDates } from './useMedicationCards';

/**
 * The Medical Problems card, from the standard API's problem list, which is the old card's own source
 * (BM-051). A failed read is a load error, never an empty list.
 */
export function useProblemCard(client: ApiClient, patientId: string, now: string): LoadState<ProblemView[]> {
    const rows = useListDates(client, patientId, 'medical_problem');
    // Recalculated only when the rows or the time change. Loading and error states pass straight through.
    return useMemo(
        (): LoadState<ProblemView[]> =>
            rows.status === 'ready' ? { status: 'ready', data: mapProblemList(rows.data, now) } : rows,
        [rows, now],
    );
}
