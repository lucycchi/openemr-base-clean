/**
 * Supplies the patient header (name, date of birth, age and so on) at the top of the dashboard. In: the
 * API client, the patient id from the web address, and display options (today's date for the age, and
 * the site's age and date formats). Out: a LoadState holding the header, or an error. App.tsx shows no
 * clinical card until this is ready and has confirmed the record is the patient asked for.
 */
import { useEffect, useState } from 'react';
import type { Patient } from 'fhir/r4';
import type { ApiClient } from '../api/client';
import { mapHeader } from '../mappers/header';
import type { HeaderOptions, HeaderView } from '../mappers/header';
import type { LoadState } from './loadState';

/** A finished header result, labelled with the patient it was loaded for ("tag by patient"). */
interface Loaded {
    /** The patient this result belongs to; a result for any other patient is never returned. */
    forPatientId: string;
    state: LoadState<HeaderView>;
}

/**
 * Loads and maps the header patient. A Patient whose id differs from the one asked for is a load error
 * (BM-004).
 */
export function usePatient(client: ApiClient, patientId: string, options: HeaderOptions): LoadState<HeaderView> {
    const [loaded, setLoaded] = useState<Loaded | undefined>(undefined);
    const { asOf, age, dateFormat } = options;

    // After the page draws, read the Patient record. Reruns when the patient or a display option
    // changes; the clean-up discards an earlier request's late answer.
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
                // Turn the FHIR record into the header's display values. The date format is passed on only
                // when the site set one (the `...` adds its fields to the options when present).
                state = {
                    status: 'ready',
                    data: mapHeader(result.value, { asOf, age, ...(dateFormat === undefined ? {} : { dateFormat }) }),
                };
            }
            setLoaded({ forPatientId: patientId, state });
        });
        return () => {
            cancelled = true;
        };
    }, [client, patientId, asOf, age, dateFormat]);

    // Until the result for *this* patient arrives, report loading, so a patient switch can never
    // show the previous patient's header.
    return loaded !== undefined && loaded.forPatientId === patientId ? loaded.state : { status: 'loading' };
}
