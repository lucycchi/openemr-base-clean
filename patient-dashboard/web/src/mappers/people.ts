/**
 * Names of people and facilities. In: a Practitioner (a clinician or staff user), RelatedPerson (a
 * family member or other contact) or Organization (a facility) record, or a reference to one such as
 * "Practitioner/abc". Out: the name to show, or a fixed placeholder when it cannot be named, and a
 * check of which references are safe to look up. Used by the Care Team and Encounter history cards.
 */
import type { HumanName, Organization, Practitioner, RelatedPerson } from 'fhir/r4';

/** Shown for a person or facility FHIR cannot name (BM-028, BM-032, BM-045). */
export const NAME_UNAVAILABLE = 'Name unavailable';

/** Shown when the staff-name lookup itself failed, so an outage never looks like "no record". */
export const NAME_NOT_LOADED = "Name couldn't be loaded";

/**
 * Relative references to the named types the BFF proxy serves, with a FHIR id that cannot be a
 * dot-segment. Anything else (absolute URLs, other types, traversal) is never fetched.
 * In words, the pattern matches: exactly "Practitioner/", "RelatedPerson/" or "Organization/",
 * then an id of 1 to 64 letters, digits, dashes or dots that does not start with a dot, and nothing else.
 * (The BFF proxy is the dashboard's own small server, which fetches OpenEMR records on the page's behalf.)
 */
const READABLE_NAMED = /^(Practitioner|RelatedPerson|Organization)\/[A-Za-z0-9-][A-Za-z0-9.-]{0,63}$/;

/** True when a reference is one of the three named kinds and safe to ask the server to look up. */
export function isReadableNameReference(reference: string): boolean {
    return READABLE_NAMED.test(reference);
}

/**
 * Joins the parts of a person's name. 'last-first' gives "Family, Given" (falling back to whichever
 * part exists, then the name as written); 'as-written' gives the full written name, else "Given Family".
 * The style can only be one of those two words.
 */
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

/** A provider reads "Last, First" as on the old dashboard; a related person as written; a facility by its name. */
export function displayName(resource: Practitioner | RelatedPerson | Organization): string {
    if (resource.resourceType === 'Organization') {
        return resource.name?.trim() || NAME_UNAVAILABLE;
    }
    // People: use the first recorded name; if it comes out blank, show "Name unavailable".
    const style = resource.resourceType === 'Practitioner' ? 'last-first' : 'as-written';
    return joinName(resource.name?.[0], style) || NAME_UNAVAILABLE;
}
