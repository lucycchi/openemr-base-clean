import { useEffect, useMemo, useState } from 'react';
import type { MedicationRequest } from 'fhir/r4';
import type { ApiClient } from '../api/client';
import { parseListDates } from '../api/listDates';
import type { ListDates, ListName } from '../api/listDates';
import { splitMedications } from '../mappers/medications';
import type { MedicationView } from '../mappers/medications';
import type { LoadState } from './loadState';
import { useBundleCard } from './useBundleCard';

export interface MedicationCards {
    medications: LoadState<MedicationView[]>;
    prescriptions: LoadState<MedicationView[]>;
}

interface LoadedDates {
    forPatientId: string;
    state: LoadState<Map<string, ListDates>>;
}

const keepAll = (resources: MedicationRequest[]): MedicationRequest[] => resources;

/** A list's end dates and outcomes from the standard API (BM-044, BM-047), tagged by patient like every card hook. */
export function useListDates(client: ApiClient, patientId: string, list: ListName): LoadState<Map<string, ListDates>> {
    const [loaded, setLoaded] = useState<LoadedDates | undefined>(undefined);

    useEffect(() => {
        let cancelled = false;
        void client.getJson(`list-dates?list=${list}&patient=${encodeURIComponent(patientId)}`).then((result) => {
            if (cancelled) {
                return;
            }
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

    return loaded !== undefined && loaded.forPatientId === patientId ? loaded.state : { status: 'loading' };
}

/**
 * Both medication cards from one MedicationRequest search plus the list end dates. Medications need
 * the end dates, so they show an error if those fail. Prescriptions only use them for list rows
 * marked Order, so they fall back to the FHIR rule instead of failing with them.
 */
export function useMedicationCards(client: ApiClient, patientId: string, today: string): MedicationCards {
    const requests = useBundleCard(client, patientId, 'MedicationRequest', keepAll);
    const dates = useListDates(client, patientId, 'medication');

    return useMemo((): MedicationCards => {
        if (requests.status !== 'ready') {
            return { medications: requests, prescriptions: requests };
        }
        if (dates.status === 'loading') {
            return { medications: dates, prescriptions: dates };
        }
        if (dates.status === 'error') {
            return {
                medications: dates,
                prescriptions: { status: 'ready', data: splitMedications(requests.data).prescriptions },
            };
        }
        const split = splitMedications(requests.data, dates.data, today);
        return {
            medications: { status: 'ready', data: split.medications },
            prescriptions: { status: 'ready', data: split.prescriptions },
        };
    }, [requests, dates, today]);
}
