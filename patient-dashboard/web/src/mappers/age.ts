/**
 * Age strings exactly as OpenEMR's header shows them. FHIR only supplies birthDate and
 * deceasedDateTime, so the rules are ported here (BM-007). Dates are "YYYY-MM-DD".
 */

export interface AgeSettings {
    /** The age_display_format global: 0 = years (months under 2), 1 = "Ny Nm Nd" up to the limit. */
    format: 0 | 1;
    /** The age_display_limit global, in years. */
    limitYears: number;
}

interface YMD {
    year: number;
    month: number;
    day: number;
}

function parseDate(date: string): YMD {
    const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(date);
    if (match === null) {
        throw new Error(`Expected a YYYY-MM-DD date, got "${date}"`);
    }
    return { year: Number(match[1]), month: Number(match[2]), day: Number(match[3]) };
}

/** Port of PatientService::getPatientAge: whole years, or "N month" up to and including 24 months. */
function patientAge(dob: YMD, now: YMD): string {
    const dayDiff = now.day - dob.day;
    const monthDiff = now.month - dob.month;
    const yearDiff = now.year - dob.year;
    let ageInMonths = now.year * 12 + now.month - (dob.year * 12 + dob.month);
    if (dayDiff < 0) {
        ageInMonths -= 1;
    }
    if (ageInMonths > 24) {
        let age = yearDiff;
        if ((monthDiff === 0 && dayDiff < 0) || monthDiff < 0) {
            age -= 1;
        }
        return String(age);
    }
    return `${ageInMonths} month`;
}

/** Port of PatientService::getPatientAgeYMD, including its fixed month lengths. */
function patientAgeYmd(dob: YMD, now: YMD): { decimalAge: number; text: string } {
    let daysInPreviousMonth: number;
    if ([5, 7, 10, 12].includes(now.month)) {
        daysInPreviousMonth = 30;
    } else if (now.month === 3) {
        daysInPreviousMonth = now.year % 4 === 0 ? 29 : 28;
    } else {
        daysInPreviousMonth = 31;
    }

    const today = now.year * 10000 + now.month * 100 + now.day;
    const birthdayThisYear = now.year * 10000 + dob.month * 100 + dob.day;
    let years: number;
    let months: number;
    if (today < birthdayThisYear) {
        years = now.year - dob.year - 1;
        months = now.day < dob.day ? 12 - dob.month + now.month - 1 : 12 - dob.month + now.month;
    } else {
        years = now.year - dob.year;
        months = now.day < dob.day ? now.month - dob.month - 1 : now.month - dob.month;
    }
    const days = now.day < dob.day ? daysInPreviousMonth - dob.day + now.day : now.day - dob.day;

    const decimalAge = Math.round((years + (months + days / 30) / 12) * 100) / 100;
    return { decimalAge, text: `${years}y ${months}m ${days}d` };
}

/** Port of PatientService::getPatientAgeDisplay, used for living patients. */
export function ageDisplay(birthDate: string, asOf: string, settings: AgeSettings): string {
    const dob = parseDate(birthDate);
    const now = parseDate(asOf);
    if (settings.format === 1) {
        const ymd = patientAgeYmd(dob, now);
        if (ymd.decimalAge <= settings.limitYears) {
            return ymd.text;
        }
    }
    return patientAge(dob, now);
}

/**
 * Port of oeFormatAge (format 0), used for "Age at death": whole years from 24 months, months below.
 * OpenEMR prints "4months" because of an operator-precedence bug; this returns the intended
 * "4 months" / "1 month" (BM-040, approved parity exception).
 */
export function ageAtDeath(birthDate: string, deathDate: string): string {
    const dob = parseDate(birthDate);
    const death = parseDate(deathDate);
    const dayDiff = death.day - dob.day;
    const monthDiff = death.month - dob.month;
    const yearDiff = death.year - dob.year;
    let ageInMonths = yearDiff * 12 + monthDiff;
    if (dayDiff < 0) {
        ageInMonths -= 1;
    }
    if (ageInMonths >= 24) {
        let age = yearDiff;
        if (monthDiff < 0 || (monthDiff === 0 && dayDiff < 0)) {
            age -= 1;
        }
        return String(age);
    }
    return `${ageInMonths} ${ageInMonths === 1 ? 'month' : 'months'}`;
}
