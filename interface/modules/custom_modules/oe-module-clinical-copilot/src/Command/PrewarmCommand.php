<?php

/**
 * copilot:prewarm — narrate and cache the briefing for every patient on a
 * day's schedule before clinic opens. Run by cron as the web user; inert
 * unless COPILOT_PREWARM_ENABLED is set (or --force for a one-off).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Command;

use DateTimeImmutable;
use DateTimeZone;
use OpenEMR\Modules\ClinicalCopilot\Config;
use OpenEMR\Modules\ClinicalCopilot\Prewarmer;
use OpenEMR\Modules\ClinicalCopilot\PrewarmRow;
use OpenEMR\Modules\ClinicalCopilot\PrewarmStatus;
use OpenEMR\Modules\ClinicalCopilot\Ops\NullTracer;
use OpenEMR\Modules\ClinicalCopilot\Ops\RequestTrace;
use OpenEMR\Modules\ClinicalCopilot\Ops\Tracer;
use OpenEMR\Modules\ClinicalCopilot\RunLock;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `bin/console copilot:prewarm` — the CLI wrapper around Prewarmer, meant to
 * run from cron before clinic hours. Responsibilities here are only the
 * CLI-shaped ones: parse options, honour the kill switch, take the run
 * lock, print a summary, and pick an exit code. Everything else is Prewarmer.
 */
final class PrewarmCommand extends Command
{
    public const NAME = 'copilot:prewarm';

    public function __construct(
        private readonly Config $config,
        private readonly Prewarmer $prewarmer,
        private readonly ClockInterface $clock,
        private readonly DateTimeZone $tz,
        private readonly RunLock $lock,
        /** Week 2: the sweep is the module's queue; one trace per run makes its depth and outcome chartable. */
        private readonly Tracer $tracer = new NullTracer(),
    ) {
        parent::__construct(self::NAME);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Pre-warm Co-Pilot briefings for the patients scheduled on a day')
            ->addOption('site', null, InputOption::VALUE_REQUIRED, 'OpenEMR site (consumed by bin/console)', 'default')
            ->addOption('date', null, InputOption::VALUE_REQUIRED, 'today, tomorrow, or YYYY-MM-DD', 'today')
            ->addOption('pid', null, InputOption::VALUE_REQUIRED, 'Only this patient id')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List the schedule without narrating')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Run even when COPILOT_PREWARM_ENABLED is off');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Kill switch: off by default so a fresh deploy never starts spending
        // on model calls until someone opts in. Exit 0 so cron stays quiet.
        if (!$this->config->prewarmEnabled && !$input->getOption('force')) {
            $output->writeln('pre-warm disabled on this site (set COPILOT_PREWARM_ENABLED=1, or pass --force for one run)');
            return Command::SUCCESS;
        }

        $day = $this->day($input->getOption('date'));
        if ($day === null) {
            $output->writeln('<error>--date must be today, tomorrow, or YYYY-MM-DD</error>');
            return Command::INVALID;
        }
        $pid = $input->getOption('pid');
        $onlyPid = is_string($pid) && ctype_digit($pid) ? (int) $pid : null;
        $dryRun = (bool) $input->getOption('dry-run');

        // An overlapping cron (a catch-up pass while the 06:00 sweep is still
        // going) is expected, not an error: say so and leave the first run alone.
        if (!$this->lock->acquire()) {
            $output->writeln('another pre-warm run holds the lock; exiting');
            return Command::SUCCESS;
        }
        // finally: the lock is released even if the sweep throws.
        $started = hrtime(true);
        try {
            $summary = $this->prewarmer->run($day, $onlyPid, $dryRun);
        } finally {
            $this->lock->release();
        }
        $totalMs = (int) round((hrtime(true) - $started) / 1e6);

        // Per-row lines only with -v; errors always. Then one summary line in
        // key=value form that the alert rules and dashboard grep for.
        foreach ($summary->rows as $row) {
            $output->writeln($this->rowLine($row), OutputInterface::VERBOSITY_VERBOSE);
            if ($row->status === PrewarmStatus::Error) {
                $output->writeln(sprintf('<error>pid=%d provider=%s: %s</error>', $row->appointment->pid->value, $row->appointment->providerUsername, $row->error ?? 'unknown error'));
            }
        }
        $modelCalls = count(array_filter($summary->rows, static fn(PrewarmRow $r) => $r->modelCalled));
        // One trace per sweep: the queue's size (scheduled), what drained it
        // (warmed / already cached / skipped), what is left over (errored),
        // and its throughput. The tracer scores prewarm_ok from these fields.
        $this->tracer->record(new RequestTrace(
            $summary->runId,
            'copilot.prewarm',
            'cron',
            (int) round(microtime(true) * 1000) - $totalMs,
            $totalMs,
            [
                'date' => $day->format('Y-m-d'),
                'dry_run' => $dryRun,
                'scheduled' => $summary->scheduled,
                'warmed' => $summary->warmed,
                'already_cached' => $summary->alreadyCached,
                'skipped' => $summary->skipped,
                'errored' => $summary->errored,
                'model_calls' => $modelCalls,
                'queue_depth_after' => $summary->errored,
            ],
            null,
            0,
            0,
            0,
            $summary->errored > 0 ? sprintf('%d of %d scheduled patients errored', $summary->errored, $summary->scheduled) : null,
        ));
        $output->writeln(sprintf(
            'prewarm date=%s scheduled=%d warmed=%d already_cached=%d skipped=%d errored=%d model_calls=%d total_ms=%d',
            $day->format('Y-m-d'),
            $summary->scheduled,
            $summary->warmed,
            $summary->alreadyCached,
            $summary->skipped,
            $summary->errored,
            $modelCalls,
            $totalMs,
        ));
        // Non-zero exit if any patient errored, so cron/CI notices.
        return $summary->errored > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /** Resolves --date to midnight in the site's zone; null means invalid input. */
    private function day(mixed $option): ?DateTimeImmutable
    {
        if (!is_string($option)) {
            return null;
        }
        $today = $this->clock->now()->setTimezone($this->tz)->setTime(0, 0);
        return match (true) {
            $option === 'today' => $today,
            $option === 'tomorrow' => $today->modify('+1 day'),
            (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $option) => $this->calendarDay($option),
            default => null,
        };
    }

    /** Strict parse: "2026-02-30" round-trips to "2026-03-02", so it is rejected. */
    private function calendarDay(string $ymd): ?DateTimeImmutable
    {
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $ymd, $this->tz);
        return $day !== false && $day->format('Y-m-d') === $ymd ? $day : null;
    }

    private function rowLine(PrewarmRow $row): string
    {
        return sprintf(
            '%s pid=%d provider=%s event=%d hash=%s ms=%d corr=%s',
            $row->status->value,
            $row->appointment->pid->value,
            $row->appointment->providerUsername,
            $row->appointment->eventId,
            $row->factsHash ?? '-',
            $row->durationMs,
            $row->correlationId,
        );
    }
}
