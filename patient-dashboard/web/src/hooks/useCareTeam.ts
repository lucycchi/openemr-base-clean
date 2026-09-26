import { useEffect, useState } from 'react';
import type { CareTeam, Practitioner, RelatedPerson } from 'fhir/r4';
import { assertBelongsTo } from '../api/client';
import type { ApiClient } from '../api/client';
import { mapCareTeams, memberReferences, personName } from '../mappers/careTeam';
import type { CareTeamView } from '../mappers/careTeam';
import type { LoadState } from './loadState';

interface Loaded {
    forPatientId: string;
    state: LoadState<CareTeamView[]>;
}

/** Reads one member for its name; any failure leaves the member unnamed rather than failing the card. */
async function readName(client: ApiClient, patientId: string, reference: string): Promise<string | undefined> {
    const result = await client.getResource<Practitioner | RelatedPerson>(reference);
    if (!result.ok || `${result.value.resourceType}/${result.value.id ?? ''}` !== reference) {
        return undefined;
    }
    if (result.value.resourceType === 'RelatedPerson' && result.value.patient.reference !== `Patient/${patientId}`) {
        return undefined;
    }
    return personName(result.value);
}

/**
 * Loads the patient's care teams, then reads each member once for a name, because FHIR participants
 * are bare references and _include drops the CareTeam itself (BM-029). Members that cannot be read
 * (HTTP 404 for users without an NPI and for related persons) are shown as "Name unavailable" (BM-028).
 */
export function useCareTeam(client: ApiClient, patientId: string): LoadState<CareTeamView[]> {
    const [loaded, setLoaded] = useState<Loaded | undefined>(undefined);

    useEffect(() => {
        let cancelled = false;
        void (async () => {
            const result = await client.getBundle<CareTeam>(`CareTeam?patient=${encodeURIComponent(patientId)}`);
            let state: LoadState<CareTeamView[]>;
            if (!result.ok) {
                state = { status: 'error', error: result.error };
            } else {
                const owned = assertBelongsTo(patientId, result.value);
                if (!owned.ok) {
                    state = { status: 'error', error: owned.error };
                } else {
                    const references = memberReferences(result.value);
                    const found = await Promise.all(
                        references.map((reference) => readName(client, patientId, reference)),
                    );
                    const names = new Map<string, string>();
                    references.forEach((reference, index) => {
                        const name = found[index];
                        if (name !== undefined) {
                            names.set(reference, name);
                        }
                    });
                    state = { status: 'ready', data: mapCareTeams(result.value, names) };
                }
            }
            if (!cancelled) {
                setLoaded({ forPatientId: patientId, state });
            }
        })();
        return () => {
            cancelled = true;
        };
    }, [client, patientId]);

    return loaded !== undefined && loaded.forPatientId === patientId ? loaded.state : { status: 'loading' };
}
