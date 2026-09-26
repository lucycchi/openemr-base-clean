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
