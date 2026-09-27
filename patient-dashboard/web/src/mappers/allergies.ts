import type { AllergyIntolerance } from 'fhir/r4';
import { isCurrentListRow } from '../api/listDates';
import type { ListDates } from '../api/listDates';
import { narrativeText, realCodingDisplay } from './narrative';

/** One row of the allergies card. See modules/allergies.md and the Gate 2 decisions. */
export interface AllergyView {
    id: string;
    name: string;
    /** Reaction manifestation(s), comma-separated; '' when none recorded. */
    reaction: string;
    /** FHIR criticality as a label (BM-011): 'Low risk', 'High risk', 'Unable to assess', or ''. */
    risk: string;
    /** Highlighted like the old severe / life-threatening / fatal entries. */
    high: boolean;
}

const RISK_LABELS: Record<string, string> = {
    low: 'Low risk',
    high: 'High risk',
    'unable-to-assess': 'Unable to assess',
};

function allergyName(allergy: AllergyIntolerance): string {
    // Coded display first; an uncoded allergy's name is only in the narrative (BM-010).
    return (
        realCodingDisplay(allergy.code?.coding) ||
        allergy.code?.text?.trim() ||
        narrativeText(allergy.text?.div) ||
        'Unnamed allergy'
    );
}

function reactionText(allergy: AllergyIntolerance): string {
    const manifestations = (allergy.reaction ?? []).flatMap((reaction) => reaction.manifestation);
    return manifestations
        .map((m) => realCodingDisplay(m.coding) || m.text?.trim() || '')
        .filter((text) => text !== '')
        .join(', ');
}

function isActive(allergy: AllergyIntolerance): boolean {
    return allergy.clinicalStatus?.coding?.some((coding) => coding.code === 'active') ?? false;
}

/** Active allergies in API order (the old card does not sort; BM-014), inactive ones hidden (BM-016). */
/**
 * Maps allergies for the card. With the standard API's list dates, the old card's rule decides
 * (hide resolved or past-ended, keep future-ended), because FHIR's clinicalStatus is wrong both ways
 * (BM-016, BM-047). Without a list row for an allergy, only FHIR-active ones are shown. Entry order
 * is kept (BM-014). `now` is the local date and time, "YYYY-MM-DD HH:MM:SS".
 */
export function mapAllergies(
    resources: readonly AllergyIntolerance[],
    listDates: ReadonlyMap<string, ListDates> = new Map(),
    now = '',
): AllergyView[] {
    const isShown = (allergy: AllergyIntolerance): boolean => {
        const row = listDates.get(allergy.id ?? '');
        return row === undefined ? isActive(allergy) : isCurrentListRow(row, now);
    };
    return resources.filter(isShown).map((allergy) => ({
        id: allergy.id ?? '',
        // The old card shows lists.title; FHIR's coding display is the code's description (Opus review 4).
        name: listDates.get(allergy.id ?? '')?.title?.trim() || allergyName(allergy),
        reaction: reactionText(allergy),
        risk: RISK_LABELS[allergy.criticality ?? ''] ?? '',
        high: allergy.criticality === 'high',
    }));
}
