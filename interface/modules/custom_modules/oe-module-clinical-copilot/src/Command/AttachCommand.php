<?php

/**
 * copilot:attach <pid> <file> <lab_pdf|intake_form>: the brief's
 * attach_and_extract(patient_id, file_path, doc_type) as a console command.
 * Stores the PDF for the patient through the same DocumentStore the panel
 * uses, runs the sidecar extraction and persists the result, then prints a
 * summary. Exit codes: 0 extracted, 1 the sidecar or storage refused, 2 bad
 * arguments. Runs as bin/console inside the openemr container.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Command;

use OpenEMR\Modules\ClinicalCopilot\CorrelationId;
use OpenEMR\Modules\ClinicalCopilot\Documents\DocType;
use OpenEMR\Modules\ClinicalCopilot\Documents\DocumentStore;
use OpenEMR\Modules\ClinicalCopilot\Documents\ExtractionRunner;
use OpenEMR\Modules\ClinicalCopilot\Documents\SidecarException;
use OpenEMR\Modules\ClinicalCopilot\Documents\UploadRejected;
use OpenEMR\Modules\ClinicalCopilot\PatientId;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class AttachCommand extends Command
{
    public const NAME = 'copilot:attach';

    public function __construct(private readonly DocumentStore $store, private readonly ExtractionRunner $runner)
    {
        parent::__construct(self::NAME);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Attach a lab PDF or intake form to a patient and extract it (attach_and_extract)')
            ->addArgument('pid', InputArgument::REQUIRED, 'Patient id')
            ->addArgument('file', InputArgument::REQUIRED, 'Path to a PDF')
            ->addArgument('doc_type', InputArgument::REQUIRED, 'lab_pdf or intake_form')
            ->addOption('site', null, InputOption::VALUE_REQUIRED, 'OpenEMR site (consumed by bin/console)', 'default')
            ->addOption('user', null, InputOption::VALUE_REQUIRED, 'Username recorded as the uploader', 'admin')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print the summary as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $pidArg = $input->getArgument('pid');
        $file = $input->getArgument('file');
        $type = DocType::tryFrom(is_string($input->getArgument('doc_type')) ? $input->getArgument('doc_type') : '');
        if (!is_string($pidArg) || !ctype_digit($pidArg) || (int) $pidArg <= 0 || !is_string($file) || $type === null) {
            $output->writeln('<error>usage: copilot:attach <pid> <file.pdf> <lab_pdf|intake_form></error>');
            return Command::INVALID;
        }
        $bytes = @file_get_contents($file);
        if ($bytes === false) {
            $output->writeln('<error>cannot read file</error>');
            return Command::INVALID;
        }
        $pid = new PatientId((int) $pidArg);
        $user = is_string($input->getOption('user')) ? $input->getOption('user') : 'admin';
        $correlationId = CorrelationId::generate();
        try {
            $stored = $this->store->store($pid, $type, basename($file), $bytes, $user, 0);
        } catch (UploadRejected $e) {
            $output->writeln('<error>upload rejected: ' . $e->reason . '</error>');
            return Command::FAILURE;
        }
        $doc = $this->store->find($pid, $stored['document_id']);
        if ($doc === null) {
            $output->writeln('<error>stored document not found</error>');
            return Command::FAILURE;
        }
        try {
            $outcome = $this->runner->run($pid, $doc, $correlationId);
        } catch (SidecarException $e) {
            $output->writeln(sprintf('<error>sidecar failed: %s (document %d is stored; retry later)</error>', $e->errorCode, $doc['document_id']));
            return Command::FAILURE;
        }
        $extraction = $outcome['extraction'];
        $summary = [
            'correlation_id' => $correlationId,
            'document_id' => $doc['document_id'],
            'doc_type' => $type->value,
            'existing_upload' => $stored['existing'],
            'already_extracted' => $outcome['already'],
            'status' => $outcome['persisted']['status']->value,
            'failure_reason' => $extraction?->failureReason,
            'confidence' => $extraction->confidence ?? $doc['confidence'],
            'results_persisted' => $outcome['persisted']['results_persisted'],
            'unverified' => $outcome['persisted']['unverified'],
            'unextracted' => $outcome['persisted']['unextracted'],
            'handoffs' => array_map(static fn($h) => $h->from . ' -> ' . $h->to . ' (' . $h->reason . ')', $outcome['run']->handoffs ?? []),
            'model_calls' => $outcome['run']?->chatTokens()['calls'] ?? 0,
        ];
        if ($input->getOption('json')) {
            $output->writeln(json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } else {
            foreach ($summary as $k => $v) {
                $output->writeln(sprintf('%-18s %s', $k, is_array($v) ? implode('; ', $v) : json_encode($v)));
            }
        }
        return $summary['status'] === 'extracted' ? Command::SUCCESS : Command::FAILURE;
    }
}
