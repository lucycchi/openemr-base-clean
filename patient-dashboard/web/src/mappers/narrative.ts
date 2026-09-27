/**
 * Two small helpers for reading names out of FHIR records safely. In: a record's narrative (a short
 * human-readable HTML summary FHIR records may carry) or its list of codings (coded names from a
 * terminology such as SNOMED or RxNorm). Out: plain text. Used by the allergy and medication rules.
 */
import type { Coding } from 'fhir/r4';

/**
 * Plain text of a FHIR narrative div. OpenEMR builds narratives by string concatenation without
 * escaping (BM-009), so a div may contain live markup. It is parsed and read as text only, never
 * rendered as HTML.
 */
export function narrativeText(div: string | undefined): string {
    if (div === undefined || div.trim() === '') {
        return '';
    }
    const document = new DOMParser().parseFromString(div, 'text/html');
    // Take just the words, collapse every run of spaces, tabs or line breaks into one space, and trim the ends.
    return (document.body.textContent ?? '').replace(/\s+/g, ' ').trim();
}

/** The code system OpenEMR uses to say "no real value here" (it fills in "Unknown" for missing data). */
const DATA_ABSENT_SYSTEM = 'http://terminology.hl7.org/CodeSystem/data-absent-reason';

/** The first coding display that is real data, not OpenEMR's data-absent "Unknown". */
export function realCodingDisplay(codings: readonly Coding[] | undefined): string {
    // The first coding that is not a "data absent" placeholder and has a non-blank name.
    const coding = (codings ?? []).find(
        (c) => c.system !== DATA_ABSENT_SYSTEM && c.display !== undefined && c.display.trim() !== '',
    );
    return coding?.display?.trim() ?? '';
}
