/**
 * The "Edit in OpenEMR" link on the Allergies, Medical Problems, Medications and Care Team cards (ARC-06).
 * OpenEMR's write API cannot record those cards' clinical fields (an allergy's reaction and severity, a
 * problem's comments, a medication's dosage instructions) and cannot change a care team at all, so editing
 * them in the dashboard would save incomplete records. The link opens OpenEMR's own chart page instead.
 *
 * It goes through the dashboard's server (server/openemrLink.ts), which looks up OpenEMR's patient number
 * and redirects. It opens in a new tab so the dashboard stays where it was; `noopener` also stops OpenEMR's
 * sign-in script from reaching back into this tab.
 */

/** The short note shown on each card and as the link's hover text. */
export const EDIT_IN_OPENEMR_NOTE =
    'Changes are made in OpenEMR. Opens in a new tab; you may need to sign in, then click again.';

export function EditInOpenEmr({ patientId }: { patientId: string }) {
    return (
        <a
            className="btn btn-link btn-sm p-0"
            href={`/openemr/patient/${encodeURIComponent(patientId)}`}
            target="_blank"
            rel="noopener noreferrer"
            title={EDIT_IN_OPENEMR_NOTE}
        >
            Edit in OpenEMR
        </a>
    );
}

/** The same note as visible text at the foot of a card, since hover text is invisible on touch screens. */
export function EditInOpenEmrNote() {
    return <p className="small text-muted mb-0 mt-1">{EDIT_IN_OPENEMR_NOTE}</p>;
}
