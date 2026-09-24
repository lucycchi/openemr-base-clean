<?php

/**
 * KEY_METRICS.md metric 6 arithmetic: up, down and not rated as shares of
 * briefings rendered, every day of the window listed.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Modules\ClinicalCopilot;

use OpenEMR\Modules\ClinicalCopilot\RatingCounts;
use OpenEMR\Modules\ClinicalCopilot\RatingDay;
use OpenEMR\Modules\ClinicalCopilot\RatingReport;
use OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Support\ModuleAutoload;
use PHPUnit\Framework\TestCase;

final class RatingReportTest extends TestCase
{
    /**
     * @codeCoverageIgnore Fixture wiring; runs before coverage attribution.
     */
    public static function setUpBeforeClass(): void
    {
        ModuleAutoload::register();
    }

    private function report(): RatingReport
    {
        return new RatingReport(new class implements RatingCounts {
            public function renderedByDay(\DateTimeImmutable $from, \DateTimeImmutable $to): array
            {
                return ['2026-09-22' => 20, '2026-09-24' => 4];
            }

            public function ratingsByDay(\DateTimeImmutable $from, \DateTimeImmutable $to): array
            {
                return ['2026-09-22' => ['up' => 5, 'down' => 1], '2026-09-23' => ['up' => 1, 'down' => 0]];
            }

            public function ratingsByVersion(\DateTimeImmutable $from, \DateTimeImmutable $to): array
            {
                return [['prompt_version' => '2026-09-23.1', 'model' => 'gpt-4o-mini', 'up' => 6, 'down' => 1]];
            }
        });
    }

    public function testEveryDayIsListedWithSharesOfRenderedThatSumTo100(): void
    {
        $days = $this->report()->days(new \DateTimeImmutable('2026-09-22'), new \DateTimeImmutable('2026-09-25'));

        self::assertSame(['2026-09-22', '2026-09-23', '2026-09-24'], array_map(static fn(RatingDay $d): string => $d->day, $days));
        self::assertSame(['up' => 25.0, 'down' => 5.0, 'not_rated' => 70.0], $days[0]->shares());
        self::assertSame(14, $days[0]->notRated());
        // A rating recorded on a day with no briefing rendered: listed, never a negative not-rated count.
        self::assertSame(0, $days[1]->notRated());
        self::assertNull((new RatingDay('2026-09-23', 0, 0, 0))->shares(), 'no briefings, no percentages');
        self::assertSame(['up' => 0.0, 'down' => 0.0, 'not_rated' => 100.0], $days[2]->shares(), 'ignored briefings stay visible');
    }

    public function testTheWindowTotalAndThePerVersionBreakdown(): void
    {
        $from = new \DateTimeImmutable('2026-09-22');
        $to = new \DateTimeImmutable('2026-09-25');
        $total = $this->report()->total($from, $to);

        self::assertSame('2026-09-22..2026-09-24', $total->day);
        self::assertSame(24, $total->rendered);
        self::assertSame(6, $total->up);
        self::assertSame(1, $total->down);
        self::assertSame(17, $total->notRated());
        self::assertSame([['prompt_version' => '2026-09-23.1', 'model' => 'gpt-4o-mini', 'up' => 6, 'down' => 1]], $this->report()->byVersion($from, $to));
    }
}
