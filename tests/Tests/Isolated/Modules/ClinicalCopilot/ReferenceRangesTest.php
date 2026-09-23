<?php

/**
 * Pins the reference-range table the briefing judges lab results against:
 * every analyte the extractor can code has a range, every range names its
 * source, and sex-specific rows resolve for male, female and unknown.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Modules\ClinicalCopilot;

use OpenEMR\Modules\ClinicalCopilot\ReferenceRanges;
use OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Support\ModuleAutoload;
use PHPUnit\Framework\TestCase;

final class ReferenceRangesTest extends TestCase
{
    private const MODULE = __DIR__ . '/../../../../../interface/modules/custom_modules/oe-module-clinical-copilot';

    public static function setUpBeforeClass(): void
    {
        ModuleAutoload::register();
    }

    /** @return list<string> distinct LOINC codes the anchor step can assign */
    private static function mappedLoincCodes(): array
    {
        $map = json_decode((string) file_get_contents(self::MODULE . '/contracts/loinc_map.json'), true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($map);
        self::assertIsArray($map['analytes']);
        $codes = [];
        foreach ($map['analytes'] as $entry) {
            self::assertIsArray($entry);
            self::assertIsString($entry['loinc']);
            $codes[$entry['loinc']] = true;
        }
        return array_keys($codes);
    }

    public function testEveryLoincInTheMapHasARange(): void
    {
        $ranges = new ReferenceRanges();
        $missing = array_filter(self::mappedLoincCodes(), static fn(string $loinc): bool => $ranges->range($loinc) === null);
        self::assertSame([], array_values($missing), 'analytes in loinc_map.json with no reference range');
    }

    public function testEveryRangeHasASourceAndAUnit(): void
    {
        foreach ((new ReferenceRanges())->all() as $loinc => $rows) {
            foreach ($rows as $row) {
                self::assertNotSame('', $row->source, "$loinc has a range without a source");
                self::assertNotSame('', $row->unit, "$loinc has a range without a unit");
                self::assertTrue($row->low !== null || $row->high !== null, "$loinc has a range with no bound at all");
                if ($row->low !== null && $row->high !== null) {
                    self::assertLessThan($row->high, $row->low, "$loinc low must be below high");
                }
            }
        }
    }

    public function testSexSpecificRowsResolveForMaleFemaleAndUnknown(): void
    {
        $ranges = new ReferenceRanges();
        $male = $ranges->range('718-7', 'M');
        $female = $ranges->range('718-7', 'F');
        $unknown = $ranges->range('718-7', null);
        self::assertNotNull($male);
        self::assertNotNull($female);
        self::assertNotNull($unknown);
        self::assertSame(13.5, $male->low);
        self::assertSame(17.5, $male->high);
        self::assertSame(12.0, $female->low);
        self::assertSame(15.5, $female->high);
        // Unknown sex: the widest union, so nobody is flagged by a sex they may not be.
        self::assertSame(12.0, $unknown->low);
        self::assertSame(17.5, $unknown->high);
    }

    public function testAnalyteWithoutSexSpecificRowsIgnoresSex(): void
    {
        $ranges = new ReferenceRanges();
        self::assertEquals($ranges->range('2823-3', 'M'), $ranges->range('2823-3', 'F'));
        self::assertSame(3.5, $ranges->range('2823-3', null)?->low);
    }

    public function testLegacyForReturnsTheSameTriple(): void
    {
        self::assertSame([4.0, 5.6, '%'], (new ReferenceRanges())->for('4548-4'));
        self::assertNull((new ReferenceRanges())->for('99999-9'));
        // One-sided intervals have no legacy triple; range() still describes them.
        self::assertNull((new ReferenceRanges())->for('62238-1'));
        self::assertSame('60 or above', (new ReferenceRanges())->range('62238-1')?->describe());
    }

    public function testPanicBoundsAreOptional(): void
    {
        $potassium = (new ReferenceRanges())->range('2823-3');
        $tsh = (new ReferenceRanges())->range('3016-3');
        self::assertNotNull($potassium);
        self::assertNotNull($tsh);
        self::assertSame(6.0, $potassium->panicHigh);
        self::assertSame(2.8, $potassium->panicLow);
        self::assertNull($tsh->panicHigh);
        self::assertNull($tsh->panicLow);
    }

    public function testVersionComesFromTheFile(): void
    {
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}\.\d+$/', ReferenceRanges::VERSION);
        $file = json_decode((string) file_get_contents(self::MODULE . '/contracts/reference_ranges.json'), true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($file);
        self::assertSame(ReferenceRanges::VERSION, $file['version'], 'bump ReferenceRanges::VERSION together with the file');
    }
}
