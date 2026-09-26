import type { Patient } from 'fhir/r4';
import { ageAtDeath, ageDisplay } from './age';
import type { AgeSettings } from './age';

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

export interface HeaderOptions {
    /** Today's local date, YYYY-MM-DD (injected for testability). */
    asOf: string;
    age: AgeSettings;
}

const SEX_LABELS: Record<string, string> = {
    female: 'Female',
    male: 'Male',
    other: 'Other',
    unknown: 'Unknown',
};

function displayName(patient: Patient): string {
    const name = patient.name?.find((n) => n.use === 'official') ?? patient.name?.[0];
    const parts = [...(name?.given ?? []), name?.family ?? ''].map((part) => part.trim()).filter((part) => part !== '');
    return parts.length > 0 ? parts.join(' ') : 'Name not recorded';
}

function medicalRecordNumber(patient: Patient): string {
    const pt = patient.identifier?.find((identifier) =>
        identifier.type?.coding?.some((coding) => coding.code === 'PT'),
    );
    return pt?.value ?? '';
}

export function mapHeader(patient: Patient, options: HeaderOptions): HeaderView {
    const deathDate = patient.deceasedDateTime?.slice(0, 10);
    const birthDate = patient.birthDate;

    let dobLine = 'DOB: not recorded';
    if (birthDate !== undefined) {
        dobLine =
            deathDate === undefined
                ? `DOB: ${birthDate} Age: ${ageDisplay(birthDate, options.asOf, options.age)}`
                : `DOB: ${birthDate} Age at death: ${ageAtDeath(birthDate, deathDate)}`;
    }

    return {
        id: patient.id ?? '',
        name: displayName(patient),
        mrn: medicalRecordNumber(patient),
        dobLine,
        sex: SEX_LABELS[patient.gender ?? 'unknown'] ?? 'Unknown',
        status: deathDate === undefined ? 'Active' : `Deceased (${deathDate})`,
    };
}
