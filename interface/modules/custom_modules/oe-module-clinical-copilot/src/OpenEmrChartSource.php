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
        // Every prescription row, active or not: a stopped medication is a fact
        // too. The interval code is read as its list label ("daily"), the
        // dose as recorded; both are shown on a changed-medication fact.
        $rows = QueryUtils::fetchRecords(
            "SELECT p.id, p.drug, NULLIF(p.start_date, '0000-00-00') AS start_date, p.date_added, p.active, p.end_date, p.dosage, p.date_modified,
                    COALESCE(lo.title, '') AS interval_label
             FROM prescriptions p
             LEFT JOIN list_options lo ON lo.list_id = 'drug_interval' AND lo.option_id = CAST(p.`interval` AS CHAR) AND p.`interval` <> 0
             WHERE p.patient_id = ? AND p.drug <> ''",
            [$pid->value]
        );
        $out = [];
        foreach ($rows as $r) {
            $end = Row::str($r, 'end_date');
            $ended = $this->hasDate($end);
            [$started, $provenance] = $this->datedWithProvenance(Row::str($r, 'start_date'), Row::str($r, 'date_added'));
            $modified = Row::str($r, 'date_modified');
            $out[] = new MedicationRecord(
                Row::int($r, 'id'),
                Row::str($r, 'drug'),
                $started,
                Row::int($r, 'active') === 1 && !$ended,
                $provenance,
                $ended ? $this->date($end) : null,
                trim(Row::str($r, 'dosage')),
                trim(Row::str($r, 'interval_label')),
                $this->hasDate($modified) ? $this->date($modified) : null,
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
                    pr.`range` AS printed_range, pr.abnormal, cdf.document_id AS doc_id, cdf.field_path, cdf.page, cdf.bbox_json, cdf.row_bbox_json, cdf.unit_mismatch
             FROM procedure_result pr
             JOIN procedure_report prp ON prp.procedure_report_id = pr.procedure_report_id
             JOIN procedure_order po ON po.procedure_order_id = prp.procedure_order_id
             LEFT JOIN copilot_document_fact cdf ON cdf.procedure_result_id = pr.procedure_result_id
             WHERE po.patient_id = ? AND TRIM(pr.result) <> ''",
            [$pid->value]
        );
        return array_map(
            function (array $r): LabRecord {
                $raw = trim(Row::str($r, 'result'));
                $numeric = preg_match('/^-?\d+(?:\.\d+)?$/', $raw) === 1;
                $printed = trim(Row::str($r, 'printed_range'));
                return new LabRecord(
                    Row::int($r, 'procedure_result_id'),
                    Row::int($r, 'encounter_id'),
                    Row::str($r, 'result_code'),
                    $this->shortLabName(Row::str($r, 'result_text')),
                    $numeric ? (float) $raw : null,
                    Row::str($r, 'units'),
                    $this->date(Row::str($r, 'date')),
                    $this->documentCitation($r),
                    Row::int($r, 'unit_mismatch') === 1,
                    $printed === '' ? null : $printed,
                    Row::str($r, 'abnormal'),
                    $numeric ? null : $raw,
                );
            },
            $rows
        );
    }

    public function demographics(PatientId $pid): Demographics
    {
        $row = QueryUtils::querySingleRow("SELECT sex, DOB FROM patient_data WHERE pid = ?", [$pid->value]);
        if (!is_array($row)) {
            return Demographics::unknown();
        }
        $dob = Row::str($row, 'DOB');
        return new Demographics(
            Demographics::normaliseSex(Row::str($row, 'sex')),
            $this->hasDate($dob) ? $this->date($dob) : null,
        );
    }

    /**
     * A document citation for a lab row that came from an uploaded PDF
     * (copilot_document_fact joined on procedure_result_id), else null.
     *
     * @param array<mixed> $r  a lab row as the query layer returns it
     */
    private function documentCitation(array $r): ?Citation
    {
        $docId = $r['doc_id'] ?? null;
        $fieldPath = $r['field_path'] ?? null;
        if (!is_numeric($docId) || !is_string($fieldPath)) {
            return null;
        }
        $bbox = is_string($r['bbox_json'] ?? null) ? json_decode($r['bbox_json'], true) : null;
        $row = is_string($r['row_bbox_json'] ?? null) ? json_decode($r['row_bbox_json'], true) : null;
        return new Citation(
            'document',
            (string) (int) $docId,
            self::pageLabel($r),
            $fieldPath,
            Row::str($r, 'result'),
            is_array($bbox),
            is_array($bbox) ? BBox::fromArray($bbox) : null,
            is_array($row) ? BBox::fromArray($row) : null,
        );
    }

    /**
     * The page a document citation points at, as the citation contract's
     * page_or_section string; '' when the row has no page.
     *
     * @param array<mixed> $r
     */
    private static function pageLabel(array $r): string
    {
        $page = $r['page'] ?? null;
        return is_numeric($page) ? (string) (int) $page : '';
    }

    /** @return list<IntakeRecord> */
    public function intakeRecords(PatientId $pid): array
    {
        $rows = QueryUtils::fetchRecords(
            "SELECT ci.id, ci.document_id, ci.kind, ci.value, ci.detail, ci.anchored, ci.page, ci.field_path, ci.bbox_json, ci.row_bbox_json, cd.created_at
             FROM copilot_intake ci
             JOIN copilot_document cd ON cd.document_id = ci.document_id
             JOIN documents d ON d.id = cd.document_id
             WHERE ci.pid = ? AND cd.status = 'extracted' AND d.deleted = 0
             UNION ALL
             SELECT cdf.id, cdf.document_id, cdf.kind, cdf.value, NULL AS detail, cdf.anchored, cdf.page, cdf.field_path, cdf.bbox_json, cdf.row_bbox_json, cd.created_at
             FROM copilot_document_fact cdf
             JOIN copilot_document cd ON cd.document_id = cdf.document_id
             JOIN documents d ON d.id = cd.document_id
             WHERE cd.pid = ? AND cdf.kind = 'patient_mismatch' AND cd.status = 'extracted' AND d.deleted = 0
             ORDER BY created_at DESC, id ASC",
            [$pid->value, $pid->value]
        );
        $out = [];
        foreach ($rows as $r) {
            $bbox = is_string($r['bbox_json'] ?? null) ? json_decode($r['bbox_json'], true) : null;
            $row = is_string($r['row_bbox_json'] ?? null) ? json_decode($r['row_bbox_json'], true) : null;
            $out[] = new IntakeRecord(
                Row::int($r, 'id'),
                Row::int($r, 'document_id'),
                Row::str($r, 'kind'),
                Row::str($r, 'value'),
                is_string($r['detail'] ?? null) ? $r['detail'] : null,
                $this->date(Row::str($r, 'created_at')),
                new Citation('document', (string) Row::int($r, 'document_id'), self::pageLabel($r), Row::str($r, 'field_path'), Row::str($r, 'value'), Row::int($r, 'anchored') === 1 && is_array($bbox), is_array($bbox) ? BBox::fromArray($bbox) : null, is_array($row) ? BBox::fromArray($row) : null),
            );
        }
        return $out;
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
                new Citation('document', (string) Row::int($r, 'document_id'), self::pageLabel($r), Row::str($r, 'field_path'), Row::str($r, 'value'), false, is_array($bbox) ? BBox::fromArray($bbox) : null, is_array($row) ? BBox::fromArray($row) : null),
            );
        }
        return $out;
    }

    public function problems(PatientId $pid): array
    {
        // Active and resolved problems alike: the assembler reports a problem
        // resolved since the prior visit and keeps only active titles for the
        // guideline rules.
        $rows = QueryUtils::fetchRecords(
            "SELECT id, title, begdate, date, enddate, activity, modifydate FROM lists
             WHERE pid = ? AND type = 'medical_problem' AND title <> ''",
            [$pid->value]
        );
        return array_map(
            function (array $r): ProblemRecord {
                $end = Row::str($r, 'enddate');
                $modified = Row::str($r, 'modifydate');
                return new ProblemRecord(
                    Row::int($r, 'id'),
                    Row::str($r, 'title'),
                    $this->date(Row::str($r, 'begdate') ?: Row::str($r, 'date')),
                    $this->hasDate($end) ? $this->date($end) : null,
                    Row::int($r, 'activity') === 1,
                    $this->hasDate($modified) ? $this->date($modified) : null,
                );
            },
            $rows
        );
    }

    public function notes(PatientId $pid, int $encounterId): array
    {
        // The SOAP form's assessment and plan, and clinical notes, filed under
        // this encounter through the forms table (so a deleted form is not a
        // note). Progress and plan-type clinical notes count as plan; the rest
        // as assessment.
        $out = [];
        $soap = QueryUtils::fetchRecords(
            "SELECT s.id, s.date, s.assessment, s.plan FROM form_soap s
             JOIN forms f ON f.form_id = s.id AND f.formdir = 'soap' AND f.deleted = 0
             WHERE s.pid = ? AND f.encounter = ? AND s.activity = 1",
            [$pid->value, $encounterId]
        );
        foreach ($soap as $r) {
            $date = $this->date(Row::str($r, 'date'));
            foreach ([['assessment', NoteKind::Assessment], ['plan', NoteKind::Plan]] as [$column, $kind]) {
                $text = trim(Row::str($r, $column));
                if ($text !== '') {
                    $out[] = new NoteRecord(Row::int($r, 'id'), $encounterId, $date, $kind, $text);
                }
            }
        }
        $notes = QueryUtils::fetchRecords(
            "SELECT n.form_id, n.date, n.description, n.clinical_notes_type FROM form_clinical_notes n
             JOIN forms f ON f.form_id = n.form_id AND f.formdir = 'clinical_notes' AND f.deleted = 0
             WHERE n.pid = ? AND f.encounter = ? AND n.activity = 1",
            [$pid->value, $encounterId]
        );
        foreach ($notes as $r) {
            $text = trim(Row::str($r, 'description'));
            if ($text === '') {
                continue;
            }
            $type = strtolower(Row::str($r, 'clinical_notes_type'));
            $kind = (str_contains($type, 'plan') || str_contains($type, 'progress')) ? NoteKind::Plan : NoteKind::Assessment;
            $out[] = new NoteRecord(Row::int($r, 'form_id'), $encounterId, $this->date(Row::str($r, 'date')), $kind, $text);
        }
        return $out;
    }

    public function vitals(PatientId $pid): array
    {
        // Through the forms table so a deleted form is not a reading and the
        // encounter id is known (for the sensitivity filter). Pressures are
        // strings in OpenEMR; anything that is not a whole number becomes null.
        $rows = QueryUtils::fetchRecords(
            "SELECT fv.id, fv.date, f.encounter, fv.bps, fv.bpd, fv.pulse, fv.oxygen_saturation, fv.temperature, fv.respiration, fv.weight, fv.BMI
             FROM form_vitals fv
             JOIN forms f ON f.form_id = fv.id AND f.formdir = 'vitals' AND f.deleted = 0
             WHERE fv.pid = ? AND fv.activity = 1",
            [$pid->value]
        );
        $int = static function (mixed $v): ?int {
            $t = is_scalar($v) ? trim((string) $v) : '';
            return preg_match('/^\d{2,3}$/', $t) === 1 ? (int) $t : null;
        };
        $num = static function (mixed $v): ?float {
            $t = is_scalar($v) ? trim((string) $v) : '';
            return is_numeric($t) && (float) $t > 0 ? (float) $t : null;
        };
        return array_map(
            fn(array $r) => new VitalRecord(
                Row::int($r, 'id'),
                Row::int($r, 'encounter'),
                $this->date(Row::str($r, 'date')),
                $int($r['bps'] ?? null),
                $int($r['bpd'] ?? null),
                $num($r['pulse'] ?? null),
                $num($r['oxygen_saturation'] ?? null),
                $num($r['temperature'] ?? null),
                $num($r['respiration'] ?? null),
                $num($r['weight'] ?? null),
                $num($r['BMI'] ?? null),
            ),
            $rows
        );
    }

    public function pendingOrders(PatientId $pid): array
    {
        // Orders with no report at all, still open, and not the module's own
        // upload orders (those always carry a report). The demo database has
        // thousands of reportless seed orders, so the assembler's date boundary
        // does the rest.
        $rows = QueryUtils::fetchRecords(
            "SELECT po.procedure_order_id, po.date_ordered, po.order_status, po.encounter_id, COALESCE(poc.procedure_name, '') AS procedure_name
             FROM procedure_order po
             LEFT JOIN procedure_order_code poc ON poc.procedure_order_id = po.procedure_order_id AND poc.procedure_order_seq = 1
             LEFT JOIN procedure_report prp ON prp.procedure_order_id = po.procedure_order_id
             WHERE po.patient_id = ? AND prp.procedure_report_id IS NULL
               AND po.order_status NOT IN ('complete', 'canceled', 'cancelled')
               AND COALESCE(poc.procedure_code, '') <> ?",
            [$pid->value, \OpenEMR\Modules\ClinicalCopilot\Documents\DocumentIngestService::PANEL_CODE]
        );
        return array_map(
            fn(array $r) => new PendingOrderRecord(
                Row::int($r, 'procedure_order_id'),
                trim(Row::str($r, 'procedure_name')) !== '' ? trim(Row::str($r, 'procedure_name')) : 'Lab order',
                $this->date(Row::str($r, 'date_ordered')),
                Row::str($r, 'order_status'),
                Row::int($r, 'encounter_id'),
            ),
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
