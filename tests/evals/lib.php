<?php

/**
 * Typed readers for the eval harness.
 *
 * Case files, fixtures, sidecar replies and database rows all arrive as
 * decoded JSON or untyped arrays: every value is `mixed`. These helpers
 * narrow a value to the type the caller needs, with a harmless default
 * instead of a cast, so the harness stays honest under PHPStan level 10
 * ("narrow, don't cast") without an is_string() at every use. They are the
 * harness-side counterpart of the module's Row class.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Evals;

/** The value as a string; numbers are stringified, anything else is the default. */
function strOf(mixed $v, string $default = ''): string
{
    return is_string($v) ? $v : (is_int($v) || is_float($v) ? (string) $v : $default);
}

/** The value as an int; numeric strings are accepted, anything else is the default. */
function intOf(mixed $v, int $default = 0): int
{
    if (is_int($v)) {
        return $v;
    }
    return is_string($v) && is_numeric($v) ? (int) $v : (is_float($v) ? (int) $v : $default);
}

/**
 * The value as an array, or [] when it is not one.
 *
 * @return array<mixed>
 */
function arrOf(mixed $v): array
{
    return is_array($v) ? $v : [];
}

/**
 * The value as a string-keyed array (a decoded JSON object), or [] when it is not an array.
 *
 * @return array<string, mixed>
 */
function mapOf(mixed $v): array
{
    $out = [];
    foreach (is_array($v) ? $v : [] as $k => $x) {
        $out[(string) $k] = $x;
    }
    return $out;
}

/**
 * The value as a list (a decoded JSON array), or [] when it is not an array.
 *
 * @return list<mixed>
 */
function listOf(mixed $v): array
{
    return is_array($v) ? array_values($v) : [];
}

/**
 * The strings in the value, in order; non-strings are dropped.
 *
 * @return list<string>
 */
function strings(mixed $v): array
{
    return array_values(array_filter(is_array($v) ? $v : [], 'is_string'));
}

/**
 * The keyed form of strOf: the string at $a[$key], or the default when the key is absent.
 *
 * @param array<mixed> $a
 */
function str(array $a, string $key, string $default = ''): string
{
    return strOf($a[$key] ?? null, $default);
}

/**
 * The keyed form of intOf: the int at $a[$key], or the default when the key is absent.
 *
 * @param array<mixed> $a
 */
function int(array $a, string $key, int $default = 0): int
{
    return intOf($a[$key] ?? null, $default);
}

/**
 * The keyed form of arrOf: the array at $a[$key], or [] when absent.
 *
 * @param array<mixed> $a
 * @return array<mixed>
 */
function arr(array $a, string $key): array
{
    return arrOf($a[$key] ?? null);
}

/**
 * The keyed form of mapOf: the JSON object at $a[$key] as a string-keyed array, or [].
 *
 * @param array<mixed> $a
 * @return array<string, mixed>
 */
function map(array $a, string $key): array
{
    return mapOf($a[$key] ?? null);
}

/**
 * The keyed form of listOf: the JSON array at $a[$key] as a list, or [].
 *
 * @param array<mixed> $a
 * @return list<mixed>
 */
function lst(array $a, string $key): array
{
    return listOf($a[$key] ?? null);
}

/**
 * Decodes a JSON file into a string-keyed array; throws on malformed JSON or a non-object.
 *
 * @return array<string, mixed>
 */
function jsonFile(string $path): array
{
    $decoded = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new \RuntimeException("Not a JSON object: $path");
    }
    return mapOf($decoded);
}
