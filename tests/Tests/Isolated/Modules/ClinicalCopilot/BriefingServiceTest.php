<?php

/**
 * BriefingService builds the whole briefing for chart open and the 06:00
 * pre-warm: the guideline cards, then the narration with the vetted passages.
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
use OpenEMR\Modules\ClinicalCopilot\BriefingService;
use OpenEMR\Modules\ClinicalCopilot\Config;
use OpenEMR\Modules\ClinicalCopilot\EvidenceChunk;
use OpenEMR\Modules\ClinicalCopilot\EvidenceSet;
use OpenEMR\Modules\ClinicalCopilot\Fact;
use OpenEMR\Modules\ClinicalCopilot\FactCategory;
use OpenEMR\Modules\ClinicalCopilot\FactSet;
use OpenEMR\Modules\ClinicalCopilot\Guidelines\GuidelineCard;
use OpenEMR\Modules\ClinicalCopilot\Guidelines\GuidelineOutcome;
use OpenEMR\Modules\ClinicalCopilot\Guidelines\GuidelineSection;
use OpenEMR\Modules\ClinicalCopilot\Guidelines\GuidelineStatus;
use OpenEMR\Modules\ClinicalCopilot\Ops\Step;
use OpenEMR\Modules\ClinicalCopilot\Ops\StepRecorder;
use OpenEMR\Modules\ClinicalCopilot\PatientId;
use OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Support\FakeGuidelineSource;
use OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Support\FakeNarrationPipelines;
use OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Support\ModuleAutoload;
use PHPUnit\Framework\TestCase;

/**
 * Drives BriefingService with scripted cards and a real NarrationPipeline over
 * the fake model and an in-memory cache.
 */
final class BriefingServiceTest extends TestCase
{
    /**
     * @codeCoverageIgnore Fixture wiring; runs before coverage attribution.
     */
    public static function setUpBeforeClass(): void
    {
        ModuleAutoload::register();
    }

    private FakeGuidelineSource $guidelines;
    private FakeNarrationPipelines $pipelines;
    private StepRecorder $steps;

    protected function setUp(): void
    {
        $this->guidelines = new FakeGuidelineSource();
        $this->pipelines = new FakeNarrationPipelines();
        $this->steps = new StepRecorder();
    }

    private function service(string $apiKey = 'sk-test'): BriefingService
    {
        return new BriefingService($this->guidelines, $this->pipelines, new Config($apiKey, 'fake-model', 'https://cloud.langfuse.com', '', ''));
    }

    private function med(): Fact
    {
        return new Fact(Fact::idFor('PrescriptionService', 17, 'drug'), 'PrescriptionService', 17, 'drug', 'Metformin 500 MG Oral Tablet', FactCategory::MedicationActive);
    }

    private function assembled(): AssembledFacts
    {
        return new AssembledFacts(new FactSet([$this->med()]), null);
    }

    private static function chunk(string $id): EvidenceChunk
    {
        return new EvidenceChunk($id, 'ada-2025-standards', 'Glycemic goals', 'An A1C goal of less than 7% is appropriate for many adults.', 0.8);
    }

    /** One card the critic judged applicable, one it could not judge, and the sidecar's route. */
    private function twoCards(): GuidelineOutcome
    {
        $section = new GuidelineSection('ok', [
            new GuidelineCard('diabetes', 'Diabetes', [], [], [self::chunk('aaaaaaaaaaaa')], true, 'adult'),
            new GuidelineCard('lipids', 'Lipids', [], [], [self::chunk('bbbbbbbbbbbb')], null, null),
        ], 0, cacheable: false);
        $hop = ['from' => 'supervisor', 'to' => 'evidence_retriever', 'reason' => 'chart_triggers', 'state_keys_changed' => [], 'ms' => 3];
        return new GuidelineOutcome($section, GuidelineStatus::Partial, false, [$hop], []);
    }

    /** @return list<string> */
    private function stepNames(): array
    {
        return array_map(static fn(Step $s): string => $s->name, $this->steps->all());
    }

    public function testWithoutAChatModelTheCardsAreStillBuiltAndThereIsNoNarration(): void
    {
        $this->guidelines->outcome = $this->twoCards();

        $briefing = $this->service('')->brief($this->assembled(), new PatientId(7), new DateTimeImmutable('2026-09-24'), 'corr-1', $this->steps);

        self::assertNull($briefing->narration);
        self::assertNull($briefing->keyRead);
        self::assertCount(2, $briefing->cards->section->cards);
        self::assertSame(['evidence_retriever'], array_map(static fn(array $h): string => $h['to'], $briefing->handoffs()), 'the sidecar route only, no answer stage');
        self::assertSame(0, $this->pipelines->llm->calls);
        self::assertSame(['retrieve_chart_evidence'], $this->stepNames());
    }

    public function testTheCardsAreBuiltForTheDayAndCorrelationIdTheCallerPasses(): void
    {
        $this->service()->brief($this->assembled(), new PatientId(7), new DateTimeImmutable('2026-09-18 00:00'), 'corr-sweep-row', $this->steps);

        self::assertSame(7, $this->guidelines->builds[0]['pid']);
        self::assertSame('2026-09-18 00:00', $this->guidelines->builds[0]['day']);
        self::assertSame('corr-sweep-row', $this->guidelines->builds[0]['correlationId']);
    }

    public function testOnlyTheVettedPassagesAreOfferedAndTheyAreInTheKeyRead(): void
    {
        $this->guidelines->outcome = $this->twoCards();
        $this->pipelines->llm->reply = ['sentences' => [['text' => 'Metformin is on the medication list.', 'fact_ids' => [$this->med()->id]]]];

        $briefing = $this->service()->brief($this->assembled(), new PatientId(7), new DateTimeImmutable('2026-09-24'), 'corr-1', $this->steps);

        self::assertStringContainsString('aaaaaaaaaaaa', $this->pipelines->llm->lastUser);
        self::assertStringNotContainsString('bbbbbbbbbbbb', $this->pipelines->llm->lastUser, 'an unassessed passage never reaches the summary');
        $expected = $this->pipelines->create(new Config('sk-test', 'fake-model', '', '', ''), $this->assembled(), new PatientId(7), null, new StepRecorder())
            ->cacheKey($this->assembled(), new EvidenceSet([self::chunk('aaaaaaaaaaaa')]));
        self::assertSame($expected, $briefing->keyRead);
        self::assertSame($expected, $briefing->narration?->cacheKey, 'a stored narration lives under the key read');
        self::assertSame(1, $briefing->llmAttempts);
    }

    public function testTheRouteIsTheSidecarsHopsThenTheAnswerStage(): void
    {
        $this->guidelines->outcome = $this->twoCards();
        $this->pipelines->llm->reply = ['sentences' => [['text' => 'Metformin is on the medication list.', 'fact_ids' => [$this->med()->id]]]];

        $briefing = $this->service()->brief($this->assembled(), new PatientId(7), new DateTimeImmutable('2026-09-24'), 'corr-1', $this->steps);

        self::assertSame(['evidence_retriever', 'answer_writer', 'verifier', 'done'], array_map(static fn(array $h): string => $h['to'], $briefing->handoffs()));
        self::assertSame('retrieve_chart_evidence', $this->stepNames()[0], 'the cards are built before the narration');
    }

    public function testASecondBriefingOfTheSameChartIsServedFromTheCacheUnderTheSameKey(): void
    {
        $this->pipelines->llm->reply = ['sentences' => [['text' => 'Metformin is on the medication list.', 'fact_ids' => [$this->med()->id]]]];
        $first = $this->service()->brief($this->assembled(), new PatientId(7), new DateTimeImmutable('2026-09-24'), 'corr-1', new StepRecorder());
        $second = $this->service()->brief($this->assembled(), new PatientId(7), new DateTimeImmutable('2026-09-24'), 'corr-2', new StepRecorder());

        self::assertTrue($second->narration?->fromCache);
        self::assertSame($first->keyRead, $second->keyRead);
        self::assertSame(1, $this->pipelines->llm->calls);
    }
}
