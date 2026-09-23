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

/**
 * Where uploaded PDFs go and how they are found again. Two tables share the
 * work: OpenEMR's own documents table (and its file storage, possibly
 * encrypted) holds the bytes, so the file shows up in the patient's
 * Documents tab like any other upload; the module's copilot_document table
 * holds what the Co-Pilot needs to know about it (type, extraction status,
 * a hash for deduplication). The two are joined by documents.id.
 *
 *   store():  reject bad files -> hash -> same file for this patient before?
 *             -> yes: return the old row; no: file it in OpenEMR + insert
 *   find():   one document, scoped to the patient (a foreign id finds nothing)
 *   list():   the patient's documents, newest first
 *   bytes():  the file back out of OpenEMR storage
 */
final class DocumentStore
{
    /** The upload cap: 20 MB, checked on the bytes actually received, not on what the browser claims. */
    public const MAX_BYTES = 20 * 1024 * 1024;

    /**
     * Store a PDF for the patient. Returns the copilot_document row; if the
     * same bytes were uploaded for this patient before, returns the existing
     * row with existing=true and stores nothing. A prior row whose document
     * was deleted in OpenEMR is re-pointed at the new upload.
     *
     * "Existing" is decided by content, not by filename: two uploads of the
     * same file under different names are one document, and the same file
     * for two different patients is two documents.
     *
     * @return array{id: int, document_id: int, existing: bool, status: DocumentStatus}
     */
    public function store(PatientId $pid, DocType $type, string $filename, string $bytes, string $uploadedBy, int $ownerUserId): array
    {
        // 1. Cheap refusals before any database work: size, then the PDF magic bytes.
        //    "%PDF-" is how every PDF file begins; a renamed image or a Word file fails here.
        if (strlen($bytes) > self::MAX_BYTES) {
            throw new UploadRejected('too_large');
        }
        if (!str_starts_with($bytes, '%PDF-')) {
            throw new UploadRejected('not_a_pdf');
        }
        // 2. Fingerprint the bytes. sha3-512 is what OpenEMR's documents.hash uses too,
        //    so the two tables agree on what "the same file" means.
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

        // 3. File it through OpenEMR's Document class, which picks the storage method
        //    and encryption the site is configured for. The category decides which
        //    folder of the Documents tab it appears in.
        $categoryId = QueryUtils::fetchSingleValue("SELECT id FROM categories WHERE name = ? LIMIT 1", 'id', [$type->categoryName()]);
        if (!is_numeric($categoryId)) {
            throw new \RuntimeException('Stock document category missing: ' . $type->categoryName());
        }
        // The filename becomes part of a path on disk, so anything outside a safe set is replaced.
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
        // 4a. The hash was seen before but its OpenEMR document was deleted: reuse the
        //     copilot_document row (the UNIQUE (pid, hash) key would refuse a second one)
        //     and reset it to a fresh, unextracted state pointing at the new file.
        if ($existing !== null) {
            QueryUtils::sqlStatementThrowException(
                "UPDATE copilot_document SET document_id = ?, status = 'stored', failure_reason = NULL, confidence = NULL, uploaded_by = ?, created_at = NOW(), extracted_at = NULL WHERE id = ?",
                [$documentId, $uploadedBy, Row::int($existing, 'id')]
            );
            return ['id' => Row::int($existing, 'id'), 'document_id' => $documentId, 'existing' => false, 'status' => DocumentStatus::Stored];
        }
        // 4b. A file this patient has never had: one new row, status "stored".
        $id = QueryUtils::sqlInsert(
            "INSERT INTO copilot_document (document_id, pid, doc_type, hash, status, uploaded_by) VALUES (?, ?, ?, ?, 'stored', ?)",
            [$documentId, $pid->value, $type->value, $hash, $uploadedBy]
        );
        return ['id' => (int) $id, 'document_id' => $documentId, 'existing' => false, 'status' => DocumentStatus::Stored];
    }

    /**
     * One document by id, but only if it belongs to this patient. The
     * patient id always comes from the session, so a document id typed into
     * a request for another chart simply finds nothing.
     *
     * @return array{id: int, document_id: int, pid: int, doc_type: DocType, hash: string, status: DocumentStatus, failure_reason: ?string, confidence: ?float}|null
     */
    public function find(PatientId $pid, int $documentId): ?array
    {
        $row = QueryUtils::querySingleRow("SELECT * FROM copilot_document WHERE pid = ? AND document_id = ?", [$pid->value, $documentId]);
        return is_array($row) ? $this->row($row) : null;
    }

    /**
     * The patient's documents for the panel's list, newest first, with the
     * filename from OpenEMR's table. Documents deleted in OpenEMR are left
     * out even though their copilot_document row remains.
     *
     * @return list<array{id: int, document_id: int, pid: int, doc_type: DocType, hash: string, status: DocumentStatus, failure_reason: ?string, confidence: ?float, created_at: string, filename: string}>
     */
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

    /** Resets a failed document to "stored" so a retry starts from a clean state. */
    public function markStored(int $documentId): void
    {
        QueryUtils::sqlStatementThrowException("UPDATE copilot_document SET status = 'stored', failure_reason = NULL WHERE document_id = ?", [$documentId]);
    }

    /**
     * Turns a raw database row (every column a string or null) into typed
     * values: ints, enums, a float or null. Done in one place so every
     * caller sees the same shape.
     *
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
