/**
 * Allergies card rules. In: the patient's FHIR AllergyIntolerance records (FHIR's name for an
 * allergy or intolerance entry) and, when available, the matching rows of OpenEMR's own allergy list
 * from the Standard REST API. Out: the list of rows the Allergies card draws, each with a name,
 * reaction, risk label and a flag for highlighting. Which entries appear follows the old card's rule.
 */
import type { AllergyIntolerance } from 'fhir/r4';
import { isCurrentListRow } from '../api/listDates';
import type { ListDates } from '../api/listDates';
import { narrativeText, realCodingDisplay } from './narrative';

/** One row of the allergies card. See modules/allergies.md and the Gate 2 decisions. */
export interface AllergyView {
    /** The allergy's record id, used to tell rows apart. */
    id: string;
    name: string;
    /** Reaction manifestation(s), comma-separated; '' when none recorded. */
    reaction: string;
    /** FHIR criticality as a label (BM-011): 'Low risk', 'High risk', 'Unable to assess', or ''. */
    risk: string;
    /** Highlighted like the old severe / life-threatening / fatal entries. */
    high: boolean;
}

/** FHIR's criticality codes (how dangerous a future reaction could be) turned into the words shown. */
const RISK_LABELS: Record<string, string> = {
    low: 'Low risk',
    high: 'High risk',
    'unable-to-assess': 'Unable to assess',
};

/** The allergy's name from FHIR alone, used when OpenEMR's list gives no title for it. */
function allergyName(allergy: AllergyIntolerance): string {
    // Coded display first; an uncoded allergy's name is only in the narrative (BM-010).
    // Each `||` means "if the one before is blank, fall back to the next": coded name, then the free
    // text, then the narrative (FHIR's human-readable summary), then "Unnamed allergy".
    // `?.` means "if this part is missing, stop and treat the whole thing as missing" instead of failing.
    return (
        realCodingDisplay(allergy.code?.coding) ||
        allergy.code?.text?.trim() ||
        narrativeText(allergy.text?.div) ||
        'Unnamed allergy'
    );
}

/** Every recorded reaction (for example "Hives, Wheezing"), in the order they were entered. */
function reactionText(allergy: AllergyIntolerance): string {
    // Gather the manifestations (what the reaction looked like) from every recorded reaction.
    // `?? []` means "if there are no reactions at all, use an empty list".
    const manifestations = (allergy.reaction ?? []).flatMap((reaction) => reaction.manifestation);
    // Turn each into its coded name or free text, drop the blank ones, and join with commas.
    return manifestations
        .map((m) => realCodingDisplay(m.coding) || m.text?.trim() || '')
        .filter((text) => text !== '')
        .join(', ');
}

/** True when FHIR's clinicalStatus (active, inactive or resolved) says "active". */
function isActive(allergy: AllergyIntolerance): boolean {
    return allergy.clinicalStatus?.coding?.some((coding) => coding.code === 'active') ?? false;
}

/**
 * Maps allergies for the card. With the standard API's list dates, the old card's rule decides
 * (hide resolved or past-ended, keep future-ended), because FHIR's clinicalStatus is wrong both ways
 * (BM-016, BM-047). Without a list row for an allergy, only FHIR-active ones are shown. Entry order
 * is kept (BM-014). `now` is the local date and time, "YYYY-MM-DD HH:MM:SS".
 * The list dates are a Map: a lookup table from an allergy's id to its OpenEMR list row. The `= ...`
 * after each input gives the value used when the caller leaves it out (an empty table, no time).
 */
export function mapAllergies(
    resources: readonly AllergyIntolerance[],
    listDates: ReadonlyMap<string, ListDates> = new Map(),
    now = '',
): AllergyView[] {
    // Decides whether one allergy is shown. (`(x) => ...` is a small unnamed function taking x.)
    const isShown = (allergy: AllergyIntolerance): boolean => {
        const row = listDates.get(allergy.id ?? '');
        // No OpenEMR list row: trust FHIR's active flag. Otherwise apply the old card's rule.
        return row === undefined ? isActive(allergy) : isCurrentListRow(row, now);
    };
    // Keep only the allergies that should show, in their original order, and turn each into a card row.
    return resources.filter(isShown).map((allergy) => ({
        id: allergy.id ?? '',
        // The old card shows lists.title; FHIR's coding display is the code's description (Opus review 4).
        name: listDates.get(allergy.id ?? '')?.title?.trim() || allergyName(allergy),
        reaction: reactionText(allergy),
        // An unknown or missing criticality gives no label.
        risk: RISK_LABELS[allergy.criticality ?? ''] ?? '',
        high: allergy.criticality === 'high',
    }));
}
