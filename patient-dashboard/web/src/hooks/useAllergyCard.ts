/**
 * Supplies the Allergies card's data. It combines two sources: the patient's allergy records from FHIR,
 * and each allergy's end date and resolved flag from the BFF's /api/list-dates route (useListDates). In:
 * the API client, the patient on screen and the current local time. Out: one LoadState for the card,
 * which is ready only when both sources have loaded.
 */
import { useMemo } from 'react';
import type { AllergyIntolerance } from 'fhir/r4';
import type { ApiClient } from '../api/client';
import { mapAllergies } from '../mappers/allergies';
import type { AllergyView } from '../mappers/allergies';
import type { LoadState } from './loadState';
import { useBundleCard } from './useBundleCard';
import { useListDates } from './useMedicationCards';

// A "map" step that passes the records through unchanged; they are combined with the dates below.
const keepAll = (resources: AllergyIntolerance[]): AllergyIntolerance[] => resources;

/**
 * The Allergies card: AllergyIntolerance from FHIR plus each allergy's resolved flag and end date
 * from the standard API, because FHIR's clinicalStatus is wrong both ways (BM-016, BM-047). If the
 * dates cannot be read the card shows a load error: guessing could hide an active allergy.
 */
export function useAllergyCard(client: ApiClient, patientId: string, now: string): LoadState<AllergyView[]> {
    // Start both requests at once.
    const allergies = useBundleCard(client, patientId, 'AllergyIntolerance', keepAll);
    const dates = useListDates(client, patientId, 'allergy');

    // Recalculated only when one of the inputs listed at the end changes. If either source is still
    // loading or has failed, the card shows that state; otherwise the two are combined.
    return useMemo((): LoadState<AllergyView[]> => {
        if (allergies.status !== 'ready') {
            return allergies;
        }
        if (dates.status !== 'ready') {
            return dates;
        }
        return { status: 'ready', data: mapAllergies(allergies.data, dates.data, now) };
    }, [allergies, dates, now]);
}
