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
use OpenEMR\Modules\ClinicalCopilot\AssembledFacts;
use OpenEMR\Modules\ClinicalCopilot\BriefingNarrator;
use OpenEMR\Modules\ClinicalCopilot\BriefingResult;
use OpenEMR\Modules\ClinicalCopilot\Command\PrewarmCommand;
use OpenEMR\Modules\ClinicalCopilot\Config;
use OpenEMR\Modules\ClinicalCopilot\FixedClock;
use OpenEMR\Modules\ClinicalCopilot\PatientId;
use OpenEMR\Modules\ClinicalCopilot\Prewarmer;
use OpenEMR\Modules\ClinicalCopilot\Ops\Tracer;
use OpenEMR\Modules\ClinicalCopilot\Ops\RequestTrace;
use OpenEMR\Modules\ClinicalCopilot\RunLock;
use OpenEMR\Modules\ClinicalCopilot\ScheduledAppointment;
use OpenEMR\Modules\ClinicalCopilot\ScheduleSource;
use OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Support\FakeAuthorization;
use OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Support\FakeChartSource;
use OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Support\ModuleAutoload;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * PrewarmCommand driven through Symfony's CommandTester with a real
 * Prewarmer wired to anonymous-class fakes for the schedule, narrator and
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
    private int $narrations = 0;
    private bool $narratorFails = false;
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
        $narrator = new class ($test) implements BriefingNarrator {
            public function __construct(private readonly PrewarmCommandTest $test)
            {
            }

            public function brief(AssembledFacts $assembled, PatientId $pid, string $correlationId): BriefingResult
            {
                return $this->test->narrate();
            }
        };
        $prewarmer = new Prewarmer($schedule, new FakeChartSource(), static fn(string $u) => new FakeAuthorization(), $narrator, $this->tz);
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
        self::assertSame(['date' => '2026-09-18', 'dry_run' => false, 'scheduled' => 1, 'warmed' => 1, 'already_cached' => 0, 'skipped' => 0, 'errored' => 0, 'model_calls' => 1, 'queue_depth_after' => 0], $trace->metadata);
        self::assertNull($trace->status);
        self::assertNull($trace->model, 'the sweep itself is not a model call; each row has its own receipt');
    }

    public function testAnErroredRowLeavesTheQueueDepthAndAStatusOnTheTrace(): void
    {
        $this->narratorFails = true;
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

    /** @internal called by the anonymous narrator */
    public function narrate(): BriefingResult
    {
        if ($this->narratorFails) {
            throw new \RuntimeException('upstream down');
        }
        $this->narrations++;
        return new BriefingResult([], 0, [], null, false, false, 10, 5);
    }

    public function testDisabledSiteDoesNothingAndExitsZero(): void
    {
        $tester = $this->command(false);

        $exit = $tester->execute(['--date' => 'today']);

        self::assertSame(0, $exit);
        self::assertStringContainsString('pre-warm disabled on this site', $tester->getDisplay());
        self::assertSame([], $this->daysAsked);
        self::assertSame(0, $this->narrations);
    }

    public function testForceBypassesTheKillSwitchForOneRun(): void
    {
        $tester = $this->command(false);

        $exit = $tester->execute(['--date' => 'today', '--force' => true]);

        self::assertSame(0, $exit);
        self::assertSame(1, $this->narrations);
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
        self::assertSame(0, $this->narrations);
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
        $this->narratorFails = true;
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
        $this->narratorFails = true;

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
