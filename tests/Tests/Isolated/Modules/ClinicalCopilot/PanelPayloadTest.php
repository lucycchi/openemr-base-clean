<?php

/**
 * PanelPayload: the JSON contract between chat.php and the panel.
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
use OpenEMR\Modules\ClinicalCopilot\AnswerResult;
use OpenEMR\Modules\ClinicalCopilot\AssembledFacts;
use OpenEMR\Modules\ClinicalCopilot\BriefingResult;
use OpenEMR\Modules\ClinicalCopilot\EncounterRecord;
use OpenEMR\Modules\ClinicalCopilot\Fact;
use OpenEMR\Modules\ClinicalCopilot\FactCategory;
use OpenEMR\Modules\ClinicalCopilot\FactSet;
use OpenEMR\Modules\ClinicalCopilot\PanelPayload;
use OpenEMR\Modules\ClinicalCopilot\Sentence;
use OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Support\ModuleAutoload;
use PHPUnit\Framework\TestCase;

final class PanelPayloadTest extends TestCase
{
    /**
     * @codeCoverageIgnore Fixture wiring; runs before coverage attribution.
     */
    public static function setUpBeforeClass(): void
    {
        ModuleAutoload::register();
    }

    private Fact $med;
    private Fact $allergy;

    protected function setUp(): void
    {
        $this->med = new Fact('rx0001', 'PrescriptionService', 17, 'drug', 'Lisinopril 10 MG', FactCategory::MedicationNew);
        $this->allergy = new Fact('al0001', 'AllergyIntoleranceService', 812, 'title', 'penicillin', FactCategory::AllergyNew);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<mixed>
     */
    private function section(array $payload, string $key): array
    {
        $section = $payload[$key] ?? null;
        self::assertIsArray($section);
        return $section;
    }

    private function assembled(?EncounterRecord $prior): AssembledFacts
    {
        return new AssembledFacts(new FactSet([$this->med, $this->allergy]), $prior);
    }

    public function testBriefingPayloadGroupsFactsAndCarriesNarrationAndCorrelationId(): void
    {
        $prior = new EncounterRecord(99, new DateTimeImmutable('2026-09-01 10:00:00'), '', 'Follow-up');
        $briefing = new BriefingResult(
            [new Sentence('A new medication was started.', ['rx0001'])],
            1,
            [$this->allergy],
            null,
            false,
            false,
            600,
            90,
        );

        $payload = PanelPayload::briefing($this->assembled($prior), $briefing, 'corr-123');
        $facts = $this->section($payload, 'facts');
        $narration = $this->section($payload, 'narration');

        self::assertSame('corr-123', $payload['correlation_id']);
        self::assertSame('2026-09-01', $payload['prior_visit']);
        self::assertSame($this->assembled($prior)->facts()->hash(), $payload['facts_hash']);
        self::assertSame(['id' => 'rx0001', 'category' => 'medication_new', 'value' => 'Lisinopril 10 MG', 'source' => 'PrescriptionService#17.drug', 'must_surface' => true], $facts[0]);
        self::assertSame([['text' => 'A new medication was started.', 'fact_ids' => ['rx0001']]], $narration['sentences']);
        self::assertSame(1, $narration['stripped']);
        self::assertSame(['al0001'], $narration['omitted_fact_ids']);
        self::assertNull($narration['status']);
        self::assertFalse($narration['from_cache']);
        self::assertFalse($narration['total_failure']);
        self::assertSame(['prompt' => 600, 'completion' => 90], $narration['tokens']);
        self::assertNull($narration['generated_at']);
    }

    public function testACachedBriefingCarriesItsGenerationTime(): void
    {
        $briefing = new BriefingResult([], 0, [], null, true, false, 0, 0, '2026-09-18T06:02:11-07:00');

        $narration = $this->section(PanelPayload::briefing($this->assembled(null), $briefing, 'corr-1'), 'narration');

        self::assertTrue($narration['from_cache']);
        self::assertSame('2026-09-18T06:02:11-07:00', $narration['generated_at']);
    }

    public function testFirstVisitHasNoPriorAndStatusIsPassedThrough(): void
    {
        $briefing = new BriefingResult([], 0, [$this->med, $this->allergy], 'AI summary unavailable: timed out', false, false, 0, 0);

        $payload = PanelPayload::briefing($this->assembled(null), $briefing, 'corr-1');
        $narration = $this->section($payload, 'narration');

        self::assertNull($payload['prior_visit']);
        self::assertSame('AI summary unavailable: timed out', $narration['status']);
        self::assertSame(['rx0001', 'al0001'], $narration['omitted_fact_ids']);
    }

    public function testAnswerPayloadCarriesTypeSentencesAndFactsHash(): void
    {
        $answer = new AnswerResult('cited', [new Sentence('Started at the last visit.', ['rx0001'])], 0, null, 300, 20);

        $payload = PanelPayload::answer($this->assembled(null), $answer, 'corr-2');
        $answerSection = $this->section($payload, 'answer');

        self::assertSame('corr-2', $payload['correlation_id']);
        self::assertSame('cited', $answerSection['type']);
        self::assertSame([['text' => 'Started at the last visit.', 'fact_ids' => ['rx0001']]], $answerSection['sentences']);
        self::assertSame($this->assembled(null)->facts()->hash(), $payload['facts_hash']);
        self::assertFalse($payload['chart_changed']);
    }

    public function testChartChangedPayloadReturnsFreshFactsAndNoAnswer(): void
    {
        $payload = PanelPayload::chartChanged($this->assembled(null), 'corr-3');

        self::assertTrue($payload['chart_changed']);
        self::assertCount(2, $this->section($payload, 'facts'));
        self::assertArrayNotHasKey('answer', $payload);
    }
}
