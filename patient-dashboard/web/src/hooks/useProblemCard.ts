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
    return useMemo(
        (): LoadState<ProblemView[]> =>
            rows.status === 'ready' ? { status: 'ready', data: mapProblemList(rows.data, now) } : rows,
        [rows, now],
    );
}
