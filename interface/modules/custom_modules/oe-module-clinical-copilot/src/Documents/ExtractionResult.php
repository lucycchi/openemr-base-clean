<?php

/**
 * One document's outcome inside a run.response.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Documents;

final readonly class ExtractionResult
{
    public function __construct(
        public int $documentId,
        public DocumentStatus $status,
        public ?string $failureReason,
        public LabReportExtraction|IntakeExtraction|null $extraction,
        public float $confidence,
        /** Model calls beyond one per page (the sidecar's omission-driven re-ask); the dashboard's retry count. */
        public int $retries = 0,
    ) {
    }

    /** @param array<mixed> $a  decoded JSON; every value is narrowed here */
    public static function fromArray(array $a): self
    {
        $status = is_string($a['status'] ?? null) ? DocumentStatus::tryFrom($a['status']) : null;
        if (!is_int($a['document_id'] ?? null) || $status === null || $status === DocumentStatus::Stored || !is_numeric($a['confidence'] ?? null)) {
            throw new SidecarException('schema_mismatch');
        }
        $ext = null;
        if (is_array($a['extraction'] ?? null)) {
            $ext = ($a['extraction']['doc_type'] ?? null) === 'lab_pdf' ? LabReportExtraction::fromArray($a['extraction']) : IntakeExtraction::fromArray($a['extraction']);
        }
        if ($status === DocumentStatus::Extracted && $ext === null) {
            throw new SidecarException('schema_mismatch');
        }
        $retries = $a['retries'] ?? 0;
        return new self($a['document_id'], $status, is_string($a['failure_reason'] ?? null) ? $a['failure_reason'] : null, $ext, (float) $a['confidence'], is_int($retries) && $retries >= 0 ? $retries : 0);
    }
}
