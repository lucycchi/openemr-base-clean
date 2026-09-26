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
    return (document.body.textContent ?? '').replace(/\s+/g, ' ').trim();
}

const DATA_ABSENT_SYSTEM = 'http://terminology.hl7.org/CodeSystem/data-absent-reason';

/** The first coding display that is real data, not OpenEMR's data-absent "Unknown". */
export function realCodingDisplay(codings: readonly Coding[] | undefined): string {
    const coding = (codings ?? []).find(
        (c) => c.system !== DATA_ABSENT_SYSTEM && c.display !== undefined && c.display.trim() !== '',
    );
    return coding?.display?.trim() ?? '';
}
