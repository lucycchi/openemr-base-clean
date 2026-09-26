import { useMemo } from 'react';
import type { AllergyIntolerance } from 'fhir/r4';
import type { ApiClient } from '../api/client';
import { mapAllergies } from '../mappers/allergies';
import type { AllergyView } from '../mappers/allergies';
import type { LoadState } from './loadState';
import { useBundleCard } from './useBundleCard';
import { useListDates } from './useMedicationCards';

const keepAll = (resources: AllergyIntolerance[]): AllergyIntolerance[] => resources;

/**
 * The Allergies card: AllergyIntolerance from FHIR plus each allergy's resolved flag and end date
 * from the standard API, because FHIR's clinicalStatus is wrong both ways (BM-016, BM-047). If the
 * dates cannot be read the card shows a load error: guessing could hide an active allergy.
 */
export function useAllergyCard(client: ApiClient, patientId: string, today: string): LoadState<AllergyView[]> {
    const allergies = useBundleCard(client, patientId, 'AllergyIntolerance', keepAll);
    const dates = useListDates(client, patientId, 'allergy');

    return useMemo((): LoadState<AllergyView[]> => {
        if (allergies.status !== 'ready') {
            return allergies;
        }
        if (dates.status !== 'ready') {
            return dates;
        }
        return { status: 'ready', data: mapAllergies(allergies.data, dates.data, today) };
    }, [allergies, dates, today]);
}
