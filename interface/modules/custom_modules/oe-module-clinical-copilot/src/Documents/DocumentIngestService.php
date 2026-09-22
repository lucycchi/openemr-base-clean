<?php

/**
 * Persists a sidecar extraction into OpenEMR: a complete lab structure (procedure_order + procedure_order_code + procedure_report + procedure_result) for anchored lab results, provenance rows for every field, intake rows for intake forms. One transaction per document; idempotent on (document_id, field_path); a repeat run creates no second set of rows.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Documents;

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Uuid\UuidRegistry;
use OpenEMR\Modules\ClinicalCopilot\PatientId;

final class DocumentIngestService
{
    public const PANEL_CODE = 'COPILOT-PANEL';

    /**
     * @return array{status: DocumentStatus, results_persisted: int, unverified: int, unextracted: int}
     */
    public function persist(PatientId $pid, ExtractionResult $result, string $correlationId): array
    {
        $documentId = $result->documentId;
        if ($result->status === DocumentStatus::Failed || $result->extraction === null) {
            QueryUtils::sqlStatementThrowException(
                "UPDATE copilot_document SET status = 'failed', failure_reason = ?, confidence = NULL, correlation_id = ?, extracted_at = NOW() WHERE document_id = ? AND pid = ?",
                [$result->failureReason ?? 'model_error', $correlationId, $documentId, $pid->value]
            );
            return ['status' => DocumentStatus::Failed, 'results_persisted' => 0, 'unverified' => 0, 'unextracted' => 0];
        }
        if ($this->alreadyPersisted($documentId)) {
            // A repeat run (retry after a network blip, double click) must not create a second set of rows.
            return ['status' => DocumentStatus::Extracted, 'results_persisted' => 0, 'unverified' => 0, 'unextracted' => 0];
        }

        QueryUtils::startTransaction();
        try {
            $counts = $result->extraction instanceof LabReportExtraction
                ? $this->persistLab($pid, $documentId, $result->extraction)
                : $this->persistIntake($pid, $documentId, $result->extraction);
            QueryUtils::sqlStatementThrowException(
                "UPDATE copilot_document SET status = 'extracted', failure_reason = NULL, confidence = ?, correlation_id = ?, extracted_at = NOW() WHERE document_id = ? AND pid = ?",
                [$result->confidence, $correlationId, $documentId, $pid->value]
            );
            QueryUtils::commitTransaction();
        } catch (\Throwable $e) {
            QueryUtils::rollbackTransaction();
            throw $e;
        }
        return ['status' => DocumentStatus::Extracted] + $counts;
    }

    private function alreadyPersisted(int $documentId): bool
    {
        $n = QueryUtils::fetchSingleValue("SELECT COUNT(*) AS n FROM copilot_document_fact WHERE document_id = ?", 'n', [$documentId]);
        $m = QueryUtils::fetchSingleValue("SELECT COUNT(*) AS n FROM copilot_intake WHERE document_id = ?", 'n', [$documentId]);
        return (int) $n + (int) $m > 0;
    }

    /** @return array{results_persisted: int, unverified: int, unextracted: int} */
    private function persistLab(PatientId $pid, int $documentId, LabReportExtraction $lab): array
    {
        $collected = $lab->collectionDate->format('Y-m-d 00:00:00');
        $reported = ($lab->reportedDate ?? $lab->collectionDate)->format('Y-m-d 00:00:00');
        $encounterId = $this->encounterFor($pid, $lab->collectionDate);

        $anchored = array_values(array_filter($lab->results, static fn(LabResultExtraction $r): bool => $r->citation->anchored));
        $orderId = 0;
        $reportId = 0;
        if ($anchored !== []) {
            $orderId = (int) QueryUtils::sqlInsert(
                "INSERT INTO procedure_order (uuid, provider_id, patient_id, encounter_id, date_collected, date_ordered, order_status, activity, procedure_order_type, clinical_hx)
                 VALUES (?, 0, ?, ?, ?, ?, 'complete', 1, 'laboratory_test', ?)",
                [$this->uuid('procedure_order'), $pid->value, $encounterId, $collected, $collected, 'Uploaded lab report; documents.id ' . $documentId]
            );
            QueryUtils::sqlStatementThrowException(
                "INSERT INTO procedure_order_code (procedure_order_id, procedure_order_seq, procedure_code, procedure_name, procedure_source, procedure_type) VALUES (?, 1, ?, ?, '1', 'laboratory_test')",
                [$orderId, self::PANEL_CODE, mb_substr($lab->labName ?? 'Uploaded lab report', 0, 255)]
            );
            $reportId = (int) QueryUtils::sqlInsert(
                "INSERT INTO procedure_report (uuid, procedure_order_id, procedure_order_seq, date_collected, date_report, source, report_status, review_status)
                 VALUES (?, ?, 1, ?, ?, 0, 'final', 'received')",
                [$this->uuid('procedure_report'), $orderId, $collected, $reported]
            );
        }

        $persisted = 0;
        $unverified = 0;
        foreach ($lab->results as $i => $r) {
            $resultId = null;
            if ($r->citation->anchored && !$this->duplicateResult($pid, $r, $lab->collectionDate)) {
                $numeric = $r->numericValue();
                $resultId = (int) QueryUtils::sqlInsert(
                    "INSERT INTO procedure_result (uuid, procedure_report_id, result_data_type, result_code, result_text, date, units, result, `range`, abnormal, document_id, result_status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'final')",
                    [
                        $this->uuid('procedure_result'),
                        $reportId,
                        $numeric === null ? 'S' : 'N',
                        $r->loinc ?? '',
                        mb_substr($r->analyte, 0, 255),
                        $collected,
                        $r->unitMismatch ? mb_substr($r->unit ?? '', 0, 31) : mb_substr($r->unit ?? '', 0, 31),
                        mb_substr($r->value, 0, 255),
                        mb_substr($r->referenceRange ?? '', 0, 255),
                        $r->unitMismatch ? '' : $this->abnormal($r->abnormalFlag),
                        $documentId,
                    ]
                );
                $persisted++;
            } elseif (!$r->citation->anchored) {
                $unverified++;
            }
            $this->fact($documentId, "/results/$i/value", 'lab_result', $r->analyte, $r->value, $r->unit, $r->loinc, $r->referenceRange, $r->abnormalFlag, $r->unitMismatch, $r->citation, $resultId);
        }
        $this->fact($documentId, '/collection_date', 'collection_date', null, $lab->collectionDate->format('Y-m-d'), null, null, null, null, false, $lab->collectionDateCitation, null);
        if ($lab->reportedDate !== null && $lab->reportedDateCitation !== null) {
            $this->fact($documentId, '/reported_date', 'reported_date', null, $lab->reportedDate->format('Y-m-d'), null, null, null, null, false, $lab->reportedDateCitation, null);
        }
        foreach ($lab->unextracted as $i => $u) {
            QueryUtils::sqlInsert(
                "INSERT INTO copilot_document_fact (document_id, field_path, kind, value, anchored, page, row_bbox_json) VALUES (?, ?, 'unextracted_row', ?, 0, ?, ?)",
                [$documentId, "/unextracted/$i", mb_substr($u->text, 0, 255), $u->page, json_encode($u->rowBbox->toArray(), JSON_THROW_ON_ERROR)]
            );
        }
        return ['results_persisted' => $persisted, 'unverified' => $unverified, 'unextracted' => count($lab->unextracted)];
    }

    /** @return array{results_persisted: int, unverified: int, unextracted: int} */
    private function persistIntake(PatientId $pid, int $documentId, IntakeExtraction $intake): array
    {
        $unverified = 0;
        foreach ($intake->items as $item) {
            QueryUtils::sqlInsert(
                "INSERT INTO copilot_intake (document_id, pid, field_path, kind, value, detail, anchored, page, bbox_json, row_bbox_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $documentId,
                    $pid->value,
                    $item->fieldPath,
                    $item->kind,
                    mb_substr($item->value, 0, 500),
                    $item->detail === null ? null : mb_substr($item->detail, 0, 255),
                    $item->citation->anchored ? 1 : 0,
                    $item->citation->bbox?->page,
                    $item->citation->bbox === null ? null : json_encode($item->citation->bbox->toArray(), JSON_THROW_ON_ERROR),
                    $item->citation->rowBbox === null ? null : json_encode($item->citation->rowBbox->toArray(), JSON_THROW_ON_ERROR),
                ]
            );
            if (!$item->citation->anchored) {
                $unverified++;
            }
        }
        return ['results_persisted' => count($intake->items), 'unverified' => $unverified, 'unextracted' => 0];
    }

    private function fact(int $documentId, string $path, string $kind, ?string $analyte, string $value, ?string $unit, ?string $loinc, ?string $range, ?string $flag, bool $mismatch, Citation $c, ?int $resultId): void
    {
        QueryUtils::sqlInsert(
            "INSERT INTO copilot_document_fact (document_id, field_path, kind, analyte, value, unit, loinc, reference_range, abnormal_flag, unit_mismatch, anchored, page, bbox_json, row_bbox_json, procedure_result_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $documentId,
                $path,
                $kind,
                $analyte === null ? null : mb_substr($analyte, 0, 255),
                mb_substr($value, 0, 255),
                $unit === null ? null : mb_substr($unit, 0, 64),
                $loinc,
                $range === null ? null : mb_substr($range, 0, 64),
                $flag,
                $mismatch ? 1 : 0,
                $c->anchored ? 1 : 0,
                $c->bbox?->page,
                $c->bbox === null ? null : json_encode($c->bbox->toArray(), JSON_THROW_ON_ERROR),
                $c->rowBbox === null ? null : json_encode($c->rowBbox->toArray(), JSON_THROW_ON_ERROR),
                $resultId,
            ]
        );
    }

    /** The encounter dated on the collection date, else the latest, else 0 (OpenEMR accepts 0 on an order). */
    private function encounterFor(PatientId $pid, \DateTimeImmutable $collected): int
    {
        $same = QueryUtils::fetchSingleValue("SELECT encounter FROM form_encounter WHERE pid = ? AND DATE(date) = ? ORDER BY encounter DESC LIMIT 1", 'encounter', [$pid->value, $collected->format('Y-m-d')]);
        if (is_numeric($same)) {
            return (int) $same;
        }
        $latest = QueryUtils::fetchSingleValue("SELECT encounter FROM form_encounter WHERE pid = ? ORDER BY date DESC, encounter DESC LIMIT 1", 'encounter', [$pid->value]);
        return is_numeric($latest) ? (int) $latest : 0;
    }

    /** An existing result with the same LOINC, date and value is the same result (e.g. the lab interface already imported it). */
    private function duplicateResult(PatientId $pid, LabResultExtraction $r, \DateTimeImmutable $collected): bool
    {
        if ($r->loinc === null) {
            return false;
        }
        $n = QueryUtils::fetchSingleValue(
            "SELECT COUNT(*) AS n FROM procedure_result pr
             JOIN procedure_report prp ON prp.procedure_report_id = pr.procedure_report_id
             JOIN procedure_order po ON po.procedure_order_id = prp.procedure_order_id
             WHERE po.patient_id = ? AND pr.result_code = ? AND pr.result = ? AND DATE(COALESCE(pr.date, prp.date_collected, po.date_collected)) = ?",
            'n',
            [$pid->value, $r->loinc, $r->value, $collected->format('Y-m-d')]
        );
        return (int) $n > 0;
    }

    private function abnormal(?string $flag): string
    {
        return match ($flag) {
            'H', 'HH' => 'high',
            'L', 'LL' => 'low',
            'A' => 'yes',
            'N' => 'no',
            default => '',
        };
    }

    private function uuid(string $table): string
    {
        $registry = new UuidRegistry(['table_name' => $table, 'table_id' => $table . '_id']);
        $uuid = $registry->createUuid();
        return is_string($uuid) ? $uuid : throw new \RuntimeException('uuid');
    }
}
