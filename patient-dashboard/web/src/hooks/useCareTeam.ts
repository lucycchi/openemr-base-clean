/**
 * Supplies the Care Team card's data. In: the API client and the patient on screen. Out: a LoadState
 * holding the patient's care teams with each member's name. The loading, patient check and name look-up
 * are done by the shared useBundleWithNames; this file only says which records to load (CareTeam),
 * which people to name (memberReferences) and how to shape the result for the card (mapCareTeams).
 */
import type { CareTeam } from 'fhir/r4';
import type { ApiClient } from '../api/client';
import { mapCareTeams, memberReferences } from '../mappers/careTeam';
import type { CareTeamView } from '../mappers/careTeam';
import type { LoadState } from './loadState';
import { useBundleWithNames } from './useBundleWithNames';

/**
 * Loads the patient's care teams, then reads each member once for a name, because FHIR participants
 * are bare references and _include drops the CareTeam itself (BM-029). Members that cannot be read
 * (HTTP 404 for users without an NPI and for related persons) are shown as "Name unavailable" (BM-028).
 */
export function useCareTeam(client: ApiClient, patientId: string): LoadState<CareTeamView[]> {
    return useBundleWithNames<CareTeam, CareTeamView[]>(client, patientId, 'CareTeam', memberReferences, mapCareTeams);
}
