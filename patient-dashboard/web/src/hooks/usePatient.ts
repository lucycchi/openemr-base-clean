import { useEffect, useState } from 'react';
import type { Patient } from 'fhir/r4';
import type { ApiClient } from '../api/client';
import { mapHeader } from '../mappers/header';
import type { HeaderOptions, HeaderView } from '../mappers/header';
import type { LoadState } from './loadState';

interface Loaded {
    /** The patient this result belongs to; a result for any other patient is never returned. */
    forPatientId: string;
    state: LoadState<HeaderView>;
}

/** Loads and maps the header patient. A Patient whose id differs from the one asked for is a load error (BM-004). */
export function usePatient(client: ApiClient, patientId: string, options: HeaderOptions): LoadState<HeaderView> {
    const [loaded, setLoaded] = useState<Loaded | undefined>(undefined);
    const { asOf, age } = options;

    useEffect(() => {
        let cancelled = false;
        void client.getResource<Patient>(`Patient/${encodeURIComponent(patientId)}`).then((result) => {
            if (cancelled) {
                return;
            }
            let state: LoadState<HeaderView>;
            if (!result.ok) {
                state = { status: 'error', error: result.error };
            } else if (result.value.id !== patientId) {
                state = {
                    status: 'error',
                    error: { kind: 'wrong-patient', expected: patientId, found: result.value.id ?? '(none)' },
                };
            } else {
                state = { status: 'ready', data: mapHeader(result.value, { asOf, age }) };
            }
            setLoaded({ forPatientId: patientId, state });
        });
        return () => {
            cancelled = true;
        };
    }, [client, patientId, asOf, age]);

    // Until the result for *this* patient arrives, report loading, so a patient switch can never
    // show the previous patient's header.
    return loaded !== undefined && loaded.forPatientId === patientId ? loaded.state : { status: 'loading' };
}
