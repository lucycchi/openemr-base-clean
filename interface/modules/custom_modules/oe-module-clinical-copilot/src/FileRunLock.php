<?php

/**
 * flock()-based RunLock. The OS releases it when the process ends, so a
 * crashed sweep never leaves a stale lock behind.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot;

final class FileRunLock implements RunLock
{
    /** @var resource|null */
    private $handle = null;

    public function __construct(private readonly string $path)
    {
    }

    public function acquire(): bool
    {
        if ($this->handle !== null) {
            return true;
        }
        $dir = dirname($this->path);
        if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create lock directory');
        }
        $handle = fopen($this->path, 'c');
        if ($handle === false) {
            throw new \RuntimeException('Cannot open lock file');
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return false;
        }
        $this->handle = $handle;
        return true;
    }

    public function release(): void
    {
        if ($this->handle === null) {
            return;
        }
        flock($this->handle, LOCK_UN);
        fclose($this->handle);
        $this->handle = null;
    }
}
