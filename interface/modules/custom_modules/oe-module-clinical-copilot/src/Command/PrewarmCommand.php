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
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class PrewarmCommand extends Command
{
    public const NAME = 'copilot:prewarm';

    public function __construct(
        private readonly Config $config,
        private readonly Prewarmer $prewarmer,
        private readonly ClockInterface $clock,
        private readonly DateTimeZone $tz,
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

        $started = hrtime(true);
        $summary = $this->prewarmer->run($day, $onlyPid, $dryRun);
        $totalMs = (int) round((hrtime(true) - $started) / 1e6);

        foreach ($summary->rows as $row) {
            $output->writeln($this->rowLine($row), OutputInterface::VERBOSITY_VERBOSE);
            if ($row->status === PrewarmStatus::Error) {
                $output->writeln(sprintf('<error>pid=%d provider=%s: %s</error>', $row->appointment->pid->value, $row->appointment->providerUsername, $row->error ?? 'unknown error'));
            }
        }
        $modelCalls = count(array_filter($summary->rows, static fn(PrewarmRow $r) => $r->modelCalled));
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
        return $summary->errored > 0 ? Command::FAILURE : Command::SUCCESS;
    }

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
