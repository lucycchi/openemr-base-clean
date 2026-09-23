<?php

/**
 * FileRunLock keeps two pre-warm sweeps from running at once.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Modules\ClinicalCopilot;

use OpenEMR\Modules\ClinicalCopilot\FileRunLock;
use OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Support\ModuleAutoload;
use PHPUnit\Framework\TestCase;

/**
 * FileRunLock against a real temp file: a second holder is refused until
 * the first releases, and a missing parent directory is created. Uses the
 * OS temp dir with a random suffix so parallel test runs do not collide.
 */
final class FileRunLockTest extends TestCase
{
    /**
     * @codeCoverageIgnore Fixture wiring; runs before coverage attribution.
     */
    public static function setUpBeforeClass(): void
    {
        ModuleAutoload::register();
    }

    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/copilot-prewarm-test-' . bin2hex(random_bytes(4)) . '.lock';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    public function testASecondHolderCannotAcquireUntilTheFirstReleases(): void
    {
        $first = new FileRunLock($this->path);
        $second = new FileRunLock($this->path);

        self::assertTrue($first->acquire());
        self::assertFalse($second->acquire());
        $first->release();
        self::assertTrue($second->acquire());
        $second->release();
    }

    public function testTheLockDirectoryIsCreatedWhenMissing(): void
    {
        $lock = new FileRunLock(sys_get_temp_dir() . '/copilot-prewarm-test-' . bin2hex(random_bytes(4)) . '/nested/run.lock');

        self::assertTrue($lock->acquire());
        $lock->release();
    }
}
