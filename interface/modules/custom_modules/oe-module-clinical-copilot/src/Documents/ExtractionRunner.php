<?php

/**
 * The one extraction path shared by the HTTP controller and the
 * copilot:attach console command: read the stored bytes, send them to the
 * sidecar, persist what comes back. Returns everything the caller needs to
 * log, trace and respond; it does no logging itself so each entry point
 * keeps its own correlation id and audit shape.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Documents;

use OpenEMR\Modules\ClinicalCopilot\PatientId;

final readonly class ExtractionRunner
{
    public function __construct(
        private DocumentStore $store,
        private SidecarClient $sidecar,
        private DocumentIngestService $ingest,
    ) {
    }

    /**
     * Runs one document. A document already extracted is returned as-is
     * (already=true); a failed one is reset to stored so its bytes are sent
     * again. SidecarException propagates: the caller decides how to report it.
     *
     * @param array{id: int, document_id: int, pid: int, doc_type: DocType, hash: string, status: DocumentStatus, failure_reason: ?string, confidence: ?float} $doc a row from DocumentStore::find()
     * @return array{already: bool, run: ?RunResult, extraction: ?ExtractionResult, persisted: array{status: DocumentStatus, results_persisted: int, unverified: int, unextracted: int}}
     */
    public function run(PatientId $pid, array $doc, string $correlationId): array
    {
        if ($doc['status'] === DocumentStatus::Extracted) {
            return ['already' => true, 'run' => null, 'extraction' => null, 'persisted' => ['status' => DocumentStatus::Extracted, 'results_persisted' => 0, 'unverified' => 0, 'unextracted' => 0]];
        }
        if ($doc['status'] === DocumentStatus::Failed) {
            $this->store->markStored($doc['document_id']);
        }
        $bytes = $this->store->bytes($doc['document_id']);
        $run = $this->sidecar->extract(
            $correlationId,
            hash('sha256', $doc['hash']), // the sidecar wants a facts hash; documents carry none, so the file hash stands in
            [['document_id' => $doc['document_id'], 'doc_type' => $doc['doc_type'], 'sha3_512' => $doc['hash'], 'bytes' => $bytes]]
        );
        $extraction = null;
        foreach ($run->extractions as $x) {
            if ($x->documentId === $doc['document_id']) {
                $extraction = $x;
            }
        }
        if ($extraction === null) {
            throw new SidecarException('schema_mismatch');
        }
        $persisted = $this->ingest->persist($pid, $extraction, $correlationId);
        return ['already' => false, 'run' => $run, 'extraction' => $extraction, 'persisted' => $persisted];
    }
}
