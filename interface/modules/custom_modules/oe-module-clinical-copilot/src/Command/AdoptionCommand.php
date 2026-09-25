<?php

/**
 * copilot:adoption: KEY_METRICS.md metric 7, chat adoption per patient encounter.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Command;

use OpenEMR\Modules\ClinicalCopilot\AdoptionReport;
use OpenEMR\Modules\ClinicalCopilot\AdoptionRow;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Prints, per physician per day and per ISO week, the patient encounters,
 * how many of them the chat was used on (at least one typed question), that
 * share, and the mean questions per used encounter; then the whole window.
 * --json prints the same for the weekly review. Counts only: no question
 * text is read.
 *
 *   php bin/console copilot:adoption --days=7
 */
final class AdoptionCommand extends Command
{
    public const NAME = 'copilot:adoption';

    public function __construct(
        private readonly AdoptionReport $report,
        private readonly ClockInterface $clock,
        private readonly \DateTimeZone $tz,
    ) {
        parent::__construct(self::NAME);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Chat adoption per patient encounter (metric 7): share of encounters the chat was used on')
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
        $perWeek = $this->report->weeks($from, $to);
        $total = $this->report->total($from, $to);

        if ($input->getOption('json')) {
            $output->writeln(json_encode([
                'metric' => 'chat_adoption_per_encounter',
                'days' => array_map(static fn(AdoptionRow $r): array => $r->toArray(), $perDay),
                'weeks' => array_map(static fn(AdoptionRow $r): array => $r->toArray(), $perWeek),
                'total' => $total->toArray(),
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            return Command::SUCCESS;
        }

        foreach (['day' => $perDay, 'week' => $perWeek, 'window' => [$total]] as $period => $rows) {
            $table = new Table($output);
            $table->setHeaders([$period, 'physician', 'encounters', 'chat used', 'used %', 'questions per used encounter']);
            foreach ($rows as $r) {
                $table->addRow([$r->period, $r->physician, $r->encounters, $r->used, $r->share() ?? '-', $r->meanAsks() ?? '-']);
            }
            $table->render();
        }
        return Command::SUCCESS;
    }
}
