<?php

/**
 * FactSet lines are the receipt's record of what was warmed, in a form that
 * can be diffed against a later assembly.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Modules\ClinicalCopilot;

use OpenEMR\Modules\ClinicalCopilot\Fact;
use OpenEMR\Modules\ClinicalCopilot\FactCategory;
use OpenEMR\Modules\ClinicalCopilot\FactSet;
use OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Support\ModuleAutoload;
use PHPUnit\Framework\TestCase;

final class FactSetTest extends TestCase
{
    /**
     * @codeCoverageIgnore Fixture wiring; runs before coverage attribution.
     */
    public static function setUpBeforeClass(): void
    {
        ModuleAutoload::register();
    }

    public function testLinesAreSortedAndCarryIdServiceCategoryAndValue(): void
    {
        $med = new Fact('zz000001', 'PrescriptionService', 17, 'drug', 'Metformin 500 MG', FactCategory::MedicationActive);
        $visit = new Fact('aa000001', 'EncounterService', 99, 'date', '2026-09-01: Follow-up', FactCategory::PriorVisit);

        $lines = (new FactSet([$med, $visit]))->lines();

        self::assertSame([
            "aa000001\tEncounterService\tprior_visit\t2026-09-01: Follow-up",
            "zz000001\tPrescriptionService\tmedication_active\tMetformin 500 MG",
        ], $lines);
    }

    public function testLinesDoNotChangeTheHash(): void
    {
        $set = new FactSet([new Fact('a1b2c3d4', 'PrescriptionService', 17, 'drug', 'Metformin 500 MG', FactCategory::MedicationActive)]);

        self::assertSame(hash('sha256', "a1b2c3d4\tmedication_active\tMetformin 500 MG"), $set->hash());
    }
}
