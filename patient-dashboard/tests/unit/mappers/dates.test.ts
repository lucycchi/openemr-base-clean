import { describe, expect, it } from 'vitest';
import { formatShortDate } from '../../../web/src/mappers/dates';

// Port of DateFormatterUtils::oeFormatShortDate (src/Services/Utils/DateFormatterUtils.php:188-216):
// the date_display_format global is 0 = Y-m-d, 1 = m/d/Y (US), 2 = d/m/Y (international).
describe('formatShortDate', () => {
    it('formats a YYYY-MM-DD date in each site format', () => {
        expect(formatShortDate('2026-08-14', 0)).toBe('2026-08-14');
        expect(formatShortDate('2026-08-14', 1)).toBe('08/14/2026');
        expect(formatShortDate('2026-08-14', 2)).toBe('14/08/2026');
    });

    it('returns anything shorter than a full date unchanged, as the PHP does', () => {
        expect(formatShortDate('', 1)).toBe('');
        expect(formatShortDate('2026-08', 2)).toBe('2026-08');
    });

    it('uses only the date part of a longer value', () => {
        expect(formatShortDate('2026-08-14T00:00:00+00:00', 1)).toBe('08/14/2026');
    });
});
