<?php

/**
 * Loads the JSON Schema contracts under contracts/. Those files are the
 * source of truth for every tool input and output; PHP conforms to them.
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
 * Loads the JSON Schema files in ../contracts/. The same schema is used two
 * ways: as a strict "response_format" sent to OpenAI (so the model is forced
 * to emit that shape) and as a validator for what comes back / what we
 * return to the panel. Schemas are cached per process after first load.
 */
final class Contracts
{
    private const DIR = __DIR__ . '/../contracts/';

    /** Keys that describe the document rather than the data; OpenAI rejects them. */
    private const METADATA_KEYS = ['$schema', '$id', 'title'];

    /** @var array<string, \stdClass> */
    private static array $loaded = [];

    /**
     * The contract as a decoded object, with $id rewritten to the file's
     * location so sibling $ref values resolve from disk.
     */
    public static function schema(string $name): \stdClass
    {
        if (isset(self::$loaded[$name])) {
            return self::$loaded[$name];
        }
        // The name is used to build a file path, so it is restricted to a safe
        // character set and must resolve to a real file — no "../" tricks.
        $path = self::DIR . $name . '.schema.json';
        $real = realpath($path);
        if ($real === false || !preg_match('/^[a-z][a-z0-9.-]*$/', $name)) {
            throw new \InvalidArgumentException('Unknown contract');
        }
        $decoded = json_decode((string) file_get_contents($real), false, 64, JSON_THROW_ON_ERROR);
        if (!$decoded instanceof \stdClass) {
            throw new \RuntimeException('Contract is not a JSON object');
        }
        $decoded->{'$id'} = 'file://' . $real;
        return self::$loaded[$name] = $decoded;
    }

    /**
     * The contract as the array OpenAI accepts for response_format.json_schema:
     * document metadata removed, everything else verbatim.
     *
     * @return array<string, mixed>
     */
    public static function forOpenAi(string $name): array
    {
        // Round-trip through JSON to convert the stdClass tree into nested arrays.
        $array = json_decode(json_encode(self::schema($name), JSON_THROW_ON_ERROR), true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($array)) {
            throw new \RuntimeException('Contract is not a JSON object');
        }
        foreach (self::METADATA_KEYS as $key) {
            unset($array[$key]);
        }
        /** @var array<string, mixed> $array */
        return $array;
    }
}
