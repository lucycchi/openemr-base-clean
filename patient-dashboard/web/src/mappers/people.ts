import type { HumanName, Practitioner, RelatedPerson } from 'fhir/r4';

/** Shown for a person FHIR cannot name (BM-028, BM-032). */
export const NAME_UNAVAILABLE = 'Name unavailable';

/**
 * Relative references to the person types the BFF proxy serves, with a FHIR id that cannot be a
 * dot-segment. Anything else (absolute URLs, other types, traversal) is never fetched.
 */
const READABLE_PERSON = /^(Practitioner|RelatedPerson)\/[A-Za-z0-9-][A-Za-z0-9.-]{0,63}$/;

export function isReadablePersonReference(reference: string): boolean {
    return READABLE_PERSON.test(reference);
}

function joinName(name: HumanName | undefined, style: 'last-first' | 'as-written'): string {
    if (name === undefined) {
        return '';
    }
    const given = (name.given ?? []).join(' ').trim();
    const family = name.family?.trim() ?? '';
    if (style === 'last-first') {
        return family !== '' && given !== '' ? `${family}, ${given}` : family || given || name.text?.trim() || '';
    }
    return name.text?.trim() || [given, family].filter((part) => part !== '').join(' ');
}

/** A provider reads "Last, First" as on the old dashboard; a related person as written. */
export function personName(resource: Practitioner | RelatedPerson): string {
    const style = resource.resourceType === 'Practitioner' ? 'last-first' : 'as-written';
    return joinName(resource.name?.[0], style) || NAME_UNAVAILABLE;
}
