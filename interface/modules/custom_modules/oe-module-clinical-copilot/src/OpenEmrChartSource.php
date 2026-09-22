<?php

/**
 * ChartSource over the OpenEMR database. Read-only, patient-scoped queries.
 *
 * The service layer (EncounterService, PrescriptionService, ...) is not used
 * here because its result rows omit what the assembler needs to cite facts:
 * prescriptions carry no numeric id or start date, and lab results carry no
 * encounter link (needed for the sensitivity filter). These queries read the
 * same tables the services do. No direct identifiers (name, DOB, MRN) are
 * selected anywhere in this class.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot;

use DateTimeImmutable;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Modules\ClinicalCopilot\Documents\BBox;
use OpenEMR\Modules\ClinicalCopilot\Documents\Citation;

/**
 * The real ChartSource: five SQL queries against OpenEMR's legacy tables
 * (form_encounter, prescriptions, lists, procedure_*), each mapped straight
 * into the module's typed records. All the schema quirks — '0000-00-00'
 * placeholder dates, string-typed numeric columns, sentinel end dates —
 * are absorbed here so nothing upstream has to know about them. All values
 * are bound parameters (the `?` placeholders); no string interpolation.
 */
final class OpenEmrChartSource implements ChartSource
{
    public function encounters(PatientId $pid): array
    {
        $rows = QueryUtils::fetchRecords(
            "SELECT encounter, date, sensitivity, reason FROM form_encounter WHERE pid = ? AND date IS NOT NULL",
            [$pid->value]
        );
        return array_map(
            fn(array $r) => new EncounterRecord(
                Row::int($r, 'encounter'),
                $this->date(Row::str($r, 'date')),
                Row::str($r, 'sensitivity'),
                Row::str($r, 'reason'),
            ),
            $rows
        );
    }

    public function medications(PatientId $pid): array
    {
        // NULLIF turns the '0000-00-00' placeholder into NULL so the
        // provenance logic sees "no start date recorded".
        $rows = QueryUtils::fetchRecords(
            "SELECT id, drug, NULLIF(start_date, '0000-00-00') AS start_date, date_added, active, end_date
             FROM prescriptions WHERE patient_id = ? AND drug <> ''",
            [$pid->value]
        );
        $out = [];
        foreach ($rows as $r) {
            // "Active" means the active flag is set AND there is no real end date.
            $end = Row::str($r, 'end_date');
            $ended = $end !== '' && $end !== '0000-00-00';
            [$started, $provenance] = $this->datedWithProvenance(Row::str($r, 'start_date'), Row::str($r, 'date_added'));
            $out[] = new MedicationRecord(
                Row::int($r, 'id'),
                Row::str($r, 'drug'),
                $started,
                Row::int($r, 'active') === 1 && !$ended,
                $provenance,
            );
        }
        return $out;
    }

    public function allergies(PatientId $pid): array
    {
        // OpenEMR keeps allergies and problems in the same `lists` table,
        // distinguished by `type`. Only allergies with no end date are current.
        $rows = QueryUtils::fetchRecords(
            "SELECT id, title, begdate, date FROM lists
             WHERE pid = ? AND type = 'allergy' AND title <> '' AND (enddate IS NULL OR enddate = '0000-00-00')",
            [$pid->value]
        );
        $out = [];
        foreach ($rows as $r) {
            [$began, $provenance] = $this->datedWithProvenance(Row::str($r, 'begdate'), Row::str($r, 'date'));
            $out[] = new AllergyRecord(Row::int($r, 'id'), Row::str($r, 'title'), $began, $provenance);
        }
        return $out;
    }

    public function labs(PatientId $pid): array
    {
        // Three-table join: order -> report -> result. The REGEXP keeps only
        // results that are plain numbers (drops "positive", "<5", etc.).
        // COALESCE picks the first real date from result, report, collection,
        // then order, since different labs populate different ones.
        $rows = QueryUtils::fetchRecords(
            "SELECT pr.procedure_result_id, po.encounter_id, pr.result_code, pr.result_text, pr.result, pr.units,
                    COALESCE(NULLIF(pr.date, '0000-00-00 00:00:00'), NULLIF(prp.date_report, '0000-00-00 00:00:00'),
                             NULLIF(po.date_collected, '0000-00-00 00:00:00'), po.date_ordered) AS date,
                    cdf.document_id AS doc_id, cdf.field_path, cdf.page, cdf.bbox_json, cdf.row_bbox_json, cdf.unit_mismatch
             FROM procedure_result pr
             JOIN procedure_report prp ON prp.procedure_report_id = pr.procedure_report_id
             JOIN procedure_order po ON po.procedure_order_id = prp.procedure_order_id
             LEFT JOIN copilot_document_fact cdf ON cdf.procedure_result_id = pr.procedure_result_id
             WHERE po.patient_id = ? AND pr.result REGEXP '^-?[0-9]+(\\\\.[0-9]+)?$' AND pr.result_code <> ''",
            [$pid->value]
        );
        return array_map(
            fn(array $r) => new LabRecord(
                Row::int($r, 'procedure_result_id'),
                Row::int($r, 'encounter_id'),
                Row::str($r, 'result_code'),
                $this->shortLabName(Row::str($r, 'result_text')),
                Row::float($r, 'result'),
                Row::str($r, 'units'),
                $this->date(Row::str($r, 'date')),
                $this->documentCitation($r),
                (int) ($r['unit_mismatch'] ?? 0) === 1,
            ),
            $rows
        );
    }

    /**
     * A document citation for a lab row that came from an uploaded PDF
     * (copilot_document_fact joined on procedure_result_id), else null.
     *
     * @param array<string, mixed> $r
     */
    private function documentCitation(array $r): ?Citation
    {
        if (!is_numeric($r['doc_id'] ?? null) || !is_string($r['field_path'] ?? null)) {
            return null;
        }
        $bbox = is_string($r['bbox_json'] ?? null) ? json_decode($r['bbox_json'], true) : null;
        $row = is_string($r['row_bbox_json'] ?? null) ? json_decode($r['row_bbox_json'], true) : null;
        return new Citation(
            'document',
            (string) (int) $r['doc_id'],
            is_numeric($r['page'] ?? null) ? (string) (int) $r['page'] : '',
            $r['field_path'],
            Row::str($r, 'result'),
            is_array($bbox),
            is_array($bbox) ? BBox::fromArray($bbox) : null,
            is_array($row) ? BBox::fromArray($row) : null,
        );
    }

    /**
     * Week 2: fields the extractor could not anchor (and table rows it did not
     * extract at all) for this patient's documents, so they surface as
     * unverified rather than vanish. Ordered newest document first.
     *
     * @return list<UnverifiedExtraction>
     */
    public function unverifiedExtractions(PatientId $pid): array
    {
        $rows = QueryUtils::fetchRecords(
            "SELECT cdf.id, cdf.document_id, cdf.kind, cdf.analyte, cdf.value, cdf.unit, cdf.page, cdf.field_path, cdf.bbox_json, cdf.row_bbox_json, cd.created_at
             FROM copilot_document_fact cdf
             JOIN copilot_document cd ON cd.document_id = cdf.document_id
             JOIN documents d ON d.id = cd.document_id
             WHERE cd.pid = ? AND cdf.anchored = 0 AND cd.status = 'extracted' AND d.deleted = 0
             ORDER BY cd.created_at DESC, cdf.id ASC",
            [$pid->value]
        );
        $out = [];
        foreach ($rows as $r) {
            $row = is_string($r['row_bbox_json'] ?? null) ? json_decode($r['row_bbox_json'], true) : null;
            $bbox = is_string($r['bbox_json'] ?? null) ? json_decode($r['bbox_json'], true) : null;
            $out[] = new UnverifiedExtraction(
                Row::int($r, 'id'),
                Row::int($r, 'document_id'),
                Row::str($r, 'kind'),
                is_string($r['analyte'] ?? null) ? $r['analyte'] : null,
                Row::str($r, 'value'),
                is_string($r['unit'] ?? null) ? $r['unit'] : null,
                $this->date(Row::str($r, 'created_at')),
                new Citation('document', (string) Row::int($r, 'document_id'), is_numeric($r['page'] ?? null) ? (string) (int) $r['page'] : '', Row::str($r, 'field_path'), Row::str($r, 'value'), false, is_array($bbox) ? BBox::fromArray($bbox) : null, is_array($row) ? BBox::fromArray($row) : null),
            );
        }
        return $out;
    }

    public function problems(PatientId $pid): array
    {
        $rows = QueryUtils::fetchRecords(
            "SELECT id, title, begdate, date FROM lists
             WHERE pid = ? AND type = 'medical_problem' AND title <> '' AND activity = 1
               AND (enddate IS NULL OR enddate = '0000-00-00')",
            [$pid->value]
        );
        return array_map(
            fn(array $r) => new ProblemRecord(Row::int($r, 'id'), Row::str($r, 'title'), $this->date(Row::str($r, 'begdate') ?: Row::str($r, 'date'))),
            $rows
        );
    }

    /**
     * The clinician-recorded date when present, else the date the row was
     * first entered, each labelled with where it came from.
     *
     * @return array{DateTimeImmutable, DateProvenance}
     */
    private function datedWithProvenance(string $recorded, string $entered): array
    {
        if ($this->hasDate($recorded)) {
            return [$this->date($recorded), DateProvenance::Recorded];
        }
        if ($this->hasDate($entered)) {
            return [$this->date($entered), DateProvenance::FirstNoted];
        }
        return [$this->date(''), DateProvenance::Unknown];
    }

    /** True for a real date; false for empty or the '0000-00-00' placeholder. */
    private function hasDate(string $s): bool
    {
        return $s !== '' && !str_starts_with($s, '0000-00-00');
    }

    /** Parses a DB date string; missing/placeholder dates become the Unix epoch (a sentinel that sorts before everything). */
    private function date(string $s): DateTimeImmutable
    {
        if ($s === '' || str_starts_with($s, '0000-00-00')) {
            return new DateTimeImmutable('1970-01-01');
        }
        return new DateTimeImmutable($s);
    }

    // LOINC long names like "Hemoglobin A1c/Hemoglobin.total in Blood" read
    // badly in a briefing; keep the part before the first " [" or "/".
    private function shortLabName(string $text): string
    {
        $cut = strcspn($text, '[/');
        return trim(substr($text, 0, $cut)) ?: $text;
    }
}
