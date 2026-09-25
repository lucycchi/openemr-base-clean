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
use OpenEMR\Modules\ClinicalCopilot\BriefingNarrator;
use OpenEMR\Modules\ClinicalCopilot\BriefingResult;
use OpenEMR\Modules\ClinicalCopilot\EncounterRecord;
use OpenEMR\Modules\ClinicalCopilot\EvidenceChunk;
use OpenEMR\Modules\ClinicalCopilot\EvidenceSet;
use OpenEMR\Modules\ClinicalCopilot\FactAssembler;
use OpenEMR\Modules\ClinicalCopilot\FixedClock;
use OpenEMR\Modules\ClinicalCopilot\Guidelines\GuidelineCard;
use OpenEMR\Modules\ClinicalCopilot\Guidelines\GuidelineOutcome;
use OpenEMR\Modules\ClinicalCopilot\Guidelines\GuidelineSection;
use OpenEMR\Modules\ClinicalCopilot\Guidelines\GuidelineSource;
use OpenEMR\Modules\ClinicalCopilot\Guidelines\GuidelineStatus;
use OpenEMR\Modules\ClinicalCopilot\MedicationRecord;
use OpenEMR\Modules\ClinicalCopilot\PatientId;
use OpenEMR\Modules\ClinicalCopilot\Prewarmer;
use OpenEMR\Modules\ClinicalCopilot\PrewarmReceipts;
use OpenEMR\Modules\ClinicalCopilot\PrewarmRow;
use OpenEMR\Modules\ClinicalCopilot\PrewarmStatus;
use OpenEMR\Modules\ClinicalCopilot\ScheduledAppointment;
use OpenEMR\Modules\ClinicalCopilot\ScheduleSource;
use OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Support\FakeAuthorization;
use OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Support\FakeChartSource;
use OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Support\ModuleAutoload;
use PHPUnit\Framework\TestCase;

/**
 * Prewarmer with anonymous-class fakes that call back into the test to
 * record what they were asked. Covers selection (dedupe per
 * patient+provider, --pid filter, dry-run never narrates), that each
 * patient is narrated *as the scheduled provider* (ACL view), that the
 * clock is pinned to the target day so a check-in encounter does not
 * change the hash, cache-hit vs warmed classification, one patient's
 * failure not stopping the sweep, and that every row — including skipped
 * and errored — is written as a receipt under a single run id with the
 * fact lines attached.
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
    /** @var list<array{PatientId, string, bool}> pid, correlation id, fromCache as returned */
    private array $narrated = [];
    /** @var list<string> facts hashes the narrator saw, in order */
    private array $hashesSeen = [];
    private bool $narratorReturnsCached = false;
    /** False: the narration comes back unstored (a model failure or a summary the verifier stripped entirely). */
    private bool $narratorStores = true;
    private ?string $narratorStatus = null;
    private ?\Throwable $narratorThrows = null;
    /** @var list<list<string>> chunk ids of the evidence each narration was handed */
    private array $evidenceSeen = [];
    /** @var list<array{int, string, string}> pid, day (Y-m-d H:i), correlation id each guideline build was asked for */
    public array $guidelineBuilds = [];
    public ?GuidelineOutcome $guidelineOutcome = null;
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
    }

    private function appointment(int $eventId, int $pid, string $provider): ScheduledAppointment
    {
        return new ScheduledAppointment($eventId, new PatientId($pid), $provider, $this->day);
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
        $narrator = new class ($this) implements BriefingNarrator {
            public function __construct(private readonly PrewarmerTest $test)
            {
            }

            public function brief(AssembledFacts $assembled, PatientId $pid, string $correlationId, ?EvidenceSet $evidence = null): BriefingResult
            {
                return $this->test->narrate($assembled, $pid, $correlationId, $evidence ?? EvidenceSet::none());
            }
        };
        $guidelines = new class ($this) implements GuidelineSource {
            public function __construct(private readonly PrewarmerTest $test)
            {
            }

            public function build(AssembledFacts $assembled, PatientId $pid, DateTimeImmutable $day, string $correlationId): GuidelineOutcome
            {
                $this->test->guidelineBuilds[] = [$pid->value, $day->format('Y-m-d H:i'), $correlationId];
                return $this->test->guidelineOutcome ?? GuidelineOutcome::noneFired();
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

            public function latestFor(string $ymd, PatientId $pid, string $openerUsername): ?\OpenEMR\Modules\ClinicalCopilot\PrewarmReceipt
            {
                return null;
            }
        };
        return new Prewarmer($schedule, $this->chart, $authorizationFor, $narrator, $guidelines, $this->tz, $receipts);
    }

    /** Records what the narrator was handed and returns a canned result (throws / reports cache-hit when the test says so). @internal called by the anonymous narrator */
    public function narrate(AssembledFacts $assembled, PatientId $pid, string $correlationId, EvidenceSet $evidence): BriefingResult
    {
        if ($this->narratorThrows !== null) {
            throw $this->narratorThrows;
        }
        $this->hashesSeen[] = $assembled->facts()->hash();
        $this->narrated[] = [$pid, $correlationId, $this->narratorReturnsCached];
        $this->evidenceSeen[] = array_map(static fn(EvidenceChunk $c): string => $c->chunkId, $evidence->all());
        // A stored (or cached) narration comes back with the key it lives under; an unstored one without.
        $key = $this->narratorReturnsCached || $this->narratorStores ? 'key-' . $pid->value : null;
        return new BriefingResult([], 0, [], $this->narratorStatus, $this->narratorReturnsCached, !$this->narratorStores && $this->narratorStatus === null, 100, 20, null, $key);
    }

    /** A built section with one card the critic judged applicable and one it could not judge. */
    private function twoCards(): GuidelineOutcome
    {
        $chunk = static fn(string $id): EvidenceChunk => new EvidenceChunk($id, 'ada-2025-standards', 'Glycemic goals', 'An A1C goal of less than 7% is appropriate for many adults.', 0.8);
        $section = new GuidelineSection('ok', [
            new GuidelineCard('diabetes', 'Diabetes', [], [], [$chunk('aaaaaaaaaaaa')], true, 'adult'),
            new GuidelineCard('lipids', 'Lipids', [], [], [$chunk('bbbbbbbbbbbb')], null, null),
        ], 0, cacheable: false);
        return new GuidelineOutcome($section, GuidelineStatus::Partial, false, [], [['model' => 'gpt-4o-mini', 'kind' => 'rerank', 'input' => 1, 'output' => 0, 'cost_usd' => 0.002]]);
    }

    public function testEveryScheduledPatientIsNarratedAsTheScheduledProvider(): void
    {
        $this->appointments = [$this->appointment(1, 7, 'drsmith'), $this->appointment(2, 8, 'drjones')];

        $summary = $this->prewarmer()->run($this->day, null, false);

        self::assertSame(2, $summary->scheduled);
        self::assertSame(2, $summary->warmed);
        self::assertSame(0, $summary->alreadyCached);
        self::assertSame(['drsmith', 'drjones'], $this->authorizedAs);
        self::assertSame([7, 8], array_map(fn(array $n) => $n[0]->value, $this->narrated));
    }

    public function testHistoryIsPinnedToTheScheduledDayNotToNow(): void
    {
        // An encounter created on the scheduled day (check-in) must not change
        // what the pre-warm narrates, so its hash equals the day's assembly.
        $this->chart->encounters[] = new EncounterRecord(100, new DateTimeImmutable('2026-09-18 08:55:00', $this->tz), '', '');
        $this->appointments = [$this->appointment(1, 7, 'drsmith')];

        $this->prewarmer()->run($this->day, null, false);

        $expected = (new FactAssembler($this->chart, new FakeAuthorization(), new FixedClock($this->day->setTime(0, 0))))
            ->assemble(new PatientId(7), null)->facts()->hash();
        self::assertSame([$expected], $this->hashesSeen);
    }

    public function testAnAlreadyCachedBriefingCountsAsAlreadyCached(): void
    {
        $this->narratorReturnsCached = true;
        $this->appointments = [$this->appointment(1, 7, 'drsmith')];

        $summary = $this->prewarmer()->run($this->day, null, false);

        self::assertSame(0, $summary->warmed);
        self::assertSame(1, $summary->alreadyCached);
        self::assertSame(PrewarmStatus::AlreadyCached, $summary->rows[0]->status);
    }

    public function testDryRunSelectsButNeverNarrates(): void
    {
        $this->appointments = [$this->appointment(1, 7, 'drsmith')];

        $summary = $this->prewarmer()->run($this->day, null, true);

        self::assertSame(1, $summary->scheduled);
        self::assertSame(1, $summary->skipped);
        self::assertSame([], $this->narrated);
        self::assertSame(PrewarmStatus::Skipped, $summary->rows[0]->status);
    }

    public function testOnlyPidRestrictsTheRunToThatPatient(): void
    {
        $this->appointments = [$this->appointment(1, 7, 'drsmith'), $this->appointment(2, 8, 'drjones')];

        $summary = $this->prewarmer()->run($this->day, 8, false);

        self::assertSame(1, $summary->scheduled);
        self::assertSame([8], array_map(fn(array $n) => $n[0]->value, $this->narrated));
    }

    public function testTwoSlotsForTheSamePatientAndProviderWarmOnce(): void
    {
        $this->appointments = [$this->appointment(1, 7, 'drsmith'), $this->appointment(2, 7, 'drsmith'), $this->appointment(3, 7, 'drjones')];

        $summary = $this->prewarmer()->run($this->day, null, false);

        self::assertSame(2, $summary->scheduled);
        self::assertSame(['drsmith', 'drjones'], $this->authorizedAs);
    }

    public function testANarrationFailureIsCountedAndDoesNotStopTheRun(): void
    {
        $this->appointments = [$this->appointment(1, 7, 'drsmith'), $this->appointment(2, 8, 'drjones')];
        $this->narratorThrows = new \RuntimeException('upstream down');

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
        self::assertSame($this->hashesSeen[0], $row->factsHash);
        self::assertSame($this->narrated[0][1], $row->correlationId);
        self::assertTrue($row->modelCalled);
        self::assertGreaterThanOrEqual(0, $row->durationMs);
    }

    public function testEveryRowIsRecordedAsAReceiptUnderOneRunId(): void
    {
        $this->appointments = [$this->appointment(1, 7, 'drsmith'), $this->appointment(2, 8, 'drjones')];
        $this->narratorThrows = null;

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
        $this->narratorThrows = new \RuntimeException('upstream down');
        $this->prewarmer()->run($this->day, null, false);

        self::assertSame([PrewarmStatus::Skipped, PrewarmStatus::Error], array_map(fn(array $r) => $r[1]->status, $this->recorded));
        self::assertNotSame($this->recorded[0][0], $this->recorded[1][0]);
    }

    public function testAWarmedRowCarriesTheFactLinesForTheReceipt(): void
    {
        $this->appointments = [$this->appointment(1, 7, 'drsmith')];

        $summary = $this->prewarmer()->run($this->day, null, false);

        $expected = (new FactAssembler($this->chart, new FakeAuthorization(), new FixedClock($this->day->setTime(0, 0))))
            ->assemble(new PatientId(7), null)->facts()->lines();
        self::assertSame($expected, $summary->rows[0]->factLines);
        self::assertNotSame([], $expected);
    }

    public function testTheCardsAreBuiltFirstForThePinnedDayWithTheRowsCorrelationId(): void
    {
        $this->appointments = [$this->appointment(1, 7, 'drsmith')];

        $summary = $this->prewarmer()->run($this->day, null, false);

        self::assertSame([[7, '2026-09-18 00:00', $summary->rows[0]->correlationId]], $this->guidelineBuilds, 'age is taken on the scheduled day, and the sidecar logs join this row');
    }

    public function testOnlyTheVettedPassagesReachTheNarrationAndTheRowRecordsTheCards(): void
    {
        $this->guidelineOutcome = $this->twoCards();
        $this->appointments = [$this->appointment(1, 7, 'drsmith')];

        $summary = $this->prewarmer()->run($this->day, null, false);

        self::assertSame([['aaaaaaaaaaaa']], $this->evidenceSeen, 'the unassessed card is shown at chart open but never restated');
        $row = $summary->rows[0];
        self::assertSame(GuidelineStatus::Partial, $row->guidelineStatus);
        self::assertSame('key-7', $row->cacheKey, 'the key the narration was stored under, not a recomputation');
        self::assertSame(0.002, $row->sidecarCostUsd);
        self::assertSame(0.002, $summary->sidecarCostUsd);
    }

    public function testANarrationTheModelFailedIsAnErrorNotAWarm(): void
    {
        $this->narratorStores = false;
        $this->narratorStatus = 'AI summary unavailable: model error';
        $this->appointments = [$this->appointment(1, 7, 'drsmith')];

        $summary = $this->prewarmer()->run($this->day, null, false);

        self::assertSame(PrewarmStatus::Error, $summary->rows[0]->status);
        self::assertSame('AI summary unavailable: model error', $summary->rows[0]->error);
        self::assertNull($summary->rows[0]->cacheKey);
        self::assertSame(0, $summary->warmed);
    }

    public function testASummaryTheVerifierStrippedEntirelyIsAnErrorNotAWarm(): void
    {
        $this->narratorStores = false;
        $this->appointments = [$this->appointment(1, 7, 'drsmith')];

        $summary = $this->prewarmer()->run($this->day, null, false);

        self::assertSame(PrewarmStatus::Error, $summary->rows[0]->status);
        self::assertSame('every sentence was stripped; nothing stored', $summary->rows[0]->error);
    }

    public function testADryRunBuildsNoCards(): void
    {
        $this->appointments = [$this->appointment(1, 7, 'drsmith')];

        $this->prewarmer()->run($this->day, null, true);

        self::assertSame([], $this->guidelineBuilds);
    }
}
