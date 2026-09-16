<?php

/**
 * Readiness result persisted under the site documents dir so the TTL holds across PHP requests.
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

    // Under the site's own documents tree, not a shared temp dir: an unprivileged
    // local user must not be able to pre-plant or replace the cache file.
    public static function inSiteDirectory(string $siteDir): self
    {
        $dir = $siteDir . '/documents/clinical-copilot';
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create readiness cache directory');
        }
        return new self($dir . '/readiness.json');
    }

    public function get(): ?array
    {
        if (is_link($this->path) || !is_file($this->path)) {
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
        if (is_link($this->path)) {
            throw new \RuntimeException('Readiness cache path is a symlink');
        }
        file_put_contents($this->path, json_encode(['at' => $at, 'dependencies' => $dependencies], JSON_THROW_ON_ERROR), LOCK_EX);
        chmod($this->path, 0600);
    }
}
