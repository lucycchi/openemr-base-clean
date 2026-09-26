import { useEffect, useState } from 'react';
import type { FhirResource, RelatedPerson } from 'fhir/r4';
import { assertBelongsTo } from '../api/client';
import type { ApiClient } from '../api/client';
import { displayName, NAME_NOT_LOADED } from '../mappers/people';
import type { LoadState } from './loadState';

interface Loaded<V> {
    forPatientId: string;
    state: LoadState<V>;
}

/** A related person is read with the user's own token, and must belong to the patient. */
async function readRelatedPerson(client: ApiClient, patientId: string, reference: string): Promise<string | undefined> {
    const result = await client.getResource<RelatedPerson>(reference);
    if (!result.ok || `${result.value.resourceType}/${result.value.id ?? ''}` !== reference) {
        return undefined;
    }
    if (result.value.resourceType !== 'RelatedPerson' || result.value.patient.reference !== `Patient/${patientId}`) {
        return undefined;
    }
    return displayName(result.value);
}

const STAFF = /^(Practitioner|Organization)\//;
const BATCH = 50;

/**
 * Staff and facility names come from the BFF's server-only lookup, because OpenEMR's API lets only
 * administrators read Practitioner and Organization (Fable review F1). If the lookup fails, every
 * reference in that batch reads "Name couldn't be loaded", so an outage never looks like a person
 * with no record. Only names for the references asked for are kept.
 */
async function readStaffNames(client: ApiClient, references: readonly string[]): Promise<Map<string, string>> {
    const names = new Map<string, string>();
    for (let start = 0; start < references.length; start += BATCH) {
        const batch = references.slice(start, start + BATCH);
        const result = await client.getJson(
            `display-names?${batch.map((ref) => `ref=${encodeURIComponent(ref)}`).join('&')}`,
        );
        const found =
            result.ok && typeof result.value === 'object' && result.value !== null
                ? (result.value as { names?: unknown }).names
                : undefined;
        if (typeof found !== 'object' || found === null) {
            batch.forEach((ref) => names.set(ref, NAME_NOT_LOADED));
            continue;
        }
        for (const ref of batch) {
            const name = (found as Record<string, unknown>)[ref];
            if (typeof name === 'string' && name !== '') {
                names.set(ref, name);
            }
        }
    }
    return names;
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
                    const staff = wanted.filter((reference) => STAFF.test(reference));
                    const related = wanted.filter((reference) => reference.startsWith('RelatedPerson/'));
                    const [names, relatedNames] = await Promise.all([
                        staff.length === 0 ? new Map<string, string>() : readStaffNames(client, staff),
                        Promise.all(related.map((reference) => readRelatedPerson(client, patientId, reference))),
                    ]);
                    related.forEach((reference, index) => {
                        const name = relatedNames[index];
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
