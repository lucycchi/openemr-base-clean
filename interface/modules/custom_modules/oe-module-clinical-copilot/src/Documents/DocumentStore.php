<?php

/**
 * Uploaded documents: files go through OpenEMR's Document API (documents table and storage), the Co-Pilot keeps its own row per document with type, status and the patient-scoped hash used for deduplication.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Documents;

use Document;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Modules\ClinicalCopilot\PatientId;
use OpenEMR\Modules\ClinicalCopilot\Row;

final class DocumentStore
{
    public const MAX_BYTES = 20 * 1024 * 1024;

    /**
     * Store a PDF for the patient. Returns the copilot_document row; if the
     * same bytes were uploaded for this patient before, returns the existing
     * row with existing=true and stores nothing. A prior row whose document
     * was deleted in OpenEMR is re-pointed at the new upload.
     *
     * @return array{id: int, document_id: int, existing: bool, status: DocumentStatus}
     */
    public function store(PatientId $pid, DocType $type, string $filename, string $bytes, string $uploadedBy, int $ownerUserId): array
    {
        if (strlen($bytes) > self::MAX_BYTES) {
            throw new UploadRejected('too_large');
        }
        if (!str_starts_with($bytes, '%PDF-')) {
            throw new UploadRejected('not_a_pdf');
        }
        $hash = hash('sha3-512', $bytes);
        $existing = QueryUtils::querySingleRow(
            "SELECT cd.id, cd.document_id, cd.status, d.deleted FROM copilot_document cd LEFT JOIN documents d ON d.id = cd.document_id WHERE cd.pid = ? AND cd.hash = ?",
            [$pid->value, $hash]
        );
        $existing = is_array($existing) ? $existing : null; // querySingleRow returns false for no row
        // A LEFT JOIN with no documents row reads as deleted (null), so only an explicit 0 keeps the upload.
        $deleted = $existing['deleted'] ?? null;
        if ($existing !== null && is_numeric($deleted) && (int) $deleted === 0) {
            return ['id' => Row::int($existing, 'id'), 'document_id' => Row::int($existing, 'document_id'), 'existing' => true, 'status' => DocumentStatus::from(Row::str($existing, 'status'))];
        }

        $categoryId = QueryUtils::fetchSingleValue("SELECT id FROM categories WHERE name = ? LIMIT 1", 'id', [$type->categoryName()]);
        if (!is_numeric($categoryId)) {
            throw new \RuntimeException('Stock document category missing: ' . $type->categoryName());
        }
        $safeName = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) ?: 'upload.pdf';
        $doc = new Document();
        $data = $bytes;
        $error = $doc->createDocument((string) $pid->value, (int) $categoryId, $safeName, 'application/pdf', $data, '', 1, $ownerUserId);
        if ($error !== '') {
            throw new \RuntimeException('Document storage failed');
        }
        $newId = $doc->get_id();
        if (!is_numeric($newId) || (int) $newId <= 0) {
            throw new \RuntimeException('Document storage returned no id');
        }
        $documentId = (int) $newId;
        if ($existing !== null) {
            QueryUtils::sqlStatementThrowException(
                "UPDATE copilot_document SET document_id = ?, status = 'stored', failure_reason = NULL, confidence = NULL, uploaded_by = ?, created_at = NOW(), extracted_at = NULL WHERE id = ?",
                [$documentId, $uploadedBy, Row::int($existing, 'id')]
            );
            return ['id' => Row::int($existing, 'id'), 'document_id' => $documentId, 'existing' => false, 'status' => DocumentStatus::Stored];
        }
        $id = QueryUtils::sqlInsert(
            "INSERT INTO copilot_document (document_id, pid, doc_type, hash, status, uploaded_by) VALUES (?, ?, ?, ?, 'stored', ?)",
            [$documentId, $pid->value, $type->value, $hash, $uploadedBy]
        );
        return ['id' => (int) $id, 'document_id' => $documentId, 'existing' => false, 'status' => DocumentStatus::Stored];
    }

    /** @return array{id: int, document_id: int, pid: int, doc_type: DocType, hash: string, status: DocumentStatus, failure_reason: ?string, confidence: ?float}|null */
    public function find(PatientId $pid, int $documentId): ?array
    {
        $row = QueryUtils::querySingleRow("SELECT * FROM copilot_document WHERE pid = ? AND document_id = ?", [$pid->value, $documentId]);
        return is_array($row) ? $this->row($row) : null;
    }

    /** @return list<array{id: int, document_id: int, pid: int, doc_type: DocType, hash: string, status: DocumentStatus, failure_reason: ?string, confidence: ?float, created_at: string, filename: string}> */
    public function list(PatientId $pid): array
    {
        $rows = QueryUtils::fetchRecords(
            "SELECT cd.*, d.name AS filename FROM copilot_document cd JOIN documents d ON d.id = cd.document_id WHERE cd.pid = ? AND d.deleted = 0 ORDER BY cd.created_at DESC, cd.id DESC",
            [$pid->value]
        );
        return array_map(fn(array $r) => $this->row($r) + ['created_at' => Row::str($r, 'created_at'), 'filename' => Row::str($r, 'filename')], $rows);
    }

    /** The file bytes, through OpenEMR's Document class so storage method and encryption are honoured. */
    public function bytes(int $documentId): string
    {
        $doc = new Document($documentId);
        $data = $doc->get_data();
        if (!is_string($data) || $data === '') {
            throw new \RuntimeException('Document has no data');
        }
        return $data;
    }

    public function markStored(int $documentId): void
    {
        QueryUtils::sqlStatementThrowException("UPDATE copilot_document SET status = 'stored', failure_reason = NULL WHERE document_id = ?", [$documentId]);
    }

    /**
     * @param array<mixed> $r  a copilot_document row as the query layer returns it
     * @return array{id: int, document_id: int, pid: int, doc_type: DocType, hash: string, status: DocumentStatus, failure_reason: ?string, confidence: ?float}
     */
    private function row(array $r): array
    {
        return [
            'id' => Row::int($r, 'id'),
            'document_id' => Row::int($r, 'document_id'),
            'pid' => Row::int($r, 'pid'),
            'doc_type' => DocType::from(Row::str($r, 'doc_type')),
            'hash' => Row::str($r, 'hash'),
            'status' => DocumentStatus::from(Row::str($r, 'status')),
            'failure_reason' => is_string($r['failure_reason'] ?? null) ? $r['failure_reason'] : null,
            'confidence' => is_numeric($r['confidence'] ?? null) ? (float) $r['confidence'] : null,
        ];
    }
}
