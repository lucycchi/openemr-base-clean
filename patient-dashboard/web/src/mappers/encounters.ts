import type { Encounter, EncounterParticipant } from 'fhir/r4';
import { isReadablePersonReference, NAME_UNAVAILABLE } from './people';

/** One row of the Encounter history card. See modules/extra-encounter-history.md. */
export interface EncounterView {
    id: string;
    /** period.start as written (YYYY-MM-DD); '' when absent. */
    date: string;
    reason: string;
    /** "Last, First", or NAME_UNAVAILABLE when FHIR has no readable provider (BM-032). */
    provider: string;
}

/** The primary performer (PPRF), else the first participant with an individual. */
function providerOf(encounter: Encounter): EncounterParticipant | undefined {
    const withIndividual = (encounter.participant ?? []).filter((p) => p.individual?.reference !== undefined);
    return (
        withIndividual.find((p) => p.type?.some((type) => type.coding?.some((coding) => coding.code === 'PPRF'))) ??
        withIndividual[0]
    );
}

function providerReference(encounter: Encounter): string | undefined {
    const reference = providerOf(encounter)?.individual?.reference;
    return reference !== undefined && reference.startsWith('Practitioner/') && isReadablePersonReference(reference)
        ? reference
        : undefined;
}

/** Every provider the card should read for a name, each once. */
export function providerReferences(encounters: readonly Encounter[]): string[] {
    return [
        ...new Set(
            encounters.map(providerReference).filter((reference): reference is string => reference !== undefined),
        ),
    ];
}

/**
 * Maps encounters newest first, as the old Visit History page lists them (BM-034). FHIR returns them
 * oldest first; ties keep the reverse of the API order, and an encounter with no start goes last.
 * The date is kept as written: OpenEMR sends the stored local date with a +00:00 offset, so
 * converting it to the browser's zone would move a midnight visit to the day before.
 */
export function mapEncounters(encounters: readonly Encounter[], names: ReadonlyMap<string, string>): EncounterView[] {
    return encounters
        .map((encounter, index) => {
            const reference = providerReference(encounter);
            return {
                index,
                view: {
                    id: encounter.id ?? String(index),
                    date: encounter.period?.start?.slice(0, 10) ?? '',
                    reason: (encounter.reasonCode ?? [])
                        .map(
                            (reason) =>
                                reason.text?.trim() ||
                                reason.coding?.find((coding) => coding.display?.trim())?.display?.trim() ||
                                '',
                        )
                        .filter((text) => text !== '')
                        .join('; '),
                    provider: (reference === undefined ? undefined : names.get(reference)) ?? NAME_UNAVAILABLE,
                },
            };
        })
        .sort((a, b) => {
            if (a.view.date !== b.view.date) {
                if (a.view.date === '') return 1;
                if (b.view.date === '') return -1;
                return a.view.date < b.view.date ? 1 : -1;
            }
            return b.index - a.index;
        })
        .map(({ view }) => view);
}
