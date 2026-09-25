<?php

/**
 * copilot:prewarm — the console entry point around Prewarmer.
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
use OpenEMR\Modules\ClinicalCopilot\BriefingService;
use OpenEMR\Modules\ClinicalCopilot\Command\PrewarmCommand;
use OpenEMR\Modules\ClinicalCopilot\Config;
use OpenEMR\Modules\ClinicalCopilot\FactAssembler;
use OpenEMR\Modules\ClinicalCopilot\FixedClock;
use OpenEMR\Modules\ClinicalCopilot\Guidelines\GuidelineOutcome;
use OpenEMR\Modules\ClinicalCopilot\Guidelines\GuidelineSection;
use OpenEMR\Modules\ClinicalCopilot\Guidelines\GuidelineStatus;
use OpenEMR\Modules\ClinicalCopilot\MedicationRecord;
use OpenEMR\Modules\ClinicalCopilot\Ops\RequestTrace;
use OpenEMR\Modules\ClinicalCopilot\Ops\Score;
use OpenEMR\Modules\ClinicalCopilot\Ops\Tracer;
use OpenEMR\Modules\ClinicalCopilot\PatientId;
use OpenEMR\Modules\ClinicalCopilot\Prewarmer;
use OpenEMR\Modules\ClinicalCopilot\RunLock;
use OpenEMR\Modules\ClinicalCopilot\ScheduledAppointment;
use OpenEMR\Modules\ClinicalCopilot\ScheduleSource;
use OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Support\FakeAuthorization;
use OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Support\FakeChartSource;
use OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Support\FakeGuidelineSource;
use OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Support\FakeNarrationPipelines;
use OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Support\ModuleAutoload;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * PrewarmCommand driven through Symfony's CommandTester with a real
 * Prewarmer wired to fakes for the schedule, the briefing (cards and model) and
 * lock (each calls back into the test so it can count and script
 * behaviour). Covers: the kill switch and --force; --date parsing
 * (today/tomorrow in the site zone, explicit date, garbage rejected before
 * the schedule is read); --dry-run narrates nothing; the summary line;
 * exit code 1 when any row errored; and the lock — skipped when held
 * elsewhere, always released, never taken when disabled.
 */
final class PrewarmCommandTest extends TestCase
{
    /**
     * @codeCoverageIgnore Fixture wiring; runs before coverage attribution.
     */
    public static function setUpBeforeClass(): void
    {
        ModuleAutoload::register();
    }

    /** @var list<string> Y-m-d of each day the schedule was asked for */
    private array $daysAsked = [];
    private bool $briefingFails = false;
    private FakeGuidelineSource $guidelines;
    private FakeNarrationPipelines $pipelines;
    public bool $lockHeldElsewhere = false;
    public int $lockReleases = 0;
    /** @var list<RequestTrace> */
    public array $traces = [];
    private DateTimeZone $tz;
    private Tracer $tracer;

    protected function setUp(): void
    {
        $this->tz = new DateTimeZone('America/Los_Angeles');
        $this->tracer = $this->tracerDouble();
        $this->guidelines = new FakeGuidelineSource();
        $this->pipelines = new FakeNarrationPipelines();
    }

    /**
     * Assembles the command under test. The clock is frozen at 22:00 on
     * 2026-09-17 Pacific so "today" and "tomorrow" have known answers.
     */
    private function command(bool $enabled): CommandTester
    {
        $test = $this;
        $schedule = new class ($test) implements ScheduleSource {
            public function __construct(private readonly PrewarmCommandTest $test)
            {
            }

            public function appointmentsOn(DateTimeImmutable $day): array
            {
                return $this->test->schedule($day);
            }
        };
        // Every patient's cards cost one rerank, so the sweep's trace has a sidecar cost to report.
        $this->guidelines->outcome = new GuidelineOutcome(GuidelineSection::none('ok'), GuidelineStatus::Built, false, [], [['model' => 'rerank-v3.5', 'kind' => 'rerank', 'input' => 1, 'output' => 0, 'cost_usd' => 0.002]]);
        $this->guidelines->throw = $this->briefingFails ? new \RuntimeException('upstream down') : null;
        $chart = new FakeChartSource();
        $chart->medications = [new MedicationRecord(17, 'Metformin 500 MG Oral Tablet', new DateTimeImmutable('2025-01-10'), true)];
        // The model cites the chart's own fact, so the narration verifies and is stored: the row counts as warmed.
        $factId = (new FactAssembler($chart, new FakeAuthorization(), new FixedClock(new DateTimeImmutable('2026-09-18', $this->tz))))->assemble(new PatientId(7), null)->facts()->all()[0]->id;
        $this->pipelines->llm->reply = ['sentences' => [['text' => 'Metformin is on the medication list.', 'fact_ids' => [$factId]]]];
        $briefings = new BriefingService($this->guidelines, $this->pipelines, new Config('sk-test', 'gpt-4o-mini', 'https://cloud.langfuse.com', '', ''));
        $prewarmer = new Prewarmer($schedule, $chart, static fn(string $u) => new FakeAuthorization(), $briefings, $this->tz);
        $config = new Config('sk-test', 'gpt-4o-mini', 'https://cloud.langfuse.com', '', '', prewarmEnabled: $enabled);
        $now = new FixedClock(new DateTimeImmutable('2026-09-17 22:00:00', $this->tz));
        $lock = new class ($test) implements RunLock {
            public function __construct(private readonly PrewarmCommandTest $test)
            {
            }

            public function acquire(): bool
            {
                return !$this->test->lockHeldElsewhere;
            }

            public function release(): void
            {
                $this->test->lockReleases++;
            }
        };
        return new CommandTester(new PrewarmCommand($config, $prewarmer, $now, $this->tz, $lock, $this->tracer));
    }

    /** Captures what the command traces, so a test can read the sweep's numbers. */
    private function tracerDouble(): Tracer
    {
        return new class ($this) implements Tracer {
            public function __construct(private readonly PrewarmCommandTest $test)
            {
            }

            public function record(RequestTrace $trace): void
            {
                $this->test->traces[] = $trace;
            }

            public function score(Score $score): void
            {
            }
        };
    }

    public function testASweepRecordsOneTraceWithTheQueueNumbers(): void
    {
        $tester = $this->command(true);
        $tester->execute(['--date' => 'tomorrow']);

        self::assertCount(1, $this->traces);
        $trace = $this->traces[0];
        self::assertSame('copilot.prewarm', $trace->name);
        self::assertSame('cron', $trace->user);
        self::assertSame(['date' => '2026-09-18', 'dry_run' => false, 'scheduled' => 1, 'warmed' => 1, 'already_cached' => 0, 'skipped' => 0, 'errored' => 0, 'model_calls' => 1, 'sidecar_cost_usd' => 0.002, 'queue_depth_after' => 0], $trace->metadata);
        self::assertNull($trace->status);
        self::assertNull($trace->model, 'the sweep itself is not a model call; each row has its own receipt');
    }

    public function testAnErroredRowLeavesTheQueueDepthAndAStatusOnTheTrace(): void
    {
        $this->briefingFails = true;
        $tester = $this->command(true);
        $tester->execute(['--date' => 'tomorrow']);

        $trace = $this->traces[0];
        self::assertSame(1, $trace->metadata['errored']);
        self::assertSame(1, $trace->metadata['queue_depth_after']);
        self::assertSame('1 of 1 scheduled patients errored', $trace->status);
    }

    /**
     * @internal called by the anonymous schedule
     * @return list<ScheduledAppointment>
     */
    public function schedule(DateTimeImmutable $day): array
    {
        $this->daysAsked[] = $day->format('Y-m-d');
        return [new ScheduledAppointment(1, new PatientId(7), 'drsmith', $day)];
    }

    /** How many patients' briefings the sweep started (it builds the cards first). */
    private function briefed(): int
    {
        return count($this->guidelines->builds);
    }

    public function testDisabledSiteDoesNothingAndExitsZero(): void
    {
        $tester = $this->command(false);

        $exit = $tester->execute(['--date' => 'today']);

        self::assertSame(0, $exit);
        self::assertStringContainsString('pre-warm disabled on this site', $tester->getDisplay());
        self::assertSame([], $this->daysAsked);
        self::assertSame(0, $this->briefed());
    }

    public function testForceBypassesTheKillSwitchForOneRun(): void
    {
        $tester = $this->command(false);

        $exit = $tester->execute(['--date' => 'today', '--force' => true]);

        self::assertSame(0, $exit);
        self::assertSame(1, $this->briefed());
    }

    public function testTodayAndTomorrowResolveInTheSiteZone(): void
    {
        $this->command(true)->execute(['--date' => 'today']);
        $this->command(true)->execute(['--date' => 'tomorrow']);
        $this->command(true)->execute(['--date' => '2026-10-02']);

        self::assertSame(['2026-09-17', '2026-09-18', '2026-10-02'], $this->daysAsked);
    }

    public function testAnUnparseableDateIsRejectedWithoutTouchingTheSchedule(): void
    {
        $tester = $this->command(true);

        $exit = $tester->execute(['--date' => 'next tuesday-ish']);

        self::assertSame(2, $exit);
        self::assertSame([], $this->daysAsked);
    }

    public function testDryRunReportsWithoutNarrating(): void
    {
        $tester = $this->command(true);

        $exit = $tester->execute(['--date' => 'today', '--dry-run' => true]);

        self::assertSame(0, $exit);
        self::assertSame(0, $this->briefed());
        self::assertStringContainsString('scheduled=1', $tester->getDisplay());
        self::assertStringContainsString('skipped=1', $tester->getDisplay());
    }

    public function testSummaryLineAndZeroExitOnSuccess(): void
    {
        $tester = $this->command(true);

        $exit = $tester->execute(['--date' => 'today']);

        self::assertSame(0, $exit);
        self::assertMatchesRegularExpression('/scheduled=1 warmed=1 already_cached=0 skipped=0 errored=0 model_calls=1 total_ms=\d+/', $tester->getDisplay());
    }

    public function testAnyErroredRowMakesTheExitCodeNonZero(): void
    {
        $this->briefingFails = true;
        $tester = $this->command(true);

        $exit = $tester->execute(['--date' => 'today']);

        self::assertSame(1, $exit);
        self::assertStringContainsString('errored=1', $tester->getDisplay());
        self::assertStringContainsString('upstream down', $tester->getDisplay());
    }

    public function testAnotherRunHoldingTheLockMeansThisOneExitsCleanlyWithoutWork(): void
    {
        $this->lockHeldElsewhere = true;
        $tester = $this->command(true);

        $exit = $tester->execute(['--date' => 'today']);

        self::assertSame(0, $exit);
        self::assertStringContainsString('another pre-warm run holds the lock', $tester->getDisplay());
        self::assertSame([], $this->daysAsked);
        self::assertSame(0, $this->lockReleases);
    }

    public function testTheLockIsReleasedAfterARunEvenWhenRowsErrored(): void
    {
        $this->briefingFails = true;

        $this->command(true)->execute(['--date' => 'today']);

        self::assertSame(1, $this->lockReleases);
    }

    public function testADisabledSiteNeverTakesTheLock(): void
    {
        $this->lockHeldElsewhere = true;
        $tester = $this->command(false);

        $exit = $tester->execute(['--date' => 'today']);

        self::assertSame(0, $exit);
        self::assertStringContainsString('pre-warm disabled', $tester->getDisplay());
    }
}
