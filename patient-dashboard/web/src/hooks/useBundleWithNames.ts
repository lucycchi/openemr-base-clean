/**
 * The loader for cards that list people: the Care Team card and the Encounter History card. FHIR gives
 * those records only a pointer to each person (for example "Practitioner/42"), not their name, so after
 * loading the records and checking they belong to the patient on screen, this looks up each name once.
 *
 * Staff and facility names (Practitioner, Organization) come from the BFF's /api/display-names route;
 * family members and other contacts (RelatedPerson) are read from FHIR one by one. A name that cannot be
 * found reads "Name unavailable"; a lookup that failed reads "Name couldn't be loaded", so an outage
 * never looks like a person with no record.
 *
 * In: the API client, patient id, FHIR record type, a step that lists the people to look up, and the
 * card's map step. Out: a LoadState with the card's data, names included.
 */
import { useEffect, useState } from 'react';
import type { FhirResource, RelatedPerson } from 'fhir/r4';
import { assertBelongsTo } from '../api/client';
import type { ApiClient } from '../api/client';
import { displayName, NAME_NOT_LOADED } from '../mappers/people';
import type { LoadState } from './loadState';

/** A finished result, labelled with the patient it was loaded for, as in useBundleCard. */
interface Loaded<V> {
    forPatientId: string;
    state: LoadState<V>;
}

/**
 * A related person is read with the user's own token, and must belong to the patient. A read that fails
 * other than with a 404 reads "Name couldn't be loaded".
 */
async function readRelatedPerson(client: ApiClient, patientId: string, reference: string): Promise<string | undefined> {
    // Waits for the server to answer. Returning undefined means "no name to show" ("Name unavailable").
    const result = await client.getResource<RelatedPerson>(reference);
    if (!result.ok) {
        // A 404 means OpenEMR has no readable record ("Name unavailable"); anything else is an outage.
        return result.error.kind === 'http' && result.error.status === 404 ? undefined : NAME_NOT_LOADED;
    }
    // The record returned must be the one asked for...
    if (`${result.value.resourceType}/${result.value.id ?? ''}` !== reference) {
        return undefined;
    }
    // ...and must be linked to the patient on screen.
    if (result.value.resourceType !== 'RelatedPerson' || result.value.patient.reference !== `Patient/${patientId}`) {
        return undefined;
    }
    return displayName(result.value);
}

// Matches a pointer that starts with "Practitioner/" or "Organization/", i.e. a staff member or facility.
const STAFF = /^(Practitioner|Organization)\//;
// How many names to ask the BFF for in one request.
const BATCH = 50;

/**
 * Staff and facility names come from the BFF's server-only lookup, because OpenEMR's API lets only
 * administrators read Practitioner and Organization (Fable review F1). If the lookup fails, every
 * reference in that batch reads "Name couldn't be loaded", so an outage never looks like a person
 * with no record. Only names for the references asked for are kept.
 * The answer is a Map: a lookup table from each pointer to the name to show.
 */
async function readStaffNames(client: ApiClient, references: readonly string[]): Promise<Map<string, string>> {
    const names = new Map<string, string>();
    // Ask in groups of up to BATCH pointers at a time.
    for (let start = 0; start < references.length; start += BATCH) {
        const batch = references.slice(start, start + BATCH);
        // Builds a request such as "display-names?ref=Practitioner%2F1&ref=Practitioner%2F2".
        const result = await client.getJson(
            `display-names?${batch.map((ref) => `ref=${encodeURIComponent(ref)}`).join('&')}`,
        );
        // The answer should hold `names` (pointer -> name) and may hold `failed` (pointers whose lookup failed).
        const found =
            result.ok && typeof result.value === 'object' && result.value !== null
                ? (result.value as { names?: unknown }).names
                : undefined;
        if (typeof found !== 'object' || found === null) {
            // The whole request failed or was malformed: every name in this group reads "couldn't be loaded".
            batch.forEach((ref) => names.set(ref, NAME_NOT_LOADED));
            continue;
        }
        const failed = (result.ok ? (result.value as { failed?: unknown }).failed : undefined) ?? [];
        // For each pointer asked for: use its name if one came back, mark it "couldn't be loaded" if its
        // lookup failed, and otherwise leave it out, so the card shows "Name unavailable".
        for (const ref of batch) {
            const name = (found as Record<string, unknown>)[ref];
            if (typeof name === 'string' && name !== '') {
                names.set(ref, name);
            } else if (Array.isArray(failed) && failed.includes(ref)) {
                names.set(ref, NAME_NOT_LOADED);
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
    // The latest finished result, remembered between redraws and labelled with its patient.
    const [loaded, setLoaded] = useState<Loaded<V> | undefined>(undefined);

    // After the page draws, load the records and then the names. Reruns when the patient (or another
    // input in the list at the bottom) changes; the clean-up discards a previous patient's late answer.
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
                    // Sort the people to look up into staff/facilities and related persons.
                    const wanted = references(result.value);
                    const staff = wanted.filter((reference) => STAFF.test(reference));
                    const related = wanted.filter((reference) => reference.startsWith('RelatedPerson/'));
                    // Run both kinds of lookup at the same time (`Promise.all`) and wait for all of them.
                    const [names, relatedNames] = await Promise.all([
                        staff.length === 0 ? new Map<string, string>() : readStaffNames(client, staff),
                        Promise.all(related.map((reference) => readRelatedPerson(client, patientId, reference))),
                    ]);
                    // Add the related persons' names to the same lookup table as the staff names.
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

    // Only hand back a result labelled with the patient on screen now; anything else reads as loading.
    return loaded !== undefined && loaded.forPatientId === patientId ? loaded.state : { status: 'loading' };
}
