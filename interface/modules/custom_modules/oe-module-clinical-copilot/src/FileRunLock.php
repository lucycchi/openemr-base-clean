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

/**
 * RunLock backed by an OS-level advisory file lock (flock). Holding the
 * lock = holding an open file handle with LOCK_EX; the kernel releases it
 * automatically if the process dies, so a crashed pre-warm never leaves a
 * stale lock behind. Non-blocking: a second runner gets `false` immediately
 * rather than queueing.
 */
final class FileRunLock implements RunLock
{
    /** Open file handle while locked; null when not held. @var resource|null */
    private $handle = null;

    public function __construct(private readonly string $path)
    {
    }

    public function acquire(): bool
    {
        // Re-entrant for the same object: already holding it counts as success.
        if ($this->handle !== null) {
            return true;
        }
        $dir = dirname($this->path);
        if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create lock directory');
        }
        // 'c' = open for writing, create if missing, do NOT truncate.
        $handle = fopen($this->path, 'c');
        if ($handle === false) {
            throw new \RuntimeException('Cannot open lock file');
        }
        // LOCK_EX = exclusive, LOCK_NB = fail fast instead of waiting.
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
