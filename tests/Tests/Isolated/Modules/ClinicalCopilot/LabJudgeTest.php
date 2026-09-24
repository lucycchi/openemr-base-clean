<?php

/**
 * Pins the laboratory's own flag in LabJudge.
 *
 * A lab prints H or L beside a result it judged out of range, sometimes
 * against a range the Co-Pilot does not know (a lab-specific or age-specific
 * one). That flag alone must make the result abnormal: dropping it would
 * turn a result the lab flagged into a normal-looking line in the briefing.
 * Found by the kill matrix (mutant M9 survived the first run,
 * docs/designs/golden-set-kill-matrix.md).
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
use OpenEMR\Modules\ClinicalCopilot\LabJudge;
use OpenEMR\Modules\ClinicalCopilot\LabRecord;
use OpenEMR\Modules\ClinicalCopilot\LabVerdict;
use OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Support\ModuleAutoload;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LabJudgeTest extends TestCase
{
    protected function setUp(): void
    {
        ModuleAutoload::register();
    }

    /** A result with no LOINC code and no printed range: the lab's flag is the only signal. */
    private static function lab(string $flag): LabRecord
    {
        return new LabRecord(1, 10, '', 'Special assay', 5.0, 'U/L', new DateTimeImmutable('2026-09-01'), labFlag: $flag);
    }

    #[DataProvider('labFlags')]
    public function testTheLabsOwnFlagMakesAResultAbnormal(string $flag): void
    {
        self::assertSame(LabVerdict::Abnormal, (new LabJudge())->judge(self::lab($flag), 'F', 50)->verdict);
    }

    public function testNoFlagAndNoRangeIsNotAbnormal(): void
    {
        self::assertNotSame(LabVerdict::Abnormal, (new LabJudge())->judge(self::lab(''), 'F', 50)->verdict);
    }

    /**
     * @return array<string, array{string}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function labFlags(): array
    {
        return [
            'H' => ['H'],
            'L' => ['L'],
            'lower-case h' => ['h'],
            'High spelled out' => ['High'],
            'HH' => ['HH'],
        ];
    }
}
