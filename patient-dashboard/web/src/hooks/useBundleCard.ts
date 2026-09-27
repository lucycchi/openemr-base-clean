/**
 * The shared loader behind most cards. Each card on the page uses this to fetch its FHIR records for the
 * patient on screen, to make sure every record belongs to that patient, and to turn the records into
 * what the card draws (the card supplies its own `map` step for that).
 *
 * In: the API client, the patient id, the FHIR record type (for example "AllergyIntolerance") and the
 * card's map step. Out: a LoadState (loading, error, or ready with the card's data).
 *
 * A "hook" is a function a React page calls on every redraw; React keeps its remembered values between
 * redraws, and the page redraws itself when the hook's answer changes.
 */
import { useEffect, useState } from 'react';
import type { FhirResource } from 'fhir/r4';
import { assertBelongsTo } from '../api/client';
import type { ApiClient } from '../api/client';
import type { LoadState } from './loadState';

/**
 * A finished result, labelled with the patient it was loaded for ("tag by patient"). If the user switches
 * patient while a request is still on its way, the late answer carries the old patient's id and is
 * never shown for the new one.
 */
interface Loaded<V> {
    /** The patient this result belongs to; a result for any other patient is never returned. */
    forPatientId: string;
    state: LoadState<V>;
}

/**
 * Loads `<resourceType>?patient=<id>` for a card, checks every resource belongs to that patient
 * (BM-004), and maps it. `map` must be a stable (module-level) function.
 * ("Stable" means the same function every time, written once outside any component; a new function on
 * each redraw would make this hook think its inputs had changed and fetch again, over and over.)
 */
export function useBundleCard<R extends FhirResource, V>(
    client: ApiClient,
    patientId: string,
    resourceType: R['resourceType'],
    map: (resources: R[]) => V,
    extraQuery = '',
    // Bumped after a save (ARC-06): a new number asks again. The previous answer stays on screen meanwhile,
    // because it is still labelled with the patient on screen.
    revision = 0,
): LoadState<V> {
    // The latest finished result, remembered between redraws; undefined until the first answer arrives.
    const [loaded, setLoaded] = useState<Loaded<V> | undefined>(undefined);

    // Work done after the page draws: send the request. It reruns whenever an input in the list at the
    // bottom changes (for example the patient), and the returned clean-up marks the old request as
    // `cancelled` so its answer is thrown away.
    useEffect(() => {
        let cancelled = false;
        const path = `${resourceType}?patient=${encodeURIComponent(patientId)}${extraQuery}`;
        // `.then(...)` runs once the server has answered.
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
    }, [client, patientId, resourceType, map, extraQuery, revision]);

    // Only hand back a result labelled with the patient on screen now; anything else reads as loading.
    return loaded !== undefined && loaded.forPatientId === patientId ? loaded.state : { status: 'loading' };
}
