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

/**
 * The same upload-then-extract flow as the panel, minus the browser:
 *
 *   arguments -> DocumentStore::store -> ExtractionRunner::run -> summary
 *
 * Useful for loading a batch of test documents and for reproducing an
 * extraction outside the UI. There is no session here, so there is no CSRF
 * and no ACL check; whoever can run the console inside the container is
 * trusted, and the uploader name is recorded from --user.
 */
final class AttachCommand extends Command
{
    public const NAME = 'copilot:attach';

    /** Both collaborators are injected so the command can be wired with test doubles. */
    public function __construct(private readonly DocumentStore $store, private readonly ExtractionRunner $runner)
    {
        parent::__construct(self::NAME);
    }

    /** Declares the arguments and options Symfony Console parses and shows in --help. */
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

    /**
     * Runs the flow and prints a summary. The exit code is the result: 2
     * (INVALID) for bad arguments, 1 (FAILURE) when storage or the sidecar
     * refused or the extraction failed, 0 only for "extracted".
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // 1. Arguments. Console arguments arrive as strings (or null); each is narrowed here.
        //    The pid must be all digits and positive; the doc_type must be one of the enum's values.
        $pidArg = $input->getArgument('pid');
        $file = $input->getArgument('file');
        $type = DocType::tryFrom(is_string($input->getArgument('doc_type')) ? $input->getArgument('doc_type') : '');
        if (!is_string($pidArg) || !ctype_digit($pidArg) || (int) $pidArg <= 0 || !is_string($file) || $type === null) {
            $output->writeln('<error>usage: copilot:attach <pid> <file.pdf> <lab_pdf|intake_form></error>');
            return Command::INVALID;
        }
        // The "@" silences PHP's own warning for an unreadable path; the false return is handled.
        $bytes = @file_get_contents($file);
        if ($bytes === false) {
            $output->writeln('<error>cannot read file</error>');
            return Command::INVALID;
        }
        $pid = new PatientId((int) $pidArg);
        $user = is_string($input->getOption('user')) ? $input->getOption('user') : 'admin';
        $correlationId = CorrelationId::generate();
        // 2. Store, with the same size and PDF checks and the same per-patient dedup as the panel.
        //    Owner user id 0: there is no logged-in OpenEMR user on the console.
        try {
            $stored = $this->store->store($pid, $type, basename($file), $bytes, $user, 0);
        } catch (UploadRejected $e) {
            $output->writeln('<error>upload rejected: ' . $e->reason . '</error>');
            return Command::FAILURE;
        }
        // 3. Read the row back in the shape the runner wants (status, hash, type).
        $doc = $this->store->find($pid, $stored['document_id']);
        if ($doc === null) {
            $output->writeln('<error>stored document not found</error>');
            return Command::FAILURE;
        }
        // 4. Extract and persist. On a sidecar failure the file stays stored, as in the panel.
        try {
            $outcome = $this->runner->run($pid, $doc, $correlationId);
        } catch (SidecarException $e) {
            $output->writeln(sprintf('<error>sidecar failed: %s (document %d is stored; retry later)</error>', $e->errorCode, $doc['document_id']));
            return Command::FAILURE;
        }
        // 5. The summary: the same counts the panel logs. extraction is null when the document
        //    was already extracted, so the confidence falls back to the stored row's value.
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
        // --json for scripts; otherwise an aligned key/value listing for a person.
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
