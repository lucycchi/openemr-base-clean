<?php

/**
 * Readiness result persisted to a temp file so the TTL holds across PHP requests.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Ops;

final readonly class FileReadinessStore implements ReadinessStore
{
    public function __construct(private string $path)
    {
    }

    public static function default(): self
    {
        return new self(sys_get_temp_dir() . '/oe-copilot-readiness.json');
    }

    public function get(): ?array
    {
        if (!is_file($this->path)) {
            return null;
        }
        $raw = file_get_contents($this->path);
        if ($raw === false) {
            return null;
        }
        try {
            $data = json_decode($raw, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($data) || !is_int($data['at'] ?? null) || !is_array($data['dependencies'] ?? null)) {
            return null;
        }
        $deps = [];
        foreach ($data['dependencies'] as $name => $state) {
            if (is_string($name) && is_string($state)) {
                $deps[$name] = $state;
            }
        }
        return ['at' => $data['at'], 'dependencies' => $deps];
    }

    public function put(int $at, array $dependencies): void
    {
        file_put_contents($this->path, json_encode(['at' => $at, 'dependencies' => $dependencies], JSON_THROW_ON_ERROR), LOCK_EX);
    }
}
