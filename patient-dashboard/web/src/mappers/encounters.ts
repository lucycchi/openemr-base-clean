/**
 * Encounter history card rules. In: the patient's FHIR Encounter records (one per visit, with a start
 * time, reasons and the people involved) and the provider names that could be looked up. Out: one row
 * per visit (date, reason, provider), newest first, as the old Visit History page ordered them. It
 * also lists which provider records need looking up.
 */
import type { Encounter, EncounterParticipant } from 'fhir/r4';
import { isReadableNameReference, NAME_UNAVAILABLE } from './people';

/** One row of the Encounter history card. See modules/extra-encounter-history.md. */
export interface EncounterView {
    id: string;
    /** period.start as written (YYYY-MM-DD); '' when absent. */
    date: string;
    /** Every recorded reason for the visit, joined with "; "; '' when none. */
    reason: string;
    /** "Last, First", or NAME_UNAVAILABLE when FHIR has no readable provider (BM-032). */
    provider: string;
}

/**
 * The primary performer (PPRF) only. The old page shows form_encounter.provider_id, and OpenEMR
 * leaves that provider out of FHIR when they have no NPI (BM-032), so another participant such as a
 * referrer must never stand in for them: no PPRF means "Name unavailable".
 * Returns the first participant who points to a person and is typed PPRF, or nothing if there is none
 * (`| undefined` in the result type means "or nothing").
 */
function providerOf(encounter: Encounter): EncounterParticipant | undefined {
    return (encounter.participant ?? []).find(
        (p) =>
            p.individual?.reference !== undefined &&
            p.type?.some((type) => type.coding?.some((coding) => coding.code === 'PPRF')),
    );
}

/**
 * The primary performer's reference (such as "Practitioner/abc"), but only when it points to a
 * Practitioner and is safe to look up; otherwise nothing, which the card shows as "Name unavailable".
 */
function providerReference(encounter: Encounter): string | undefined {
    const reference = providerOf(encounter)?.individual?.reference;
    return reference !== undefined && reference.startsWith('Practitioner/') && isReadableNameReference(reference)
        ? reference
        : undefined;
}

/** Every provider the card should read for a name, each once. */
export function providerReferences(encounters: readonly Encounter[]): string[] {
    // Take each visit's provider reference, drop the visits that have none, and remove repeats (a Set
    // holds each value once). `...` spreads the Set back out into an ordinary list.
    return [
        ...new Set(
            encounters.map(providerReference).filter((reference): reference is string => reference !== undefined),
        ),
    ];
}

/**
 * Maps encounters newest first by full start date and time, as the old Visit History page sorts
 * (BM-034). Ties keep the API order: FHIR sorts by eid descending (EncounterService.php:322),
 * which is the old page's id descending. An encounter with no start goes last. Only the displayed
 * date drops the time.
 * The date is kept as written: OpenEMR sends the stored local date with a +00:00 offset, so
 * converting it to the browser's zone would move a midnight visit to the day before.
 */
export function mapEncounters(encounters: readonly Encounter[], names: ReadonlyMap<string, string>): EncounterView[] {
    // Three steps. 1 (.map): build each card row, remembering the visit's original position and its
    // start time as a number (for sorting), or nothing when the start is missing or unreadable.
    // 2 (.sort): order the rows. A later start comes first; a visit with no start goes to the end;
    // visits with the same start keep the order the API sent them in.
    // 3 (the last .map): drop the sorting helpers and keep just the card rows.
    return encounters
        .map((encounter, index) => {
            const reference = providerReference(encounter);
            const start = Date.parse(encounter.period?.start ?? '');
            return {
                index,
                start: Number.isNaN(start) ? undefined : start,
                view: {
                    id: encounter.id ?? String(index),
                    // `slice(0, 10)` keeps just the YYYY-MM-DD part and drops the time.
                    date: encounter.period?.start?.slice(0, 10) ?? '',
                    // Each reason's text, or failing that its first coded name; blanks dropped; joined by "; ".
                    reason: (encounter.reasonCode ?? [])
                        .map(
                            (reason) =>
                                reason.text?.trim() ||
                                reason.coding?.find((coding) => coding.display?.trim())?.display?.trim() ||
                                '',
                        )
                        .filter((text) => text !== '')
                        .join('; '),
                    // The looked-up name, or "Name unavailable" when there is no provider or no name.
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
            return a.index - b.index;
        })
        .map(({ view }) => view);
}
