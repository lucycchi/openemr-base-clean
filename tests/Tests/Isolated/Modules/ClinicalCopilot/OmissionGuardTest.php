<?php

/**
 * Omission guard: must-surface facts the narration failed to cite are reported
 * so they can be appended deterministically.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Modules\ClinicalCopilot;

use Composer\Autoload\ClassLoader;
use OpenEMR\Modules\ClinicalCopilot\Fact;
use OpenEMR\Modules\ClinicalCopilot\FactCategory;
use OpenEMR\Modules\ClinicalCopilot\FactSet;
use OpenEMR\Modules\ClinicalCopilot\OmissionGuard;
use OpenEMR\Modules\ClinicalCopilot\Sentence;
use OpenEMR\Modules\ClinicalCopilot\VerificationResult;
use PHPUnit\Framework\TestCase;

final class OmissionGuardTest extends TestCase
{
    /**
     * @codeCoverageIgnore Fixture wiring; runs before coverage attribution.
     */
    public static function setUpBeforeClass(): void
    {
        $loaders = ClassLoader::getRegisteredLoaders();
        $loader = reset($loaders);
        if (!$loader instanceof ClassLoader) {
            self::fail('Composer ClassLoader not available to register module autoload prefix.');
        }
        $loader->addPsr4(
            'OpenEMR\\Modules\\ClinicalCopilot\\',
            dirname(__DIR__, 5) . '/interface/modules/custom_modules/oe-module-clinical-copilot/src/'
        );
    }

    private Fact $newAllergy;
    private Fact $newMed;
    private Fact $activeMed;

    protected function setUp(): void
    {
        $this->newAllergy = new Fact('al0001', 'AllergyIntoleranceService', 812, 'title', 'penicillin', FactCategory::AllergyNew);
        $this->newMed = new Fact('rx0001', 'PrescriptionService', 17, 'drug', 'Lisinopril 10 MG Oral Tablet', FactCategory::MedicationNew);
        $this->activeMed = new Fact('rx0002', 'PrescriptionService', 18, 'drug', 'Metformin 500 MG Oral Tablet', FactCategory::MedicationActive);
    }

    private function facts(): FactSet
    {
        return new FactSet([$this->newAllergy, $this->newMed, $this->activeMed]);
    }

    public function testMustSurfaceFactNotCitedByAnyKeptSentenceIsReported(): void
    {
        $verified = new VerificationResult(
            [new Sentence('Started a new blood pressure medication.', ['rx0001'])],
            []
        );

        $omitted = (new OmissionGuard())->omitted($verified, $this->facts());

        self::assertSame([$this->newAllergy], $omitted);
    }

    public function testCitedMustSurfaceFactIsNotReported(): void
    {
        $verified = new VerificationResult(
            [new Sentence('New penicillin allergy; new medication.', ['al0001', 'rx0001'])],
            []
        );

        self::assertSame([], (new OmissionGuard())->omitted($verified, $this->facts()));
    }

    public function testNonMustSurfaceFactIsNeverReported(): void
    {
        $verified = new VerificationResult(
            [new Sentence('New allergy and new medication noted.', ['al0001', 'rx0001'])],
            []
        );

        self::assertSame([], (new OmissionGuard())->omitted($verified, $this->facts()));
    }

    public function testCitationInAStrippedSentenceDoesNotCount(): void
    {
        $verified = new VerificationResult(
            [],
            [new Sentence('New penicillin allergy on 2026-01-01.', ['al0001'])]
        );

        $omitted = (new OmissionGuard())->omitted($verified, $this->facts());

        self::assertSame([$this->newAllergy, $this->newMed], $omitted);
    }
}
