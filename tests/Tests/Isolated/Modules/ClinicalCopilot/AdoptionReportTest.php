<?php

/**
 * Pins the arithmetic of KEY_METRICS.md metric 7 (chat adoption per encounter)
 * over fixed counts, without a database.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Modules\ClinicalCopilot;

use OpenEMR\Modules\ClinicalCopilot\AdoptionCounts;
use OpenEMR\Modules\ClinicalCopilot\AdoptionReport;
use OpenEMR\Modules\ClinicalCopilot\AdoptionRow;
use OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Support\ModuleAutoload;
use PHPUnit\Framework\TestCase;

final class AdoptionReportTest extends TestCase
{
    protected function setUp(): void
    {
        ModuleAutoload::register();
    }

    /**
     * @param array<string, array<string, list<int>>> $encounters
     * @param array<string, array<int, int>> $asks
     */
    private static function report(array $encounters, array $asks): AdoptionReport
    {
        return new AdoptionReport(new class ($encounters, $asks) implements AdoptionCounts {
            /**
             * @param array<string, array<string, list<int>>> $encounters
             * @param array<string, array<int, int>> $asks
             */
            public function __construct(private array $encounters, private array $asks)
            {
            }

            public function encounters(\DateTimeImmutable $from, \DateTimeImmutable $to): array
            {
                return $this->encounters;
            }

            public function asks(\DateTimeImmutable $from, \DateTimeImmutable $to): array
            {
                return $this->asks;
            }
        });
    }

    private static function from(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-09-21');
    }

    private static function to(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-09-28');
    }

    /** @return array<string, int|float|string|null> */
    private static function fields(AdoptionRow $r): array
    {
        return ['physician' => $r->physician, 'period' => $r->period, 'encounters' => $r->encounters, 'used' => $r->used, 'asks' => $r->asks, 'share' => $r->share(), 'mean' => $r->meanAsks()];
    }

    public function testShareIsEncountersWithAQuestionOverEncounters(): void
    {
        // Four encounters on Monday; questions on two of them (three and one).
        $report = self::report(['drsmith' => ['2026-09-21' => [101, 102, 103, 104]]], ['drsmith' => [101 => 3, 103 => 1]]);

        self::assertSame(
            [['physician' => 'drsmith', 'period' => '2026-09-21', 'encounters' => 4, 'used' => 2, 'asks' => 4, 'share' => 50.0, 'mean' => 2.0]],
            array_map(self::fields(...), $report->days(self::from(), self::to())),
        );
    }

    public function testQuestionsOnSomeoneElsesEncounterDoNotCount(): void
    {
        // drjones asked on drsmith's encounter 101, and on an encounter outside the window (999).
        $report = self::report(
            ['drsmith' => ['2026-09-21' => [101]], 'drjones' => ['2026-09-21' => [201]]],
            ['drjones' => [101 => 2, 999 => 5]],
        );

        $rows = array_map(self::fields(...), $report->days(self::from(), self::to()));

        self::assertSame(['drjones', 'drsmith'], array_column($rows, 'physician'), 'sorted by day then physician');
        self::assertSame([0, 0], array_column($rows, 'used'), 'the numerator is a subset of the denominator');
        self::assertSame([0.0, 0.0], array_column($rows, 'share'));
        self::assertSame([null, null], array_column($rows, 'mean'), 'no mean questions when the chat was used on nothing');
    }

    public function testADuplicateEncounterRowIsCountedOnce(): void
    {
        $report = self::report(['drsmith' => ['2026-09-21' => [101, 101]]], ['drsmith' => [101 => 1]]);

        self::assertSame(1, $report->days(self::from(), self::to())[0]->encounters);
    }

    public function testWeeksRollUpDaysPerPhysician(): void
    {
        // Monday 21 Sep and Wednesday 23 Sep are ISO week 39; Monday 28 Sep would be week 40.
        $report = self::report(
            ['drsmith' => ['2026-09-21' => [101, 102], '2026-09-23' => [103]]],
            ['drsmith' => [102 => 1, 103 => 2]],
        );

        self::assertSame(
            [['physician' => 'drsmith', 'period' => '2026-W39', 'encounters' => 3, 'used' => 2, 'asks' => 3, 'share' => 66.7, 'mean' => 1.5]],
            array_map(self::fields(...), $report->weeks(self::from(), self::to())),
        );
    }

    public function testTotalCoversEveryPhysicianOverTheWindow(): void
    {
        $report = self::report(
            ['drsmith' => ['2026-09-21' => [101]], 'drjones' => ['2026-09-22' => [201, 202]]],
            ['drsmith' => [101 => 1], 'drjones' => [201 => 4]],
        );

        self::assertSame(
            ['physician' => 'all', 'period' => '2026-09-21..2026-09-27', 'encounters' => 3, 'used' => 2, 'asks' => 5, 'share' => 66.7, 'mean' => 2.5],
            self::fields($report->total(self::from(), self::to())),
        );
    }

    public function testNoEncountersMeansNoRowsAndNoShare(): void
    {
        $report = self::report([], ['drsmith' => [101 => 3]]);

        self::assertSame([], $report->days(self::from(), self::to()));
        self::assertSame([], $report->weeks(self::from(), self::to()));
        self::assertNull($report->total(self::from(), self::to())->share(), 'an empty window has no share, not 0%');
    }
}
