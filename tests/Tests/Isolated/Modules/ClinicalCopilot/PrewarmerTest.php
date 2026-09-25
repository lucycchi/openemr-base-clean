<?php

/**
 * Prewarmer narrates (and so caches) the briefing for every patient on the
 * day's schedule, as the scheduled provider, with history pinned to that day.
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
use DateTimeZone;
use OpenEMR\Modules\ClinicalCopilot\AssembledFacts;
use OpenEMR\Modules\ClinicalCopilot\BriefingService;
use OpenEMR\Modules\ClinicalCopilot\Config;
use OpenEMR\Modules\ClinicalCopilot\EncounterRecord;
use OpenEMR\Modules\ClinicalCopilot\EvidenceChunk;
use OpenEMR\Modules\ClinicalCopilot\EvidenceSet;
use OpenEMR\Modules\ClinicalCopilot\FactAssembler;
use OpenEMR\Modules\ClinicalCopilot\FixedClock;
use OpenEMR\Modules\ClinicalCopilot\Guidelines\GuidelineCard;
use OpenEMR\Modules\ClinicalCopilot\Guidelines\GuidelineOutcome;
use OpenEMR\Modules\ClinicalCopilot\Guidelines\GuidelineSection;
use OpenEMR\Modules\ClinicalCopilot\Guidelines\GuidelineStatus;
use OpenEMR\Modules\ClinicalCopilot\Llm\LlmRateLimited;
use OpenEMR\Modules\ClinicalCopilot\MedicationRecord;
use OpenEMR\Modules\ClinicalCopilot\Ops\StepRecorder;
use OpenEMR\Modules\ClinicalCopilot\PatientId;
use OpenEMR\Modules\ClinicalCopilot\Prewarmer;
use OpenEMR\Modules\ClinicalCopilot\PrewarmReceipt;
use OpenEMR\Modules\ClinicalCopilot\PrewarmReceipts;
use OpenEMR\Modules\ClinicalCopilot\PrewarmRow;
use OpenEMR\Modules\ClinicalCopilot\PrewarmStatus;
use OpenEMR\Modules\ClinicalCopilot\ScheduledAppointment;
use OpenEMR\Modules\ClinicalCopilot\ScheduleSource;
use OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Support\FakeAuthorization;
use OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Support\FakeChartSource;
use OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Support\FakeGuidelineSource;
use OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Support\FakeNarrationPipelines;
use OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Support\ModuleAutoload;
use PHPUnit\Framework\TestCase;

/**
 * Prewarmer over a real BriefingService: scripted guideline cards and a real
 * NarrationPipeline on the fake model and an in-memory cache. Covers
 * selection (dedupe per patient+provider, --pid filter, dry-run never
 * narrates), that each patient is assembled *as the scheduled provider*
 * (ACL view), that the clock is pinned to the target day so a check-in
 * encounter does not change the hash, cache-hit vs warmed vs not-stored
 * classification, one patient's failure not stopping the sweep, and that
 * every row, skipped and errored included, is written as a receipt under a
 * single run id with the fact lines attached.
 */
final class PrewarmerTest extends TestCase
{
    /**
     * @codeCoverageIgnore Fixture wiring; runs before coverage attribution.
     */
    public static function setUpBeforeClass(): void
    {
        ModuleAutoload::register();
    }

    /** @var list<ScheduledAppointment> */
    private array $appointments = [];
    private FakeChartSource $chart;
    private FakeGuidelineSource $guidelines;
    private FakeNarrationPipelines $pipelines;
    private string $apiKey = 'sk-test';
    /** @var list<string> */
    private array $authorizedAs = [];
    private DateTimeZone $tz;
    private DateTimeImmutable $day;
    /** @var list<array{string, PrewarmRow}> run id and row, as recorded */
    public array $recorded = [];

    protected function setUp(): void
    {
        $this->chart = new FakeChartSource();
        $this->chart->encounters = [new EncounterRecord(99, new DateTimeImmutable('2026-09-01 10:00:00'), '', 'Follow-up')];
        $this->chart->medications = [new MedicationRecord(17, 'Metformin 500 MG Oral Tablet', new DateTimeImmutable('2025-01-10'), true)];
        $this->tz = new DateTimeZone('America/Los_Angeles');
        $this->day = new DateTimeImmutable('2026-09-18', $this->tz);
        $this->guidelines = new FakeGuidelineSource();
        $this->pipelines = new FakeNarrationPipelines();
        // The model cites the chart's own first fact, so the narration verifies and is stored.
        $this->pipelines->llm->reply = ['sentences' => [['text' => 'Metformin is on the medication list.', 'fact_ids' => [$this->assembledFor(7)->facts()->all()[0]->id]]]];
    }

    private function appointment(int $eventId, int $pid, string $provider): ScheduledAppointment
    {
        return new ScheduledAppointment($eventId, new PatientId($pid), $provider, $this->day);
    }

    /** What the sweep assembles for a patient: the fake chart, the scheduled day's start. */
    private function assembledFor(int $pid): AssembledFacts
    {
        return (new FactAssembler($this->chart, new FakeAuthorization(), new FixedClock($this->day->setTime(0, 0))))->assemble(new PatientId($pid), null);
    }

    private function config(): Config
    {
        return new Config($this->apiKey, 'fake-model', 'https://cloud.langfuse.com', '', '');
    }

    private function prewarmer(): Prewarmer
    {
        $schedule = new class ($this->appointments) implements ScheduleSource {
            /** @param list<ScheduledAppointment> $appointments */
            public function __construct(private readonly array $appointments)
            {
            }

            public function appointmentsOn(DateTimeImmutable $day): array
            {
                return $this->appointments;
            }
        };
        $authorizationFor = function (string $username): FakeAuthorization {
            $this->authorizedAs[] = $username;
            return new FakeAuthorization();
        };
        $receipts = new class ($this) implements PrewarmReceipts {
            public function __construct(private readonly PrewarmerTest $test)
            {
            }

            public function record(string $runId, PrewarmRow $row): void
            {
                $this->test->recorded[] = [$runId, $row];
            }

            public function latestFor(string $ymd, PatientId $pid, string $openerUsername): ?PrewarmReceipt
            {
                return null;
            }
        };
        return new Prewarmer($schedule, $this->chart, $authorizationFor, new BriefingService($this->guidelines, $this->pipelines, $this->config()), $this->tz, $receipts);
    }

    private static function chunk(string $id): EvidenceChunk
    {
        return new EvidenceChunk($id, 'ada-2025-standards', 'Glycemic goals', 'An A1C goal of less than 7% is appropriate for many adults.', 0.8);
    }

    /** A section with one card the critic judged applicable and one it could not judge. */
    private function twoCards(): GuidelineOutcome
    {
        $section = new GuidelineSection('ok', [
            new GuidelineCard('diabetes', 'Diabetes', [], [], [self::chunk('aaaaaaaaaaaa')], true, 'adult'),
            new GuidelineCard('lipids', 'Lipids', [], [], [self::chunk('bbbbbbbbbbbb')], null, null),
        ], 0, cacheable: false);
        return new GuidelineOutcome($section, GuidelineStatus::Partial, false, [], [['model' => 'gpt-4o-mini', 'kind' => 'rerank', 'input' => 1, 'output' => 0, 'cost_usd' => 0.002]]);
    }

    /** @return list<int> the patients whose briefing was built, in order */
    private function briefed(): array
    {
        return array_map(static fn(array $b): int => $b['pid'], $this->guidelines->builds);
    }

    public function testEveryScheduledPatientIsBriefedAsTheScheduledProvider(): void
    {
        $this->appointments = [$this->appointment(1, 7, 'drsmith'), $this->appointment(2, 8, 'drjones')];

        $summary = $this->prewarmer()->run($this->day, null, false);

        self::assertSame(2, $summary->scheduled);
        // The fake chart gives both patients the same facts, so the second finds the
        // first one's narration under the same key, as the briefing cache table would.
        self::assertSame(1, $summary->warmed);
        self::assertSame(1, $summary->alreadyCached);
        self::assertSame(0, $summary->errored);
        self::assertSame(['drsmith', 'drjones'], $this->authorizedAs);
        self::assertSame([7, 8], $this->briefed());
    }

    public function testHistoryIsPinnedToTheScheduledDayNotToNow(): void
    {
        // An encounter created on the scheduled day (check-in) must not change
        // what the pre-warm narrates, so its hash equals the day's assembly.
        $expected = $this->assembledFor(7)->facts()->hash();
        $this->chart->encounters[] = new EncounterRecord(100, new DateTimeImmutable('2026-09-18 08:55:00', $this->tz), '', '');
        $this->appointments = [$this->appointment(1, 7, 'drsmith')];

        $this->prewarmer()->run($this->day, null, false);

        self::assertSame([$expected], array_map(static fn(array $b): string => $b['factsHash'], $this->guidelines->builds));
    }

    public function testAnAlreadyCachedBriefingCountsAsAlreadyCached(): void
    {
        $this->appointments = [$this->appointment(1, 7, 'drsmith')];
        $this->prewarmer()->run($this->day, null, false);

        $summary = $this->prewarmer()->run($this->day, null, false);

        self::assertSame(0, $summary->warmed);
        self::assertSame(1, $summary->alreadyCached);
        self::assertSame(PrewarmStatus::AlreadyCached, $summary->rows[0]->status);
        self::assertFalse($summary->rows[0]->modelCalled);
        self::assertSame(1, $this->pipelines->llm->calls, 'the second run costs no model call');
    }

    public function testDryRunSelectsButNeverBriefs(): void
    {
        $this->appointments = [$this->appointment(1, 7, 'drsmith')];

        $summary = $this->prewarmer()->run($this->day, null, true);

        self::assertSame(1, $summary->scheduled);
        self::assertSame(1, $summary->skipped);
        self::assertSame([], $this->guidelines->builds, 'no cards built');
        self::assertSame(0, $this->pipelines->llm->calls);
        self::assertSame(PrewarmStatus::Skipped, $summary->rows[0]->status);
    }

    public function testOnlyPidRestrictsTheRunToThatPatient(): void
    {
        $this->appointments = [$this->appointment(1, 7, 'drsmith'), $this->appointment(2, 8, 'drjones')];

        $summary = $this->prewarmer()->run($this->day, 8, false);

        self::assertSame(1, $summary->scheduled);
        self::assertSame([8], $this->briefed());
    }

    public function testTwoSlotsForTheSamePatientAndProviderWarmOnce(): void
    {
        $this->appointments = [$this->appointment(1, 7, 'drsmith'), $this->appointment(2, 7, 'drsmith'), $this->appointment(3, 7, 'drjones')];

        $summary = $this->prewarmer()->run($this->day, null, false);

        self::assertSame(2, $summary->scheduled);
        self::assertSame(['drsmith', 'drjones'], $this->authorizedAs);
    }

    public function testAPerPatientFailureIsCountedAndDoesNotStopTheRun(): void
    {
        $this->appointments = [$this->appointment(1, 7, 'drsmith'), $this->appointment(2, 8, 'drjones')];
        $this->guidelines->throw = new \RuntimeException('upstream down');

        $summary = $this->prewarmer()->run($this->day, null, false);

        self::assertSame(2, $summary->errored);
        self::assertSame(0, $summary->warmed);
        self::assertSame(PrewarmStatus::Error, $summary->rows[1]->status);
        self::assertSame('upstream down', $summary->rows[1]->error);
    }

    public function testEachRowCarriesTheReceiptFields(): void
    {
        $this->appointments = [$this->appointment(41, 7, 'drsmith')];

        $summary = $this->prewarmer()->run($this->day, null, false);

        $row = $summary->rows[0];
        self::assertSame(41, $row->appointment->eventId);
        self::assertSame(7, $row->appointment->pid->value);
        self::assertSame('drsmith', $row->appointment->providerUsername);
        self::assertSame($this->guidelines->builds[0]['factsHash'], $row->factsHash);
        self::assertSame($this->guidelines->builds[0]['correlationId'], $row->correlationId);
        self::assertTrue($row->modelCalled);
        self::assertGreaterThanOrEqual(0, $row->durationMs);
    }

    public function testEveryRowIsRecordedAsAReceiptUnderOneRunId(): void
    {
        $this->appointments = [$this->appointment(1, 7, 'drsmith'), $this->appointment(2, 8, 'drjones')];

        $summary = $this->prewarmer()->run($this->day, null, false);

        self::assertCount(2, $this->recorded);
        self::assertSame($this->recorded[0][0], $this->recorded[1][0]);
        self::assertSame($summary->runId, $this->recorded[0][0]);
        self::assertSame($summary->rows, array_map(fn(array $r) => $r[1], $this->recorded));
    }

    public function testSkippedAndErroredRowsAreRecordedToo(): void
    {
        $this->appointments = [$this->appointment(1, 7, 'drsmith')];

        $this->prewarmer()->run($this->day, null, true);
        $this->guidelines->throw = new \RuntimeException('upstream down');
        $this->prewarmer()->run($this->day, null, false);

        self::assertSame([PrewarmStatus::Skipped, PrewarmStatus::Error], array_map(fn(array $r) => $r[1]->status, $this->recorded));
        self::assertNotSame($this->recorded[0][0], $this->recorded[1][0]);
    }

    public function testAWarmedRowCarriesTheFactLinesForTheReceipt(): void
    {
        $this->appointments = [$this->appointment(1, 7, 'drsmith')];

        $summary = $this->prewarmer()->run($this->day, null, false);

        $expected = $this->assembledFor(7)->facts()->lines();
        self::assertSame($expected, $summary->rows[0]->factLines);
        self::assertNotSame([], $expected);
    }

    public function testTheCardsAreBuiltForThePinnedDayWithTheRowsCorrelationId(): void
    {
        $this->appointments = [$this->appointment(1, 7, 'drsmith')];

        $summary = $this->prewarmer()->run($this->day, null, false);

        self::assertSame('2026-09-18 00:00', $this->guidelines->builds[0]['day'], 'age is taken on the scheduled day');
        self::assertSame($summary->rows[0]->correlationId, $this->guidelines->builds[0]['correlationId'], 'the sidecar logs join this row');
    }

    public function testOnlyTheVettedPassagesReachTheNarrationAndTheRowRecordsTheCards(): void
    {
        $this->guidelines->outcome = $this->twoCards();
        $this->appointments = [$this->appointment(1, 7, 'drsmith')];

        $summary = $this->prewarmer()->run($this->day, null, false);

        self::assertStringContainsString('aaaaaaaaaaaa', $this->pipelines->llm->lastUser);
        self::assertStringNotContainsString('bbbbbbbbbbbb', $this->pipelines->llm->lastUser, 'the unassessed card is shown at chart open but never restated');
        $row = $summary->rows[0];
        self::assertSame(GuidelineStatus::Partial, $row->guidelineStatus);
        $expectedKey = $this->pipelines->create($this->config(), $this->assembledFor(7), new PatientId(7), null, new StepRecorder())
            ->cacheKey($this->assembledFor(7), new EvidenceSet([self::chunk('aaaaaaaaaaaa')]));
        self::assertSame($expectedKey, $row->cacheKey, 'the key the narration was stored under, not a recomputation');
        self::assertSame(0.002, $row->sidecarCostUsd);
        self::assertSame(0.002, $summary->sidecarCostUsd);
    }

    public function testANarrationTheModelFailedIsAnErrorNotAWarm(): void
    {
        $this->pipelines->llm->throw = new LlmRateLimited('Rate limited', 429);
        $this->appointments = [$this->appointment(1, 7, 'drsmith')];

        $summary = $this->prewarmer()->run($this->day, null, false);

        self::assertSame(PrewarmStatus::Error, $summary->rows[0]->status);
        self::assertSame((new LlmRateLimited('Rate limited', 429))->statusLabel(), $summary->rows[0]->error);
        self::assertNull($summary->rows[0]->cacheKey);
        self::assertSame(0, $summary->warmed);
    }

    public function testASummaryTheVerifierStrippedEntirelyIsAnErrorNotAWarm(): void
    {
        $this->pipelines->llm->reply = ['sentences' => [['text' => 'An invented claim.', 'fact_ids' => ['zzzzzzzz']]]];
        $this->appointments = [$this->appointment(1, 7, 'drsmith')];

        $summary = $this->prewarmer()->run($this->day, null, false);

        self::assertSame(PrewarmStatus::Error, $summary->rows[0]->status);
        self::assertSame('every sentence was stripped; nothing stored', $summary->rows[0]->error);
    }

    public function testASiteWithoutAChatModelRecordsThatAndBriefsNothing(): void
    {
        $this->apiKey = '';
        $this->appointments = [$this->appointment(1, 7, 'drsmith')];

        $summary = $this->prewarmer()->run($this->day, null, false);

        self::assertSame(PrewarmStatus::Error, $summary->rows[0]->status);
        self::assertSame('AI is not configured on this server (OPENAI_API_KEY unset)', $summary->rows[0]->error);
        self::assertSame(0, $this->pipelines->llm->calls);
    }
}
