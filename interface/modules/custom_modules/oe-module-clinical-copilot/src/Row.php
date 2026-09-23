<?php

/**
 * Narrowing readers for untyped database rows.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot;

/**
 * Safe accessors for raw database rows. OpenEMR's query layer returns
 * untyped associative arrays (every value may be a string, int, null...).
 * These helpers narrow a column to the type the caller wants, with a
 * harmless default instead of a TypeError, so the chart source can stay
 * strictly typed without sprinkling is_string() everywhere.
 */
final class Row
{
    /**
     * String column; numbers are stringified, anything else becomes ''.
     *
     * @param array<mixed> $row
     */
    public static function str(array $row, string $key): string
    {
        $v = $row[$key] ?? null;
        return is_string($v) ? $v : (is_int($v) || is_float($v) ? (string) $v : '');
    }

    /**
     * Integer column; numeric strings are accepted, anything else becomes 0.
     *
     * @param array<mixed> $row
     */
    public static function int(array $row, string $key): int
    {
        $v = $row[$key] ?? null;
        if (is_int($v)) {
            return $v;
        }
        if (is_string($v) && is_numeric($v)) {
            return (int) $v;
        }
        return 0;
    }

    /**
     * Float column; ints and numeric strings are accepted, anything else becomes 0.0.
     *
     * @param array<mixed> $row
     */
    public static function float(array $row, string $key): float
    {
        $v = $row[$key] ?? null;
        if (is_float($v) || is_int($v)) {
            return (float) $v;
        }
        if (is_string($v) && is_numeric($v)) {
            return (float) $v;
        }
        return 0.0;
    }
}
