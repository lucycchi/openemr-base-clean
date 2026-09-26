import type { Encounter } from 'fhir/r4';
import type { ApiClient } from '../api/client';
import { mapEncounters, providerReferences } from '../mappers/encounters';
import type { EncounterView } from '../mappers/encounters';
import type { LoadState } from './loadState';
import { useBundleWithNames } from './useBundleWithNames';

/** Loads the patient's encounters and reads each provider once for a name (BM-032). */
export function useEncounters(client: ApiClient, patientId: string): LoadState<EncounterView[]> {
    return useBundleWithNames<Encounter, EncounterView[]>(
        client,
        patientId,
        'Encounter',
        providerReferences,
        mapEncounters,
    );
}
