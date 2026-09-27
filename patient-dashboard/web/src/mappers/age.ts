/**
 * Age strings exactly as OpenEMR's header shows them. FHIR only supplies birthDate and
 * deceasedDateTime, so the rules are ported here (BM-007). Dates are "YYYY-MM-DD".
 *
 * In plain terms: this file takes a date of birth (and either today's date or a date of death) and
 * returns the age text the patient header prints, for example "68", "18 month" or "1y 3m 12d". It
 * serves the "Age:" and "Age at death:" parts of the header's DOB line (see header.ts). The small
 * spelling quirks ("18 month" without an "s") are copied on purpose so the new header matches the old.
 */

/**
 * The two site settings (OpenEMR "globals") that decide how an age is written. An "interface" is just
 * a description of the shape of a piece of data: here, an object with these two named fields.
 */
export interface AgeSettings {
    /**
     * The age_display_format global: 0 = years (months under 2), 1 = "Ny Nm Nd" up to the limit.
     * `0 | 1` means the value can only ever be the number 0 or the number 1.
     */
    format: 0 | 1;
    /** The age_display_limit global, in years. */
    limitYears: number;
}

/** A date split into its three numbers, so the age arithmetic can work on each part separately. */
interface YMD {
    year: number;
    month: number;
    day: number;
}

/** Splits "YYYY-MM-DD" (anything after the day, such as a time, is ignored) into year, month and day. */
function parseDate(date: string): YMD {
    // A regular expression (a text pattern): four digits, a dash, two digits, a dash, two digits, at
    // the very start of the text. The brackets capture the year, month and day so they can be read back.
    const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(date);
    if (match === null) {
        // Not a date at all: stop with an error rather than show a made-up age.
        throw new Error(`Expected a YYYY-MM-DD date, got "${date}"`);
    }
    return { year: Number(match[1]), month: Number(match[2]), day: Number(match[3]) };
}

/** Port of PatientService::getPatientAge: whole years, or "N month" up to and including 24 months. */
function patientAge(dob: YMD, now: YMD): string {
    const dayDiff = now.day - dob.day;
    const monthDiff = now.month - dob.month;
    const yearDiff = now.year - dob.year;
    // Whole calendar months between the two dates, less one if this month's "birth day" has not come yet.
    let ageInMonths = now.year * 12 + now.month - (dob.year * 12 + dob.month);
    if (dayDiff < 0) {
        ageInMonths -= 1;
    }
    if (ageInMonths > 24) {
        // Over two years old: whole years, less one if this year's birthday has not happened yet.
        let age = yearDiff;
        if ((monthDiff === 0 && dayDiff < 0) || monthDiff < 0) {
            age -= 1;
        }
        return String(age);
    }
    // Two years or younger: the age in months, written "N month" exactly as OpenEMR writes it.
    return `${ageInMonths} month`;
}

/**
 * Port of PatientService::getPatientAgeYMD, including its fixed month lengths.
 * Gives the age as years, months and days ("1y 3m 12d") plus the same age as a decimal number of
 * years (1.29), which ageDisplay uses to decide whether this detailed form should be shown at all.
 */
function patientAgeYmd(dob: YMD, now: YMD): { decimalAge: number; text: string } {
    // How many days the month before the current one had, used when counting back across a month
    // boundary. The rough leap-year test (every fourth year) is OpenEMR's own and is kept to match it.
    let daysInPreviousMonth: number;
    if ([5, 7, 10, 12].includes(now.month)) {
        daysInPreviousMonth = 30;
    } else if (now.month === 3) {
        daysInPreviousMonth = now.year % 4 === 0 ? 29 : 28;
    } else {
        daysInPreviousMonth = 31;
    }

    // Dates turned into single numbers such as 20260926, so "has the birthday passed?" is a plain comparison.
    const today = now.year * 10000 + now.month * 100 + now.day;
    const birthdayThisYear = now.year * 10000 + dob.month * 100 + dob.day;
    let years: number;
    let months: number;
    if (today < birthdayThisYear) {
        // Birthday still to come this year. (`a ? b : c` means "if a, then b, otherwise c".)
        years = now.year - dob.year - 1;
        months = now.day < dob.day ? 12 - dob.month + now.month - 1 : 12 - dob.month + now.month;
    } else {
        years = now.year - dob.year;
        months = now.day < dob.day ? now.month - dob.month - 1 : now.month - dob.month;
    }
    const days = now.day < dob.day ? daysInPreviousMonth - dob.day + now.day : now.day - dob.day;

    // Years plus the months and days as a fraction of a year (a month counted as 30 days), to 2 decimals.
    const decimalAge = Math.round((years + (months + days / 30) / 12) * 100) / 100;
    return { decimalAge, text: `${years}y ${months}m ${days}d` };
}

/**
 * Port of PatientService::getPatientAgeDisplay, used for living patients.
 * If the site asks for the detailed form (format 1) and the patient is no older than the site's
 * limit, the age reads "Ny Nm Nd"; otherwise it reads whole years (or months for a young child).
 */
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
    // Same counting as patientAge, but measured up to the date of death instead of today.
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
    // Under two years: "1 month" for exactly one, "N months" otherwise.
    return `${ageInMonths} ${ageInMonths === 1 ? 'month' : 'months'}`;
}
