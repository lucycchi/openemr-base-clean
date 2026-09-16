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
        $rows = QueryUtils::fetchRecords(
            "SELECT id, drug, NULLIF(start_date, '0000-00-00') AS start_date, date_added, active, end_date
             FROM prescriptions WHERE patient_id = ? AND drug <> ''",
            [$pid->value]
        );
        $out = [];
        foreach ($rows as $r) {
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
        $rows = QueryUtils::fetchRecords(
            "SELECT pr.procedure_result_id, po.encounter_id, pr.result_code, pr.result_text, pr.result, pr.units,
                    COALESCE(NULLIF(pr.date, '0000-00-00 00:00:00'), NULLIF(prp.date_report, '0000-00-00 00:00:00'),
                             NULLIF(po.date_collected, '0000-00-00 00:00:00'), po.date_ordered) AS date
             FROM procedure_result pr
             JOIN procedure_report prp ON prp.procedure_report_id = pr.procedure_report_id
             JOIN procedure_order po ON po.procedure_order_id = prp.procedure_order_id
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
            ),
            $rows
        );
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

    private function hasDate(string $s): bool
    {
        return $s !== '' && !str_starts_with($s, '0000-00-00');
    }

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
