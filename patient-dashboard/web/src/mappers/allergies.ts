import type { AllergyIntolerance } from 'fhir/r4';
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
export function mapAllergies(resources: readonly AllergyIntolerance[]): AllergyView[] {
    return resources.filter(isActive).map((allergy) => ({
        id: allergy.id ?? '',
        name: allergyName(allergy),
        reaction: reactionText(allergy),
        risk: RISK_LABELS[allergy.criticality ?? ''] ?? '',
        high: allergy.criticality === 'high',
    }));
}
