/**
 * Patient header rules. In: the patient's FHIR Patient record, today's date and the site's age and
 * date settings. Out: the few pieces of text the identity bar at the top of the dashboard shows:
 * name, record number, a "DOB: ... Age: ..." line, sex, and whether the patient is active or deceased.
 */
import type { Patient } from 'fhir/r4';
import { ageAtDeath, ageDisplay } from './age';
import type { AgeSettings } from './age';
import { formatShortDate } from './dates';
import type { DateDisplayFormat } from './dates';

/** What the header shows. See modules/header.md and the Gate 2 header decision. */
export interface HeaderView {
    id: string;
    name: string;
    /** OpenEMR's pubpid (the identifier with type PT), shown as "(36)". */
    mrn: string;
    /** "DOB: 1958-03-14 Age: 68" or "DOB: … Age at death: 93", as the old identity bar shows it. */
    dobLine: string;
    sex: string;
    /** "Deceased (YYYY-MM-DD)" or "Active". Never from Patient.active, which OpenEMR always sets to true (BM-005). */
    status: string;
}

/** The settings mapHeader needs besides the patient record. A `?` after a name means it may be left out. */
export interface HeaderOptions {
    /** Today's local date, YYYY-MM-DD (injected for testability). */
    asOf: string;
    age: AgeSettings;
    /** The site date format for DOB and date of death (default 0, Y-m-d). */
    dateFormat?: DateDisplayFormat;
}

/** FHIR's gender codes turned into the words shown after "Sex:". */
const SEX_LABELS: Record<string, string> = {
    female: 'Female',
    male: 'Male',
    other: 'Other',
    unknown: 'Unknown',
};

/** "Given Family": the official name if one is marked, otherwise the first name recorded. */
function displayName(patient: Patient): string {
    const name = patient.name?.find((n) => n.use === 'official') ?? patient.name?.[0];
    // All given names then the family name, each trimmed, blanks dropped.
    const parts = [...(name?.given ?? []), name?.family ?? ''].map((part) => part.trim()).filter((part) => part !== '');
    return parts.length > 0 ? parts.join(' ') : 'Name not recorded';
}

/** The record number clinicians know the patient by: the identifier whose type code is "PT"; '' if none. */
function medicalRecordNumber(patient: Patient): string {
    const pt = patient.identifier?.find((identifier) =>
        identifier.type?.coding?.some((coding) => coding.code === 'PT'),
    );
    return pt?.value ?? '';
}

/**
 * Builds the header text for one patient. The patient counts as deceased only when a real date of
 * death on or before today is recorded; then the DOB line gives the age at death instead of today's age.
 */
export function mapHeader(patient: Patient, options: HeaderOptions): HeaderView {
    // Deceased only for a real date on or before today, as is_patient_deceased (patient.inc.php:1643-1654):
    // a zero date or a future date leaves the patient active.
    // `slice(0, 10)` keeps the YYYY-MM-DD part of the recorded date and time of death.
    const recorded = patient.deceasedDateTime?.slice(0, 10);
    // The pattern checks the text is exactly a YYYY-MM-DD date; "0000..." is OpenEMR's empty date.
    // Dates in this form compare correctly as text, so `<=` means "on or before today".
    const deathDate =
        recorded !== undefined &&
        /^\d{4}-\d{2}-\d{2}$/.test(recorded) &&
        !recorded.startsWith('0000') &&
        recorded <= options.asOf
            ? recorded
            : undefined;
    const birthDate = patient.birthDate;

    // A small helper: write a date in the site's format (Y-m-d when none is set).
    const shown = (date: string) => formatShortDate(date, options.dateFormat ?? 0);

    let dobLine = 'DOB: not recorded';
    if (birthDate !== undefined) {
        dobLine =
            deathDate === undefined
                ? `DOB: ${shown(birthDate)} Age: ${ageDisplay(birthDate, options.asOf, options.age)}`
                : `DOB: ${shown(birthDate)} Age at death: ${ageAtDeath(birthDate, deathDate)}`;
    }

    return {
        id: patient.id ?? '',
        name: displayName(patient),
        mrn: medicalRecordNumber(patient),
        dobLine,
        // A missing or unrecognised gender reads "Unknown".
        sex: SEX_LABELS[patient.gender ?? 'unknown'] ?? 'Unknown',
        status: deathDate === undefined ? 'Active' : `Deceased (${shown(deathDate)})`,
    };
}
