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

use JsonSchema\Constraints\Constraint;
use JsonSchema\Validator;

/**
 * Loads the JSON Schema files in ../contracts/. The same schema is used three
 * ways: as a strict "response_format" sent to OpenAI (so the model is forced
 * to emit that shape), as the runtime gate on what the sidecar returns
 * (violations(), called by SidecarClient before anything is parsed), and as
 * the test oracle for what we return to the panel. Schemas are cached per
 * process after first load. Decisions and trade-offs:
 * clinical_copilot_week2/ENGINEERING_REQUIREMENTS.md section 3.
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
     * Every way $data violates the named contract, empty when it conforms.
     * Sibling $ref values (handoff, lab-report, ...) resolve from disk. Arrays
     * are round-tripped through JSON so the validator sees objects and lists
     * exactly as the wire did.
     *
     * @return list<string>
     */
    public static function violations(string $name, mixed $data): array
    {
        $decoded = json_decode(json_encode($data, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
        $validator = new Validator();
        $validator->validate($decoded, self::schema($name), Constraint::CHECK_MODE_NORMAL);
        $out = [];
        foreach ($validator->getErrors() as $error) {
            if (!is_array($error)) {
                $out[] = 'invalid';
                continue;
            }
            $property = is_string($error['property'] ?? null) ? $error['property'] : '';
            $message = is_string($error['message'] ?? null) ? $error['message'] : 'invalid';
            $out[] = $property === '' ? $message : $property . ': ' . $message;
        }
        return $out;
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
