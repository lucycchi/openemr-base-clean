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
use OpenEMR\Modules\ClinicalCopilot\FactAssembler;
use OpenEMR\Modules\ClinicalCopilot\FixedClock;
use OpenEMR\Modules\ClinicalCopilot\MedicationRecord;
use OpenEMR\Modules\ClinicalCopilot\PatientId;
use OpenEMR\Modules\ClinicalCopilot\Prewarmer;
use OpenEMR\Modules\ClinicalCopilot\PrewarmStatus;
use OpenEMR\Modules\ClinicalCopilot\ScheduledAppointment;
use OpenEMR\Modules\ClinicalCopilot\ScheduleSource;
use OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Support\FakeAuthorization;
use OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Support\FakeChartSource;
use OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Support\ModuleAutoload;
use PHPUnit\Framework\TestCase;

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
    private ?\Throwable $narratorThrows = null;
    /** @var list<string> */
    private array $authorizedAs = [];
    private DateTimeZone $tz;
    private DateTimeImmutable $day;

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

            public function brief(AssembledFacts $assembled, PatientId $pid, string $correlationId): BriefingResult
            {
                return $this->test->narrate($assembled, $pid, $correlationId);
            }
        };
        $authorizationFor = function (string $username): FakeAuthorization {
            $this->authorizedAs[] = $username;
            return new FakeAuthorization();
        };
        return new Prewarmer($schedule, $this->chart, $authorizationFor, $narrator, $this->tz);
    }

    /** @internal called by the anonymous narrator */
    public function narrate(AssembledFacts $assembled, PatientId $pid, string $correlationId): BriefingResult
    {
        if ($this->narratorThrows !== null) {
            throw $this->narratorThrows;
        }
        $this->hashesSeen[] = $assembled->facts()->hash();
        $this->narrated[] = [$pid, $correlationId, $this->narratorReturnsCached];
        return new BriefingResult([], 0, [], null, $this->narratorReturnsCached, false, 100, 20);
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
}
