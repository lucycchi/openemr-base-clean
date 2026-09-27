/**
 * Supplies the data for two cards, Medications and Prescriptions, and holds useListDates, which the
 * Allergies and Medical Problems cards use as well.
 *
 * Both medication cards come from one FHIR MedicationRequest search, combined with the medication list's
 * end dates and resolved flags from the BFF's /api/list-dates route (FHIR alone cannot tell which entries
 * are still current). In: the API client, the patient on screen and the current local time. Out: one
 * LoadState per card.
 */
import { useEffect, useMemo, useState } from 'react';
import type { MedicationRequest } from 'fhir/r4';
import type { ApiClient } from '../api/client';
import { parseListDates } from '../api/listDates';
import type { ListDates, ListName } from '../api/listDates';
import { splitMedications } from '../mappers/medications';
import type { MedicationView } from '../mappers/medications';
import type { LoadState } from './loadState';
import { useBundleCard } from './useBundleCard';

/** The two cards' states, handed back together. */
export interface MedicationCards {
    medications: LoadState<MedicationView[]>;
    prescriptions: LoadState<MedicationView[]>;
}

/** Finished list dates, labelled with the patient they were loaded for, as in useBundleCard. */
interface LoadedDates {
    forPatientId: string;
    state: LoadState<Map<string, ListDates>>;
}

// A "map" step that passes the records through unchanged; the sorting into two cards happens later.
const keepAll = (resources: MedicationRequest[]): MedicationRequest[] => resources;

/**
 * A list's end dates and outcomes from the standard API (BM-044, BM-047), tagged by patient like every
 * card hook. The answer is a lookup table from each list row's id to its dates, or a load error.
 */
export function useListDates(client: ApiClient, patientId: string, list: ListName): LoadState<Map<string, ListDates>> {
    const [loaded, setLoaded] = useState<LoadedDates | undefined>(undefined);

    // After the page draws, ask the BFF for the list; reruns when the patient or list changes, and the
    // clean-up discards an earlier request's late answer.
    useEffect(() => {
        let cancelled = false;
        void client.getJson(`list-dates?list=${list}&patient=${encodeURIComponent(patientId)}`).then((result) => {
            if (cancelled) {
                return;
            }
            // Check the answer is for this patient and this list, and well formed (see api/listDates.ts).
            const parsed = result.ok ? parseListDates(result.value, patientId, list) : result;
            setLoaded({
                forPatientId: patientId,
                state: parsed.ok ? { status: 'ready', data: parsed.value } : { status: 'error', error: parsed.error },
            });
        });
        return () => {
            cancelled = true;
        };
    }, [client, patientId, list]);

    // Only hand back a result labelled with the patient on screen now; anything else reads as loading.
    return loaded !== undefined && loaded.forPatientId === patientId ? loaded.state : { status: 'loading' };
}

/**
 * Both medication cards from one MedicationRequest search plus the list end dates. Both need the end
 * dates, so both show a load error if those fail rather than guess.
 */
export function useMedicationCards(client: ApiClient, patientId: string, now: string): MedicationCards {
    // Start both requests at once.
    const requests = useBundleCard(client, patientId, 'MedicationRequest', keepAll);
    const dates = useListDates(client, patientId, 'medication');

    // `useMemo` recalculates the two cards only when one of the inputs listed at the end changes,
    // rather than on every redraw.
    return useMemo((): MedicationCards => {
        if (requests.status !== 'ready') {
            return { medications: requests, prescriptions: requests };
        }
        // Without the list dates neither card is safe: FHIR cannot tell a finished list entry marked
        // Order from a current prescription (Codex review 3), so both wait, and both fail together.
        if (dates.status !== 'ready') {
            return { medications: dates, prescriptions: dates };
        }
        // Sort into the two cards: current medication-list entries go to Medications, and the other
        // prescriptions to Prescriptions (the rules are in mappers/medications.ts).
        const split = splitMedications(requests.data, dates.data, now);
        return {
            medications: { status: 'ready', data: split.medications },
            prescriptions: { status: 'ready', data: split.prescriptions },
        };
    }, [requests, dates, now]);
}
