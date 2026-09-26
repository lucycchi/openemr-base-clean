import type { Encounter, EncounterParticipant } from 'fhir/r4';
import { isReadableNameReference, NAME_UNAVAILABLE } from './people';

/** One row of the Encounter history card. See modules/extra-encounter-history.md. */
export interface EncounterView {
    id: string;
    /** period.start as written (YYYY-MM-DD); '' when absent. */
    date: string;
    reason: string;
    /** "Last, First", or NAME_UNAVAILABLE when FHIR has no readable provider (BM-032). */
    provider: string;
}

/**
 * The primary performer (PPRF) only. The old page shows form_encounter.provider_id, and OpenEMR
 * leaves that provider out of FHIR when they have no NPI (BM-032), so another participant such as a
 * referrer must never stand in for them: no PPRF means "Name unavailable".
 */
function providerOf(encounter: Encounter): EncounterParticipant | undefined {
    return (encounter.participant ?? []).find(
        (p) =>
            p.individual?.reference !== undefined &&
            p.type?.some((type) => type.coding?.some((coding) => coding.code === 'PPRF')),
    );
}

function providerReference(encounter: Encounter): string | undefined {
    const reference = providerOf(encounter)?.individual?.reference;
    return reference !== undefined && reference.startsWith('Practitioner/') && isReadableNameReference(reference)
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
 * Maps encounters newest first by full start date and time, as the old Visit History page sorts
 * (BM-034). FHIR returns them oldest first; ties keep the reverse of the API order (the old page's
 * id descending), and an encounter with no start goes last. Only the displayed date drops the time.
 * The date is kept as written: OpenEMR sends the stored local date with a +00:00 offset, so
 * converting it to the browser's zone would move a midnight visit to the day before.
 */
export function mapEncounters(encounters: readonly Encounter[], names: ReadonlyMap<string, string>): EncounterView[] {
    return encounters
        .map((encounter, index) => {
            const reference = providerReference(encounter);
            const start = Date.parse(encounter.period?.start ?? '');
            return {
                index,
                start: Number.isNaN(start) ? undefined : start,
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
            if (a.start !== b.start) {
                if (a.start === undefined) return 1;
                if (b.start === undefined) return -1;
                return b.start - a.start;
            }
            return b.index - a.index;
        })
        .map(({ view }) => view);
}
