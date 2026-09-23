<?php

/**
 * The reference-interval table, read once from contracts/reference_ranges.json.
 *
 * range() picks the row for the patient's sex: a sex-specific row when one
 * exists for that sex, the sexless default otherwise, and for an unknown sex
 * the widest union of the sex-specific rows (so nobody is flagged abnormal
 * by a sex they may not be). for() is the pre-2026-09-23 triple [low, high,
 * unit] and is null for one-sided intervals; new code uses range().
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot;

final class ReferenceRanges
{
    /** Must equal the "version" in the JSON file; ReferenceRangesTest pins it. */
    public const VERSION = '2026-09-23.1';

    private const FILE = __DIR__ . '/../contracts/reference_ranges.json';

    /** @var array<string, list<Range>>|null */
    private static ?array $table = null;

    /** @return array<string, list<Range>> every analyte's rows, keyed by LOINC */
    public function all(): array
    {
        if (self::$table === null) {
            self::$table = self::load();
        }
        return self::$table;
    }

    /** The interval for this analyte and patient sex ('M', 'F' or null when unknown), or null when the table has none. */
    public function range(string $loinc, ?string $sex = null): ?Range
    {
        $rows = $this->all()[$loinc] ?? [];
        if ($rows === []) {
            return null;
        }
        $default = null;
        $sexed = [];
        foreach ($rows as $row) {
            if ($row->sex === null) {
                $default = $row;
            } else {
                $sexed[$row->sex] = $row;
            }
        }
        if ($sex !== null && isset($sexed[$sex])) {
            return $sexed[$sex];
        }
        if ($default !== null) {
            return $default;
        }
        // Unknown sex with only sex-specific rows: the union interval.
        $low = null;
        $high = null;
        $lowOpen = false;
        $highOpen = false;
        $first = null;
        foreach ($sexed as $row) {
            $first ??= $row;
            if ($row->low === null) {
                $lowOpen = true;
            } else {
                $low = $low === null ? $row->low : min($low, $row->low);
            }
            if ($row->high === null) {
                $highOpen = true;
            } else {
                $high = $high === null ? $row->high : max($high, $row->high);
            }
        }
        if ($first === null) {
            return null;
        }
        return new Range($lowOpen ? null : $low, $highOpen ? null : $high, $first->unit, $first->panicLow, $first->panicHigh, $first->source);
    }

    /** @return array{float, float, string}|null [low, high, unit] for two-sided intervals; the pre-2026-09-23 shape */
    public function for(string $loinc): ?array
    {
        $range = $this->range($loinc);
        if ($range === null || $range->low === null || $range->high === null) {
            return null;
        }
        return [$range->low, $range->high, $range->unit];
    }

    /** @return array<string, list<Range>> */
    private static function load(): array
    {
        $raw = file_get_contents(self::FILE);
        if ($raw === false) {
            throw new \RuntimeException('reference_ranges.json is missing');
        }
        $doc = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($doc) || ($doc['version'] ?? null) !== self::VERSION || !is_array($doc['ranges'] ?? null)) {
            throw new \RuntimeException('reference_ranges.json version does not match ReferenceRanges::VERSION');
        }
        $table = [];
        foreach ($doc['ranges'] as $loinc => $rows) {
            if (!is_string($loinc) || !is_array($rows)) {
                throw new \RuntimeException('reference_ranges.json: malformed entry');
            }
            $table[$loinc] = array_map(self::row(...), array_values($rows));
        }
        return $table;
    }

    private static function row(mixed $row): Range
    {
        if (!is_array($row)) {
            throw new \RuntimeException('reference_ranges.json: a range row must be an object');
        }
        $num = static function (mixed $v): ?float {
            if ($v === null) {
                return null;
            }
            if (!is_int($v) && !is_float($v)) {
                throw new \RuntimeException('reference_ranges.json: bounds must be numbers');
            }
            return (float) $v;
        };
        $unit = $row['unit'] ?? '';
        $source = $row['source'] ?? '';
        $sex = $row['sex'] ?? null;
        if (!is_string($unit) || !is_string($source) || ($sex !== null && $sex !== 'M' && $sex !== 'F')) {
            throw new \RuntimeException('reference_ranges.json: unit, source and sex must be strings');
        }
        return new Range($num($row['low'] ?? null), $num($row['high'] ?? null), $unit, $num($row['panic_low'] ?? null), $num($row['panic_high'] ?? null), $source, $sex);
    }
}
