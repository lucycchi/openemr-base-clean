import { useEffect, useState } from 'react';
import type { FhirResource, Organization, Practitioner, RelatedPerson } from 'fhir/r4';
import { assertBelongsTo } from '../api/client';
import type { ApiClient } from '../api/client';
import { displayName } from '../mappers/people';
import type { LoadState } from './loadState';

interface Loaded<V> {
    forPatientId: string;
    state: LoadState<V>;
}

/** Reads one person or facility for a name; any failure leaves it unnamed rather than failing the card. */
async function readName(client: ApiClient, patientId: string, reference: string): Promise<string | undefined> {
    const result = await client.getResource<Practitioner | RelatedPerson | Organization>(reference);
    if (!result.ok || `${result.value.resourceType}/${result.value.id ?? ''}` !== reference) {
        return undefined;
    }
    if (result.value.resourceType === 'RelatedPerson' && result.value.patient.reference !== `Patient/${patientId}`) {
        return undefined;
    }
    return displayName(result.value);
}

/**
 * Like useBundleCard, for cards whose resources name people only by bare reference (_include is not
 * safe to use, BM-029). After the patient check, each reference `references` returns is read once;
 * `map` gets the names that could be read, and must show everyone else as "Name unavailable".
 * `references` and `map` must be stable (module-level) functions.
 */
export function useBundleWithNames<R extends FhirResource, V>(
    client: ApiClient,
    patientId: string,
    resourceType: R['resourceType'],
    references: (resources: R[]) => string[],
    map: (resources: R[], names: ReadonlyMap<string, string>) => V,
): LoadState<V> {
    const [loaded, setLoaded] = useState<Loaded<V> | undefined>(undefined);

    useEffect(() => {
        let cancelled = false;
        void (async () => {
            const result = await client.getBundle<R>(`${resourceType}?patient=${encodeURIComponent(patientId)}`);
            let state: LoadState<V>;
            if (!result.ok) {
                state = { status: 'error', error: result.error };
            } else {
                const owned = assertBelongsTo(patientId, result.value);
                if (!owned.ok) {
                    state = { status: 'error', error: owned.error };
                } else {
                    const wanted = references(result.value);
                    const found = await Promise.all(wanted.map((reference) => readName(client, patientId, reference)));
                    const names = new Map<string, string>();
                    wanted.forEach((reference, index) => {
                        const name = found[index];
                        if (name !== undefined) {
                            names.set(reference, name);
                        }
                    });
                    state = { status: 'ready', data: map(result.value, names) };
                }
            }
            if (!cancelled) {
                setLoaded({ forPatientId: patientId, state });
            }
        })();
        return () => {
            cancelled = true;
        };
    }, [client, patientId, resourceType, references, map]);

    return loaded !== undefined && loaded.forPatientId === patientId ? loaded.state : { status: 'loading' };
}
