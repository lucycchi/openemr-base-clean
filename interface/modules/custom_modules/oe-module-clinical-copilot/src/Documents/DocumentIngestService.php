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
use OpenEMR\Modules\ClinicalCopilot\Row;

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

        $extraction = $result->extraction;
        // One transaction per document: the rows and the status flip land together or not at all.
        $counts = QueryUtils::inTransaction(function () use ($pid, $documentId, $extraction, $result, $correlationId): array {
            $counts = $extraction instanceof LabReportExtraction
                ? $this->persistLab($pid, $documentId, $extraction)
                : $this->persistIntake($pid, $documentId, $extraction);
            QueryUtils::sqlStatementThrowException(
                "UPDATE copilot_document SET status = 'extracted', failure_reason = NULL, confidence = ?, correlation_id = ?, extracted_at = NOW() WHERE document_id = ? AND pid = ?",
                [$result->confidence, $correlationId, $documentId, $pid->value]
            );
            return $counts;
        });
        return ['status' => DocumentStatus::Extracted] + $counts;
    }

    private function alreadyPersisted(int $documentId): bool
    {
        $n = QueryUtils::fetchSingleValue("SELECT COUNT(*) AS n FROM copilot_document_fact WHERE document_id = ?", 'n', [$documentId]);
        $m = QueryUtils::fetchSingleValue("SELECT COUNT(*) AS n FROM copilot_intake WHERE document_id = ?", 'n', [$documentId]);
        return self::count($n) + self::count($m) > 0;
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
        if ($lab->patientNameOnReport !== null && $this->demographicsMismatches($pid, ['name' => $lab->patientNameOnReport]) !== []) {
            QueryUtils::sqlInsert(
                "INSERT INTO copilot_document_fact (document_id, field_path, kind, value, anchored, page) VALUES (?, '/patient_name_on_report', 'patient_mismatch', 'patient name on the report does not match the chart', 1, 1)",
                [$documentId]
            );
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
        // Demographics on the form are compared with the chart and never stored;
        // only the fact of a mismatch is kept, as a kind with a fixed value.
        foreach ($this->demographicsMismatches($pid, $intake->demographics) as $i => $what) {
            QueryUtils::sqlInsert(
                "INSERT INTO copilot_intake (document_id, pid, field_path, kind, value, detail, anchored, page) VALUES (?, ?, ?, 'demographics_mismatch', ?, NULL, 1, 1)",
                [$documentId, $pid->value, "/demographics/mismatch/$i", $what]
            );
        }
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

    /**
     * Which demographics printed on the form disagree with the chart. Names
     * match when every chart name token appears on the form (order and
     * middle names ignored); dates match as dates; sex by first letter;
     * phones by digits. Values never leave this method.
     *
     * @param array<string, string> $onForm name/dob/sex/phone as printed
     * @return list<string> fixed descriptions, e.g. "name on the form does not match the chart"
     */
    private function demographicsMismatches(PatientId $pid, array $onForm): array
    {
        if ($onForm === []) {
            return [];
        }
        $chart = QueryUtils::querySingleRow("SELECT fname, lname, DOB, sex, phone_home, phone_cell FROM patient_data WHERE pid = ?", [$pid->value]);
        if (!is_array($chart)) {
            return [];
        }
        $out = [];
        $norm = static fn(string $s): string => preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower($s)) ?? '';
        if (isset($onForm['name'])) {
            $form = ' ' . trim($norm($onForm['name'])) . ' ';
            foreach ([Row::str($chart, 'fname'), Row::str($chart, 'lname')] as $token) {
                $t = trim($norm($token));
                if ($t !== '' && !str_contains($form, " $t ")) {
                    $out[] = 'name on the form does not match the chart';
                    break;
                }
            }
        }
        if (isset($onForm['dob']) && is_string($chart['DOB'] ?? null) && $chart['DOB'] !== '0000-00-00') {
            $formDob = null;
            foreach (['m/d/Y', 'Y-m-d', 'd/m/Y', 'm-d-Y'] as $fmt) {
                $d = \DateTimeImmutable::createFromFormat('!' . $fmt, trim($onForm['dob']));
                if ($d !== false) {
                    $formDob = $d;
                    break;
                }
            }
            if ($formDob !== null && $formDob->format('Y-m-d') !== substr($chart['DOB'], 0, 10)) {
                $out[] = 'date of birth on the form does not match the chart';
            }
        }
        if (isset($onForm['sex']) && is_string($chart['sex'] ?? null) && $chart['sex'] !== '') {
            if (strtolower(substr(trim($onForm['sex']), 0, 1)) !== strtolower(substr($chart['sex'], 0, 1))) {
                $out[] = 'sex on the form does not match the chart';
            }
        }
        if (isset($onForm['phone'])) {
            $digits = static fn(string $s): string => preg_replace('/\D/', '', $s) ?? '';
            $formPhone = $digits($onForm['phone']);
            $chartPhones = array_filter([$digits(Row::str($chart, 'phone_home')), $digits(Row::str($chart, 'phone_cell'))]);
            if ($formPhone !== '' && $chartPhones !== [] && !in_array($formPhone, $chartPhones, true)) {
                $out[] = 'phone on the form does not match the chart';
            }
        }
        return $out;
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
        return self::count($n) > 0;
    }

    /** A COUNT(*) as the query layer returns it (string or int), as an int. */
    private static function count(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
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
        return $registry->createUuid();
    }
}
