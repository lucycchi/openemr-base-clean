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
use OpenEMR\Modules\ClinicalCopilot\Guidelines\GuidelineStatus;
use OpenEMR\Modules\ClinicalCopilot\PrewarmReceipt;
use OpenEMR\Modules\ClinicalCopilot\PrewarmStatus;
use OpenEMR\Modules\ClinicalCopilot\WarmMissReason;
use OpenEMR\Modules\ClinicalCopilot\WarmOutcome;
use OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Support\ModuleAutoload;
use PHPUnit\Framework\TestCase;

/**
 * WarmOutcome classification. Builds receipts and "now" fact sets by hand
 * and checks each WarmMissReason fires in the right situation, in the
 * right priority order (no row > prompt version > model > hash), plus the
 * subtle viewer-vs-chart distinction: a different opener whose only
 * differences are encounter facts or category flips is ViewerDiffers; a
 * real value change (dose 500 -> 1000) is HashDrift even for a different
 * opener. Ends by pinning the flat log-context shape.
 */
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
    /** The cache key the receipt's narration was stored under. */
    private const KEY = 'cachekey';

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

    /**
     * A receipt as the pre-warm would have written it for these facts (hash + lines derived from them).
     *
     * @param list<Fact> $facts
     */
    private function receipt(array $facts, string $provider = 'drsmith', string $prompt = self::PROMPT, string $model = self::MODEL, ?string $key = self::KEY, PrewarmStatus $status = PrewarmStatus::Warmed, ?GuidelineStatus $guidelines = GuidelineStatus::Built): PrewarmReceipt
    {
        $set = new FactSet($facts);
        return new PrewarmReceipt('run-1', '2026-09-18', 41, 7, $provider, $set->hash(), $key, $prompt, $model, $set->lines(), $status, 2700, true, 'corr-warm', '2026-09-18 06:02:11', $guidelines);
    }

    public function testNoReceiptIsAMissForNoRow(): void
    {
        $outcome = WarmOutcome::evaluate(null, $this->assembled([$this->med()]), 'drsmith', self::PROMPT, self::MODEL, self::KEY, true);

        self::assertFalse($outcome->hit);
        self::assertSame(WarmMissReason::NoRow, $outcome->reason);
    }

    public function testMatchingHashIsAHitWithNoReason(): void
    {
        $facts = [$this->priorVisit(), $this->med()];

        $outcome = WarmOutcome::evaluate($this->receipt($facts), $this->assembled($facts), 'drsmith', self::PROMPT, self::MODEL, self::KEY, true);

        self::assertTrue($outcome->hit);
        self::assertNull($outcome->reason);
        self::assertSame([], $outcome->newFactIds);
        self::assertSame([], $outcome->goneFactIds);
    }

    public function testAMatchingHashStillHitsWhenADifferentUserOpensTheChart(): void
    {
        $facts = [$this->priorVisit(), $this->med()];

        $outcome = WarmOutcome::evaluate($this->receipt($facts), $this->assembled($facts), 'nurse', self::PROMPT, self::MODEL, self::KEY, true);

        self::assertTrue($outcome->hit);
    }

    public function testPromptVersionBumpSinceTheReceiptIsReportedFirst(): void
    {
        $facts = [$this->med()];

        $outcome = WarmOutcome::evaluate($this->receipt($facts, prompt: '2026-09-18.1'), $this->assembled([$this->lab()]), 'drsmith', self::PROMPT, self::MODEL, self::KEY, true);

        self::assertSame(WarmMissReason::PromptVersion, $outcome->reason);
    }

    public function testModelChangeSinceTheReceiptIsReported(): void
    {
        $facts = [$this->med()];

        $outcome = WarmOutcome::evaluate($this->receipt($facts, model: 'gpt-4o'), $this->assembled($facts), 'drsmith', self::PROMPT, self::MODEL, self::KEY, true);

        self::assertSame(WarmMissReason::ModelChanged, $outcome->reason);
    }

    public function testNewLabSinceTheWarmIsHashDriftWithTheNewFactListed(): void
    {
        $warmed = [$this->priorVisit(), $this->med()];
        $now = [$this->priorVisit(), $this->med(), $this->lab()];

        $outcome = WarmOutcome::evaluate($this->receipt($warmed), $this->assembled($now), 'drsmith', self::PROMPT, self::MODEL, 'key-of-todays-facts', false);

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

        $outcome = WarmOutcome::evaluate($this->receipt($warmed, 'drsmith'), $this->assembled($now), 'nurse', self::PROMPT, self::MODEL, 'key-of-todays-facts', false);

        self::assertSame(WarmMissReason::ViewerDiffers, $outcome->reason);
    }

    public function testADifferentViewerWithACategoryOnlyChangeIsStillViewerDiffers(): void
    {
        // Hiding an encounter moves "since" and can flip a medication from
        // new to active with the same id and value.
        $warmed = [$this->priorVisit('2026-09-10: Psychiatry'), $this->med(category: FactCategory::MedicationActive)];
        $now = [$this->priorVisit('2026-09-01: Follow-up'), $this->med(category: FactCategory::MedicationNew)];

        $outcome = WarmOutcome::evaluate($this->receipt($warmed, 'drsmith'), $this->assembled($now), 'nurse', self::PROMPT, self::MODEL, 'key-of-todays-facts', false);

        self::assertSame(WarmMissReason::ViewerDiffers, $outcome->reason);
    }

    public function testADifferentViewerWithAChangedMedicationIsHashDrift(): void
    {
        $warmed = [$this->priorVisit(), $this->med('Metformin 500 MG Oral Tablet')];
        $now = [$this->priorVisit(), $this->med('Metformin 1000 MG Oral Tablet')];

        $outcome = WarmOutcome::evaluate($this->receipt($warmed, 'drsmith'), $this->assembled($now), 'nurse', self::PROMPT, self::MODEL, 'key-of-todays-facts', false);

        self::assertSame(WarmMissReason::HashDrift, $outcome->reason);
    }

    public function testLogContextIsFlatAndNamesEverything(): void
    {
        $warmed = [$this->med()];
        $now = [$this->med(), $this->lab()];

        $outcome = WarmOutcome::evaluate($this->receipt($warmed), $this->assembled($now), 'drsmith', self::PROMPT, self::MODEL, 'key-of-todays-facts', false);

        self::assertSame([
            'warm_result' => 'miss',
            'warm_reason' => 'hash_drift',
            'warm_guideline_status' => 'built',
            'warm_run_id' => 'run-1',
            'warm_provider' => 'drsmith',
            'warm_generated_at' => '2026-09-18 06:02:11',
            'warm_new_fact_ids' => $this->lab()->id,
            'warm_gone_fact_ids' => '',
        ], $outcome->toLogContext());
    }

    public function testAFailedWarmIsReportedAsWarmFailed(): void
    {
        $facts = [$this->med()];

        $outcome = WarmOutcome::evaluate($this->receipt($facts, key: null, status: PrewarmStatus::Error), $this->assembled($facts), 'drsmith', self::PROMPT, self::MODEL, self::KEY, false);

        self::assertFalse($outcome->hit);
        self::assertSame(WarmMissReason::WarmFailed, $outcome->reason);
    }

    public function testMatchingFactsAreNotAHitWhenTheKeyReadIsNotTheReceiptsKey(): void
    {
        // The 06:00 run had no guideline cards (the sidecar was down); chart open has them,
        // so it read a key the sweep never wrote. Before 0.1.5 this counted as a hit.
        $facts = [$this->med()];

        $outcome = WarmOutcome::evaluate($this->receipt($facts, guidelines: GuidelineStatus::Unavailable), $this->assembled($facts), 'drsmith', self::PROMPT, self::MODEL, 'key-with-cards', false);

        self::assertFalse($outcome->hit);
        self::assertSame(WarmMissReason::GuidelineCardsDiffer, $outcome->reason);
        self::assertSame('unavailable', $outcome->toLogContext()['warm_guideline_status']);
    }

    public function testTheRightKeyIsNotAHitUnlessItWasServedFromTheCache(): void
    {
        $facts = [$this->med()];

        $outcome = WarmOutcome::evaluate($this->receipt($facts), $this->assembled($facts), 'drsmith', self::PROMPT, self::MODEL, self::KEY, false);

        self::assertFalse($outcome->hit);
        self::assertSame(WarmMissReason::CacheEntryMissing, $outcome->reason);
    }

    public function testAReceiptWithNoKeyIsNeverAHit(): void
    {
        // A receipt written before 0.1.5: its recomputed key was cleared by the upgrade.
        $facts = [$this->med()];

        $outcome = WarmOutcome::evaluate($this->receipt($facts, key: null, guidelines: null), $this->assembled($facts), 'drsmith', self::PROMPT, self::MODEL, self::KEY, true);

        self::assertFalse($outcome->hit);
        self::assertSame(WarmMissReason::ReceiptKeyUnknown, $outcome->reason);
        self::assertSame('unknown', $outcome->toLogContext()['warm_guideline_status']);
    }
}
