import { useEffect, useState } from 'react';
import type { FhirResource } from 'fhir/r4';
import { assertBelongsTo } from '../api/client';
import type { ApiClient } from '../api/client';
import type { LoadState } from './loadState';

interface Loaded<V> {
    /** The patient this result belongs to; a result for any other patient is never returned. */
    forPatientId: string;
    state: LoadState<V>;
}

/**
 * Loads `<resourceType>?patient=<id>` for a card, checks every resource belongs to that patient
 * (BM-004), and maps it. `map` must be a stable (module-level) function.
 */
export function useBundleCard<R extends FhirResource, V>(
    client: ApiClient,
    patientId: string,
    resourceType: R['resourceType'],
    map: (resources: R[]) => V,
    extraQuery = '',
): LoadState<V> {
    const [loaded, setLoaded] = useState<Loaded<V> | undefined>(undefined);

    useEffect(() => {
        let cancelled = false;
        const path = `${resourceType}?patient=${encodeURIComponent(patientId)}${extraQuery}`;
        void client.getBundle<R>(path).then((result) => {
            if (cancelled) {
                return;
            }
            let state: LoadState<V>;
            if (!result.ok) {
                state = { status: 'error', error: result.error };
            } else {
                const owned = assertBelongsTo(patientId, result.value);
                state = owned.ok
                    ? { status: 'ready', data: map(result.value) }
                    : { status: 'error', error: owned.error };
            }
            setLoaded({ forPatientId: patientId, state });
        });
        return () => {
            cancelled = true;
        };
    }, [client, patientId, resourceType, map, extraQuery]);

    return loaded !== undefined && loaded.forPatientId === patientId ? loaded.state : { status: 'loading' };
}
