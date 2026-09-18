<?php

/**
 * FixedClock pins "now" so a pre-warm assembles history as of the scheduled
 * day, in the site's zone.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Modules\ClinicalCopilot;

use DateTimeImmutable;
use DateTimeZone;
use OpenEMR\Modules\ClinicalCopilot\FixedClock;
use OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Support\ModuleAutoload;
use PHPUnit\Framework\TestCase;

final class FixedClockTest extends TestCase
{
    /**
     * @codeCoverageIgnore Fixture wiring; runs before coverage attribution.
     */
    public static function setUpBeforeClass(): void
    {
        ModuleAutoload::register();
    }

    public function testStartOfDayIsMidnightInTheGivenZone(): void
    {
        $clock = FixedClock::startOfDay('2026-09-18', new DateTimeZone('America/Los_Angeles'));

        self::assertSame('2026-09-18T00:00:00-07:00', $clock->now()->format('c'));
    }

    public function testNowIsStable(): void
    {
        $clock = new FixedClock(new DateTimeImmutable('2026-09-18 06:00:00'));

        self::assertEquals($clock->now(), $clock->now());
    }
}
