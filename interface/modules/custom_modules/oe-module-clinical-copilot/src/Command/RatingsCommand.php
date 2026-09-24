<?php

/**
 * copilot:ratings: KEY_METRICS.md metric 6 (physician rating of the summary) for the last N days.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Command;

use OpenEMR\Modules\ClinicalCopilot\RatingDay;
use OpenEMR\Modules\ClinicalCopilot\RatingReport;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Prints, per day, the briefings rendered and the shares rated up, rated
 * down and not rated (they sum to 100), then the ratings per prompt version
 * and model. --json prints the same as JSON for the weekly review. Counts
 * and shares only: rating comments are read in the EHR, not here.
 *
 *   php bin/console copilot:ratings --days=7
 */
final class RatingsCommand extends Command
{
    public const NAME = 'copilot:ratings';

    public function __construct(
        private readonly RatingReport $report,
        private readonly ClockInterface $clock,
        private readonly \DateTimeZone $tz,
    ) {
        parent::__construct(self::NAME);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Physician rating of the AI summary (metric 6): up, down and not rated per day')
            ->addOption('site', null, InputOption::VALUE_REQUIRED, 'OpenEMR site (consumed by bin/console)', 'default')
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'How many days back, today included', '7')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print JSON instead of tables');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $days = $input->getOption('days');
        if (!is_string($days) || !ctype_digit($days) || (int) $days < 1 || (int) $days > 366) {
            $output->writeln('<error>--days must be a whole number from 1 to 366</error>');
            return Command::INVALID;
        }
        $to = $this->clock->now()->setTimezone($this->tz)->setTime(0, 0)->modify('+1 day');
        $from = $to->modify('-' . $days . ' days');
        $perDay = $this->report->days($from, $to);
        $total = $this->report->total($from, $to);
        $byVersion = $this->report->byVersion($from, $to);

        if ($input->getOption('json')) {
            $output->writeln(json_encode([
                'metric' => 'physician_rating',
                'days' => array_map(static fn(RatingDay $d): array => $d->toArray(), $perDay),
                'total' => $total->toArray(),
                'by_prompt_version' => $byVersion,
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            return Command::SUCCESS;
        }

        $table = new Table($output);
        $table->setHeaders(['day', 'rendered', 'up', 'down', 'not rated', 'up %', 'down %', 'not rated %']);
        foreach ([...$perDay, $total] as $d) {
            $shares = $d->shares();
            $table->addRow([$d->day, $d->rendered, $d->up, $d->down, $d->notRated(), $shares['up'] ?? '-', $shares['down'] ?? '-', $shares['not_rated'] ?? '-']);
        }
        $table->render();
        if ($byVersion !== []) {
            $versions = new Table($output);
            $versions->setHeaders(['prompt version', 'model', 'up', 'down']);
            foreach ($byVersion as $v) {
                $versions->addRow([$v['prompt_version'], $v['model'], $v['up'], $v['down']]);
            }
            $versions->render();
        }
        return Command::SUCCESS;
    }
}
