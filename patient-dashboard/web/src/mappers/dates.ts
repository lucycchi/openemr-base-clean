/**
 * Date display. In: a date written "YYYY-MM-DD" (possibly followed by a time) and the site's chosen
 * date format. Out: the same date rearranged the way the site wants it shown, for example
 * "03/14/1958". Used by the patient header (date of birth, date of death) and the Encounter history card.
 */

/** The date_display_format global: 0 = Y-m-d, 1 = m/d/Y (US), 2 = d/m/Y (international). */
export type DateDisplayFormat = 0 | 1 | 2;

/**
 * Port of DateFormatterUtils::oeFormatShortDate (src/Services/Utils/DateFormatterUtils.php:188-216),
 * which the old header and Visit History page use. Input starts YYYY-MM-DD; anything shorter is
 * returned unchanged, as the PHP does.
 */
export function formatShortDate(date: string, format: DateDisplayFormat): string {
    if (date.length < 10) {
        return date;
    }
    // Cut the year, month and day out of "YYYY-MM-DD" by their character positions.
    const year = date.slice(0, 4);
    const month = date.slice(5, 7);
    const day = date.slice(8, 10);
    if (format === 1) {
        return `${month}/${day}/${year}`;
    }
    if (format === 2) {
        return `${day}/${month}/${year}`;
    }
    return `${year}-${month}-${day}`;
}
