<?php

/**
 * WarmOutcome explains, at chart open, whether the pre-warm receipt for this
 * patient matched what was just assembled, and if not, why.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Modules\ClinicalCopilot;

use OpenEMR\Modules\ClinicalCopilot\AssembledFacts;
use OpenEMR\Modules\ClinicalCopilot\Fact;
use OpenEMR\Modules\ClinicalCopilot\FactCategory;
use OpenEMR\Modules\ClinicalCopilot\FactSet;
use OpenEMR\Modules\ClinicalCopilot\PrewarmReceipt;
use OpenEMR\Modules\ClinicalCopilot\PrewarmStatus;
use OpenEMR\Modules\ClinicalCopilot\WarmMissReason;
use OpenEMR\Modules\ClinicalCopilot\WarmOutcome;
use OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Support\ModuleAutoload;
use PHPUnit\Framework\TestCase;

final class WarmOutcomeTest extends TestCase
{
    /**
     * @codeCoverageIgnore Fixture wiring; runs before coverage attribution.
     */
    public static function setUpBeforeClass(): void
    {
        ModuleAutoload::register();
    }

    private const PROMPT = '2026-09-18.2';
    private const MODEL = 'gpt-4o-mini';

    private function med(string $drug = 'Metformin 500 MG Oral Tablet', FactCategory $category = FactCategory::MedicationActive): Fact
    {
        return new Fact(Fact::idFor('PrescriptionService', 17, 'drug'), 'PrescriptionService', 17, 'drug', $drug, $category);
    }

    private function priorVisit(string $value = '2026-09-01: Follow-up'): Fact
    {
        return new Fact(Fact::idFor('EncounterService', 99, 'date'), 'EncounterService', 99, 'date', $value, FactCategory::PriorVisit);
    }

    private function lab(): Fact
    {
        return new Fact(Fact::idFor('ObservationLabService', 901, 'result'), 'ObservationLabService', 901, 'result', 'Hemoglobin A1c 7.8 % on 2026-09-17 (above reference range 4-5.6 %)', FactCategory::LabAbnormal);
    }

    /** @param list<Fact> $facts */
    private function assembled(array $facts): AssembledFacts
    {
        return new AssembledFacts(new FactSet($facts), null);
    }

    /** @param list<Fact> $facts */
    private function receipt(array $facts, string $provider = 'drsmith', string $prompt = self::PROMPT, string $model = self::MODEL): PrewarmReceipt
    {
        $set = new FactSet($facts);
        return new PrewarmReceipt('run-1', '2026-09-18', 41, 7, $provider, $set->hash(), 'cachekey', $prompt, $model, $set->lines(), PrewarmStatus::Warmed, 2700, true, 'corr-warm', '2026-09-18 06:02:11');
    }

    public function testNoReceiptIsAMissForNoRow(): void
    {
        $outcome = WarmOutcome::evaluate(null, $this->assembled([$this->med()]), 'drsmith', self::PROMPT, self::MODEL);

        self::assertFalse($outcome->hit);
        self::assertSame(WarmMissReason::NoRow, $outcome->reason);
    }

    public function testMatchingHashIsAHitWithNoReason(): void
    {
        $facts = [$this->priorVisit(), $this->med()];

        $outcome = WarmOutcome::evaluate($this->receipt($facts), $this->assembled($facts), 'drsmith', self::PROMPT, self::MODEL);

        self::assertTrue($outcome->hit);
        self::assertNull($outcome->reason);
        self::assertSame([], $outcome->newFactIds);
        self::assertSame([], $outcome->goneFactIds);
    }

    public function testAMatchingHashStillHitsWhenADifferentUserOpensTheChart(): void
    {
        $facts = [$this->priorVisit(), $this->med()];

        $outcome = WarmOutcome::evaluate($this->receipt($facts), $this->assembled($facts), 'nurse', self::PROMPT, self::MODEL);

        self::assertTrue($outcome->hit);
    }

    public function testPromptVersionBumpSinceTheReceiptIsReportedFirst(): void
    {
        $facts = [$this->med()];

        $outcome = WarmOutcome::evaluate($this->receipt($facts, prompt: '2026-09-18.1'), $this->assembled([$this->lab()]), 'drsmith', self::PROMPT, self::MODEL);

        self::assertSame(WarmMissReason::PromptVersion, $outcome->reason);
    }

    public function testModelChangeSinceTheReceiptIsReported(): void
    {
        $facts = [$this->med()];

        $outcome = WarmOutcome::evaluate($this->receipt($facts, model: 'gpt-4o'), $this->assembled($facts), 'drsmith', self::PROMPT, self::MODEL);

        self::assertSame(WarmMissReason::ModelChanged, $outcome->reason);
    }

    public function testNewLabSinceTheWarmIsHashDriftWithTheNewFactListed(): void
    {
        $warmed = [$this->priorVisit(), $this->med()];
        $now = [$this->priorVisit(), $this->med(), $this->lab()];

        $outcome = WarmOutcome::evaluate($this->receipt($warmed), $this->assembled($now), 'drsmith', self::PROMPT, self::MODEL);

        self::assertSame(WarmMissReason::HashDrift, $outcome->reason);
        self::assertSame([$this->lab()->id], $outcome->newFactIds);
        self::assertSame([], $outcome->goneFactIds);
    }

    public function testADifferentViewerWhoseOnlyDifferenceIsAnEncounterIsViewerDiffers(): void
    {
        // The scheduled provider could see a sensitive encounter the opener
        // cannot: the prior visit fact differs and nothing else does.
        $warmed = [$this->priorVisit('2026-09-10: Psychiatry'), $this->med()];
        $now = [$this->priorVisit('2026-09-01: Follow-up'), $this->med()];

        $outcome = WarmOutcome::evaluate($this->receipt($warmed, 'drsmith'), $this->assembled($now), 'nurse', self::PROMPT, self::MODEL);

        self::assertSame(WarmMissReason::ViewerDiffers, $outcome->reason);
    }

    public function testADifferentViewerWithACategoryOnlyChangeIsStillViewerDiffers(): void
    {
        // Hiding an encounter moves "since" and can flip a medication from
        // new to active with the same id and value.
        $warmed = [$this->priorVisit('2026-09-10: Psychiatry'), $this->med(category: FactCategory::MedicationActive)];
        $now = [$this->priorVisit('2026-09-01: Follow-up'), $this->med(category: FactCategory::MedicationNew)];

        $outcome = WarmOutcome::evaluate($this->receipt($warmed, 'drsmith'), $this->assembled($now), 'nurse', self::PROMPT, self::MODEL);

        self::assertSame(WarmMissReason::ViewerDiffers, $outcome->reason);
    }

    public function testADifferentViewerWithAChangedMedicationIsHashDrift(): void
    {
        $warmed = [$this->priorVisit(), $this->med('Metformin 500 MG Oral Tablet')];
        $now = [$this->priorVisit(), $this->med('Metformin 1000 MG Oral Tablet')];

        $outcome = WarmOutcome::evaluate($this->receipt($warmed, 'drsmith'), $this->assembled($now), 'nurse', self::PROMPT, self::MODEL);

        self::assertSame(WarmMissReason::HashDrift, $outcome->reason);
    }

    public function testLogContextIsFlatAndNamesEverything(): void
    {
        $warmed = [$this->med()];
        $now = [$this->med(), $this->lab()];

        $outcome = WarmOutcome::evaluate($this->receipt($warmed), $this->assembled($now), 'drsmith', self::PROMPT, self::MODEL);

        self::assertSame([
            'warm_result' => 'miss',
            'warm_reason' => 'hash_drift',
            'warm_run_id' => 'run-1',
            'warm_provider' => 'drsmith',
            'warm_generated_at' => '2026-09-18 06:02:11',
            'warm_new_fact_ids' => $this->lab()->id,
            'warm_gone_fact_ids' => '',
        ], $outcome->toLogContext());
    }
}
