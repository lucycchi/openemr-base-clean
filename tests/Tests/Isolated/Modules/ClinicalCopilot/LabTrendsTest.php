<?php

/**
 * LabTrends: which lab results become a trend line in the panel, and how.
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
use OpenEMR\Modules\ClinicalCopilot\AssembledFacts;
use OpenEMR\Modules\ClinicalCopilot\Contracts;
use OpenEMR\Modules\ClinicalCopilot\Documents\BBox;
use OpenEMR\Modules\ClinicalCopilot\Documents\Citation;
use OpenEMR\Modules\ClinicalCopilot\FactSet;
use OpenEMR\Modules\ClinicalCopilot\LabRecord;
use OpenEMR\Modules\ClinicalCopilot\LabTrends;
use OpenEMR\Modules\ClinicalCopilot\PanelPayload;
use OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Support\ModuleAutoload;
use PHPUnit\Framework\TestCase;

/**
 * Pins the trend rules LabTrends documents: numeric, coded, dated results
 * only; one line per LOINC and spelling-insensitive unit; a repeated draw
 * keeps its cited copy; two points minimum; the caps, the "more" count and
 * the order lines are chosen in;
 * the latest point's name and unit label the line; and the payload shape
 * matches contracts/trends.schema.json.
 */
final class LabTrendsTest extends TestCase
{
    private const LDL = '2089-1';

    /**
     * @codeCoverageIgnore Fixture wiring; runs before coverage attribution.
     */
    public static function setUpBeforeClass(): void
    {
        ModuleAutoload::register();
    }

    private static int $nextId = 1;

    private static function lab(string $loinc, ?float $value, string $date, string $units = 'mg/dL', ?Citation $citation = null, string $name = 'LDL Cholesterol', bool $unitMismatch = false): LabRecord
    {
        return new LabRecord(self::$nextId++, 1, $loinc, $name, $value, $units, new DateTimeImmutable($date), $citation, $unitMismatch);
    }

    private static function cited(string $documentId = '42'): Citation
    {
        $box = new BBox(3, 10.0, 20.0, 60.0, 30.0, 612.0, 792.0);
        return new Citation('document', $documentId, '3', '/results/8/value', '141.6', true, $box, $box);
    }

    public function testTwoResultsForOneTestMakeOneLineOldestFirst(): void
    {
        $trends = LabTrends::fromLabs([
            self::lab(self::LDL, 150.0, '2026-06-01'),
            self::lab(self::LDL, 130.0, '2025-06-01'),
        ]);

        self::assertCount(1, $trends->series);
        self::assertSame(0, $trends->more);
        $s = $trends->series[0];
        self::assertSame(self::LDL, $s->loinc);
        self::assertSame(['2025-06-01', '2026-06-01'], array_map(static fn($p): string => $p->date->format('Y-m-d'), $s->points));
        self::assertSame([130.0, 150.0], array_map(static fn($p): float => $p->value, $s->points));
    }

    public function testASingleResultIsNoTrend(): void
    {
        self::assertSame([], LabTrends::fromLabs([self::lab(self::LDL, 150.0, '2026-06-01')])->series);
        self::assertSame([], LabTrends::fromLabs([])->series);
    }

    public function testNonNumericUncodedUndatedAndMismatchedResultsAreLeftOut(): void
    {
        $trends = LabTrends::fromLabs([
            self::lab(self::LDL, 150.0, '2026-06-01'),
            self::lab(self::LDL, null, '2026-05-01'),
            self::lab(self::LDL, 140.0, '1970-01-01'),
            self::lab(self::LDL, 3.1, '2026-04-01', 'mmol/L', null, 'LDL Cholesterol', true),
            self::lab('', 99.0, '2026-03-01'),
            self::lab('', 98.0, '2026-02-01'),
        ]);

        self::assertSame([], $trends->series, 'only one usable LDL result remains, and uncoded rows never group');
    }

    public function testUnitSpellingIsIgnoredButARealUnitChangeSplitsTheLine(): void
    {
        $trends = LabTrends::fromLabs([
            self::lab(self::LDL, 130.0, '2025-06-01', 'mg/dL'),
            self::lab(self::LDL, 140.0, '2025-12-01', ' MG/DL '),
            self::lab(self::LDL, 3.4, '2026-01-01', 'mmol/L'),
            self::lab(self::LDL, 3.6, '2026-02-01', 'mmol/L'),
        ]);

        self::assertCount(2, $trends->series);
        self::assertSame('mmol/L', $trends->series[0]->unit, 'most recently resulted line first');
        self::assertSame('MG/DL', $trends->series[1]->unit, 'the latest point labels the line, trimmed');
        self::assertCount(2, $trends->series[1]->points);
    }

    public function testTheLatestPointNamesTheLine(): void
    {
        $trends = LabTrends::fromLabs([
            self::lab(self::LDL, 130.0, '2025-06-01', 'mg/dL', null, 'LDL Cholesterol Calc'),
            self::lab(self::LDL, 141.6, '2026-09-15', 'mg/dL', self::cited(), 'LDL-C'),
        ]);

        self::assertSame('LDL-C', $trends->series[0]->name);
    }

    public function testTheSameValueTwiceOnOneDayKeepsTheCitedCopy(): void
    {
        $trends = LabTrends::fromLabs([
            self::lab(self::LDL, 130.0, '2025-06-01'),
            self::lab(self::LDL, 141.6, '2026-09-15'),
            self::lab(self::LDL, 141.6, '2026-09-15', 'mg/dL', self::cited()),
            self::lab(self::LDL, 150.0, '2026-09-15'),
        ]);

        $points = $trends->series[0]->points;
        self::assertCount(3, $points, 'the repeated 141.6 is one point; a different value the same day is its own');
        self::assertNotNull($points[1]->citation);
        self::assertSame('42', $points[1]->citation->sourceId);
        self::assertNull($points[0]->citation);
    }

    public function testCapsKeepTheLatestPointsAndTheMostRecentLines(): void
    {
        $labs = [];
        for ($i = 1; $i <= 10; $i++) {
            $labs[] = self::lab(self::LDL, 100.0 + $i, sprintf('2026-01-%02d', $i));
        }
        for ($t = 0; $t < 7; $t++) {
            $code = 'X-' . $t;
            $labs[] = self::lab($code, 1.0, '2020-01-01');
            $labs[] = self::lab($code, 2.0, sprintf('2020-02-%02d', $t + 1));
        }

        $trends = LabTrends::fromLabs($labs);

        self::assertCount(LabTrends::MAX_SERIES, $trends->series);
        self::assertSame(2, $trends->more, 'eight lines on file, six shown');
        self::assertSame(self::LDL, $trends->series[0]->loinc);
        self::assertCount(LabTrends::MAX_POINTS, $trends->series[0]->points);
        self::assertSame(103.0, $trends->series[0]->points[0]->value, 'the two oldest LDL points are dropped');
        self::assertSame('X-6', $trends->series[1]->loinc, 'then the most recently resulted of the rest');
    }

    public function testOnTheSameDayADocumentLineThenCommonlyTrendedTestsComeFirst(): void
    {
        $sameDay = static fn(string $loinc, ?Citation $c = null): array => [
            self::lab($loinc, 1.0, '2026-01-01'),
            self::lab($loinc, 2.0, '2026-09-12', 'mg/dL', $c),
        ];
        $trends = LabTrends::fromLabs([
            ...$sameDay('10834-0'),
            ...$sameDay('2160-0'),
            ...$sameDay('4548-4'),
            ...$sameDay('2571-8', self::cited()),
        ]);

        self::assertSame(['2571-8', '4548-4', '2160-0', '10834-0'], array_map(static fn($s): string => $s->loinc, $trends->series));
    }

    public function testFlatAndZeroValuedLinesAreKept(): void
    {
        $trends = LabTrends::fromLabs([
            self::lab('flat', 0.0, '2026-01-01'),
            self::lab('flat', 0.0, '2026-02-01'),
        ]);

        self::assertSame([0.0, 0.0], array_map(static fn($p): float => $p->value, $trends->series[0]->points));
    }

    public function testThePayloadCarriesTheTrendsInTheContractShape(): void
    {
        $trends = LabTrends::fromLabs([
            self::lab(self::LDL, 130.0, '2025-06-01'),
            self::lab(self::LDL, 140.0, '2026-01-01'),
            self::lab(self::LDL, 141.6, '2026-09-15', 'mg/dL', self::cited()),
        ]);
        $assembled = new AssembledFacts(new FactSet([]), null, [], null, $trends);
        $payload = PanelPayload::chartChanged($assembled, str_repeat('ab', 16));

        self::assertSame([], Contracts::violations('chat.chart-changed.response', $payload));
        self::assertSame([], Contracts::violations('trends', $trends->toArray()));
        $shape = $trends->toArray();
        self::assertNull($shape['series'][0]['points'][0]['citation']);
        self::assertSame('42', $shape['series'][0]['points'][2]['citation']['source_id'] ?? null);
    }

    public function testAssembledFactsWithoutTrendsSendsAnEmptyList(): void
    {
        $assembled = new AssembledFacts(new FactSet([]), null);

        self::assertSame(['series' => [], 'more' => 0], $assembled->labTrends()->toArray());
    }
}
