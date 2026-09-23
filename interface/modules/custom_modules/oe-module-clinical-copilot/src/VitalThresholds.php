<?php

/**
 * The adult vital-sign thresholds, read once from contracts/vital_thresholds.json.
 * `abnormal` bounds decide a VitalAbnormal fact; `delta` thresholds decide
 * a VitalDelta fact. VERSION must equal the file's version (pinned by test).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot;

final class VitalThresholds
{
    public const VERSION = '2026-09-23.1';

    private const FILE = __DIR__ . '/../contracts/vital_thresholds.json';

    /** @var array<string, array<string, float>>|null */
    private static ?array $abnormal = null;
    /** @var array<string, array<string, float>>|null */
    private static ?array $delta = null;

    /**
     * The numeric bounds for one vital ("bp", "pulse", ...), e.g. ['systolic_high' => 140.0, 'diastolic_high' => 90.0].
     *
     * @return array<string, float>
     */
    public function abnormal(string $vital): array
    {
        $this->load();
        return self::$abnormal[$vital] ?? [];
    }

    /** @return array<string, float> */
    public function delta(string $vital): array
    {
        $this->load();
        return self::$delta[$vital] ?? [];
    }

    private function load(): void
    {
        if (self::$abnormal !== null && self::$delta !== null) {
            return;
        }
        $raw = file_get_contents(self::FILE);
        if ($raw === false) {
            throw new \RuntimeException('vital_thresholds.json is missing');
        }
        $doc = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($doc) || ($doc['version'] ?? null) !== self::VERSION || !is_array($doc['abnormal'] ?? null) || !is_array($doc['delta'] ?? null)) {
            throw new \RuntimeException('vital_thresholds.json version does not match VitalThresholds::VERSION');
        }
        self::$abnormal = self::numbers($doc['abnormal']);
        self::$delta = self::numbers($doc['delta']);
    }

    /**
     * @param array<mixed> $section
     * @return array<string, array<string, float>>
     */
    private static function numbers(array $section): array
    {
        $out = [];
        foreach ($section as $vital => $bounds) {
            if (!is_string($vital) || !is_array($bounds)) {
                throw new \RuntimeException('vital_thresholds.json: malformed section');
            }
            $out[$vital] = [];
            foreach ($bounds as $k => $v) {
                if (is_string($k) && (is_int($v) || is_float($v))) {
                    $out[$vital][$k] = (float) $v;
                }
            }
        }
        return $out;
    }
}
