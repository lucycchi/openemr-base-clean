<?php

/**
 * Verifier rules for LLM narration: every sentence must reference real
 * facts and may not introduce numbers or dates that are not in them.
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
use OpenEMR\Modules\ClinicalCopilot\EvidenceChunk;
use OpenEMR\Modules\ClinicalCopilot\EvidenceSet;
use OpenEMR\Modules\ClinicalCopilot\Fact;
use OpenEMR\Modules\ClinicalCopilot\FactCategory;
use OpenEMR\Modules\ClinicalCopilot\FactSet;
use OpenEMR\Modules\ClinicalCopilot\Narration;
use OpenEMR\Modules\ClinicalCopilot\Sentence;
use OpenEMR\Modules\ClinicalCopilot\Verifier;
use PHPUnit\Framework\TestCase;

/**
 * The Verifier's three rules, one or two tests each:
 *   - no citation -> stripped; unknown id -> stripped;
 *   - a number/date in the text must appear in a cited fact (7.8 kept,
 *     9.1 stripped; right date kept, wrong date stripped), and may come
 *     from any of several cited facts;
 *   - inline "[id]" echoes are not mistaken for numeric literals, even
 *     when the id happens to be all digits;
 *   - all-stripped is a total failure, but an empty narration is not.
 */
final class VerifierTest extends TestCase
{
    /**
     * @codeCoverageIgnore Fixture wiring; runs before coverage attribution.
     */
    public static function setUpBeforeClass(): void
    {
        // Same job as Support\ModuleAutoload::register(), inlined (this test predates the helper).
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

    private function facts(): FactSet
    {
        return new FactSet([
            new Fact('a1b2c3', 'PrescriptionService', 17, 'drug', 'Lisinopril 10 MG Oral Tablet', FactCategory::MedicationNew),
            new Fact('d4e5f6', 'ObservationLabService', 902, 'result', '7.8 %', FactCategory::LabAbnormal),
            new Fact('0a0b0c', 'EncounterService', 1476, 'date', '2026-09-09', FactCategory::Encounter),
        ]);
    }

    public function testSentenceWithNoFactReferenceIsStripped(): void
    {
        $narration = new Narration([new Sentence('Patient is doing well overall.', [])]);

        $result = (new Verifier())->verify($narration, $this->facts());

        self::assertSame([], $result->kept());
        self::assertSame(['Patient is doing well overall.'], array_map(fn($s) => $s->text, $result->stripped()));
        self::assertSame(1, $result->strippedCount());
    }

    public function testSentenceCitingUnknownFactIdIsStripped(): void
    {
        $narration = new Narration([new Sentence('Started a new medication.', ['ffffff'])]);

        $result = (new Verifier())->verify($narration, $this->facts());

        self::assertSame([], $result->kept());
        self::assertSame(1, $result->strippedCount());
    }

    public function testSentenceCitingKnownFactWithoutLiteralsIsKept(): void
    {
        $sentence = new Sentence('A new blood pressure medication was started.', ['a1b2c3']);

        $result = (new Verifier())->verify(new Narration([$sentence]), $this->facts());

        self::assertSame([$sentence], $result->kept());
        self::assertSame(0, $result->strippedCount());
    }

    public function testNumberPresentInCitedFactValueIsKept(): void
    {
        $sentence = new Sentence('A1c came back at 7.8 %, above target.', ['d4e5f6']);

        $result = (new Verifier())->verify(new Narration([$sentence]), $this->facts());

        self::assertSame([$sentence], $result->kept());
    }

    public function testNumberAbsentFromCitedFactValuesIsStripped(): void
    {
        $sentence = new Sentence('A1c came back at 9.1 %, above target.', ['d4e5f6']);

        $result = (new Verifier())->verify(new Narration([$sentence]), $this->facts());

        self::assertSame([], $result->kept());
        self::assertSame(1, $result->strippedCount());
    }

    public function testDateAbsentFromCitedFactValuesIsStripped(): void
    {
        $sentence = new Sentence('Last seen on 2026-08-01.', ['0a0b0c']);

        $result = (new Verifier())->verify(new Narration([$sentence]), $this->facts());

        self::assertSame(1, $result->strippedCount());
    }

    public function testDatePresentInCitedFactValueIsKept(): void
    {
        $sentence = new Sentence('Last seen on 2026-09-09.', ['0a0b0c']);

        $result = (new Verifier())->verify(new Narration([$sentence]), $this->facts());

        self::assertSame([$sentence], $result->kept());
    }

    public function testAllSentencesStrippedIsReportedAsTotalFailure(): void
    {
        $narration = new Narration([
            new Sentence('Doing well.', []),
            new Sentence('Started something.', ['ffffff']),
        ]);

        $result = (new Verifier())->verify($narration, $this->facts());

        self::assertTrue($result->isTotalFailure());
    }

    public function testEmptyNarrationIsNotATotalFailure(): void
    {
        $result = (new Verifier())->verify(new Narration([]), $this->facts());

        self::assertFalse($result->isTotalFailure());
    }

    public function testInlineCitedFactIdMadeOfDigitsIsNotTreatedAsANumber(): void
    {
        $facts = new FactSet([new Fact('12345678', 'PrescriptionService', 1, 'drug', 'Aspirin 81 MG', FactCategory::MedicationActive)]);
        $sentence = new Sentence('Aspirin is active [12345678].', ['12345678']);

        $result = (new Verifier())->verify(new Narration([$sentence]), $facts);

        self::assertSame([$sentence], $result->kept());
    }

    public function testCommaSeparatedInlineCitationGroupIsNotTreatedAsNumbers(): void
    {
        $facts = new FactSet([
            new Fact('12345678', 'PrescriptionService', 1, 'drug', 'Aspirin 81 MG', FactCategory::MedicationActive),
            new Fact('87654321', 'PrescriptionService', 2, 'drug', 'Metformin 500 MG', FactCategory::MedicationActive),
        ]);
        $sentence = new Sentence('Aspirin and metformin are active [12345678, 87654321].', ['12345678', '87654321']);

        $result = (new Verifier())->verify(new Narration([$sentence]), $facts);

        self::assertSame([$sentence], $result->kept());
    }

    public function testNumberMayComeFromAnyCitedFactInTheSentence(): void
    {
        $sentence = new Sentence('Since 2026-09-09, A1c is 7.8 %.', ['0a0b0c', 'd4e5f6']);

        $result = (new Verifier())->verify(new Narration([$sentence]), $this->facts());

        self::assertSame([$sentence], $result->kept());
    }

    // ---- Week 2: guideline chunks as citation targets --------------------

    private function evidence(): \OpenEMR\Modules\ClinicalCopilot\EvidenceSet
    {
        return new \OpenEMR\Modules\ClinicalCopilot\EvidenceSet([
            new \OpenEMR\Modules\ClinicalCopilot\EvidenceChunk('a1b2c3d4e5f6', 'ada-2025-standards', 'Glycemic targets', 'An A1C goal below 7 percent is appropriate for many nonpregnant adults without significant hypoglycemia.', 0.9, 'ADA Standards 2025'),
        ]);
    }

    public function testASentenceMayCiteAGuidelineChunkAndItsNumbersAreCheckedAgainstTheQuote(): void
    {
        $facts = new FactSet([]);
        $ok = new Narration([new Sentence('Guidelines suggest an A1C goal below 7 percent for many adults.', ['a1b2c3d4e5f6'])]);
        $result = (new Verifier())->verify($ok, $facts, $this->evidence());
        self::assertCount(1, $result->kept());

        $wrong = new Narration([new Sentence('Guidelines suggest an A1C goal below 6.5 percent.', ['a1b2c3d4e5f6'])]);
        $result = (new Verifier())->verify($wrong, $facts, $this->evidence());
        self::assertCount(0, $result->kept(), '6.5 is not in the cited passage');
    }

    public function testAnUnknownChunkIdIsStrippedAndWithoutEvidenceChunkIdsAreUnknown(): void
    {
        $facts = new FactSet([]);
        $n = new Narration([new Sentence('Guidelines say something.', ['ffffffffffff'])]);
        self::assertCount(0, (new Verifier())->verify($n, $facts, $this->evidence())->kept());
        $n2 = new Narration([new Sentence('Guidelines suggest an A1C goal below 7 percent.', ['a1b2c3d4e5f6'])]);
        self::assertCount(0, (new Verifier())->verify($n2, $facts)->kept(), 'no evidence set: a chunk id is an unknown citation');
    }

    public function testInlineTwelveCharIdsAreTreatedAsReferencesNotNumbers(): void
    {
        $facts = new FactSet([]);
        $n = new Narration([new Sentence('A1C goal below 7 percent [a1b2c3d4e5f6].', ['a1b2c3d4e5f6'])]);
        self::assertCount(1, (new Verifier())->verify($n, $facts, $this->evidence())->kept());
    }

    public function testASentenceCitingBothAFactAndAGuidelineChunkIsStripped(): void
    {
        $facts = new FactSet([new Fact('9e41cdd9', 'ObservationLabService', 901, 'result', 'LDL Cholesterol 165 mg/dL on 2026-09-10', FactCategory::LabAbnormal)]);
        $evidence = new EvidenceSet([new EvidenceChunk('a1b2c3d4e5f6', 'acc-aha-2018-cholesterol', 'T > S', 'Moderate-intensity statin therapy is recommended for adults 40 to 75.', 0.9)]);
        $narration = new Narration([
            new Sentence('The LDL cholesterol was 165 mg/dL on 2026-09-10.', ['9e41cdd9']),
            new Sentence('An LDL of 165 mg/dL means statin therapy is recommended for this patient.', ['9e41cdd9', 'a1b2c3d4e5f6']),
            new Sentence('The guideline recommends moderate-intensity statin therapy for adults 40 to 75.', ['a1b2c3d4e5f6']),
        ]);

        $verified = (new Verifier())->verify($narration, $facts, $evidence);

        self::assertCount(2, $verified->kept());
        self::assertSame(1, $verified->strippedCount());
        self::assertSame(['9e41cdd9', 'a1b2c3d4e5f6'], $verified->stripped()[0]->factIds);
    }
}
