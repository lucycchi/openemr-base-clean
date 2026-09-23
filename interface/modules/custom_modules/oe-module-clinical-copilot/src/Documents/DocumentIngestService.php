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

/**
 * The last step of document ingestion: what the sidecar read becomes rows
 * in OpenEMR, so the rest of the chart (and the Week 1 fact assembler) sees
 * an uploaded lab report exactly as it would see results from a lab
 * interface.
 *
 *   ExtractionResult -> failed?  -> mark copilot_document failed, stop
 *                    -> done before? -> stop (no second set of rows)
 *                    -> BEGIN: lab tables or intake rows + provenance rows
 *                              + copilot_document status -> COMMIT
 *                              (any error -> ROLLBACK, nothing half-written)
 *
 * OpenEMR models a lab result as four linked tables, and all four are
 * needed for the chart, reports and FHIR to show it:
 *
 *   procedure_order       one "order": who, when collected, for which visit
 *   procedure_order_code  the test or panel on that order (here: one
 *                         Co-Pilot panel code standing in for the report)
 *   procedure_report      the lab's reply to that order, with its dates
 *   procedure_result      one row per analyte: value, unit, range, flag
 *
 * Only results whose citation is anchored (deterministic code found the
 * value on the page) get a procedure_result row. Every field, anchored or
 * not, gets a copilot_document_fact row with its page location, so an
 * unverified value is still shown to the clinician, just never as a lab
 * result. Intake forms go to copilot_intake instead; they are the patient's
 * own words, not clinical results.
 */
final class DocumentIngestService
{
    /** The procedure code on every order this service creates, so its orders are recognisable in reports. */
    public const PANEL_CODE = 'COPILOT-PANEL';

    /**
     * Writes one document's outcome. Returns the status and three counts
     * the caller logs: results written to the chart, fields left unverified,
     * table rows the model missed.
     *
     * "Idempotent" here means calling this twice for the same document has
     * the same effect as calling it once: the second call finds the first
     * call's rows and stops. A retry after a network blip or a double click
     * therefore never doubles a patient's lab results.
     *
     * @return array{status: DocumentStatus, results_persisted: int, unverified: int, unextracted: int}
     */
    public function persist(PatientId $pid, ExtractionResult $result, string $correlationId): array
    {
        $documentId = $result->documentId;
        // 1. The sidecar could not read the document: record why on the copilot_document row
        //    and write nothing else. The file stays stored so the clinician can retry.
        if ($result->status === DocumentStatus::Failed || $result->extraction === null) {
            QueryUtils::sqlStatementThrowException(
                "UPDATE copilot_document SET status = 'failed', failure_reason = ?, confidence = NULL, correlation_id = ?, extracted_at = NOW() WHERE document_id = ? AND pid = ?",
                [$result->failureReason ?? 'model_error', $correlationId, $documentId, $pid->value]
            );
            return ['status' => DocumentStatus::Failed, 'results_persisted' => 0, 'unverified' => 0, 'unextracted' => 0];
        }
        // 2. The idempotency check, backed by the UNIQUE (document_id, field_path) keys on the
        //    two provenance tables: even a race between two requests cannot double the rows.
        if ($this->alreadyPersisted($documentId)) {
            // A repeat run (retry after a network blip, double click) must not create a second set of rows.
            return ['status' => DocumentStatus::Extracted, 'results_persisted' => 0, 'unverified' => 0, 'unextracted' => 0];
        }

        $extraction = $result->extraction;
        // 3. One transaction per document: the rows and the status flip land together or not at all.
        //    inTransaction runs the closure between BEGIN and COMMIT; if anything inside throws,
        //    it rolls back and rethrows, so a failure mid-way leaves the chart exactly as it was.
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

    /**
     * True when a previous run already wrote provenance rows for this
     * document. Both tables are checked because a lab report writes to one
     * and an intake form to the other.
     */
    private function alreadyPersisted(int $documentId): bool
    {
        $n = QueryUtils::fetchSingleValue("SELECT COUNT(*) AS n FROM copilot_document_fact WHERE document_id = ?", 'n', [$documentId]);
        $m = QueryUtils::fetchSingleValue("SELECT COUNT(*) AS n FROM copilot_intake WHERE document_id = ?", 'n', [$documentId]);
        return self::count($n) + self::count($m) > 0;
    }

    /**
     * A lab report into the four lab tables plus provenance. Runs inside
     * the transaction opened by persist().
     *
     * @return array{results_persisted: int, unverified: int, unextracted: int}
     */
    private function persistLab(PatientId $pid, int $documentId, LabReportExtraction $lab): array
    {
        // Dates are stored at midnight: the report prints a day, not a time.
        // A missing report date falls back to the collection date rather than "today".
        $collected = $lab->collectionDate->format('Y-m-d 00:00:00');
        $reported = ($lab->reportedDate ?? $lab->collectionDate)->format('Y-m-d 00:00:00');
        $encounterId = $this->encounterFor($pid, $lab->collectionDate);

        // The order/report scaffolding is only created when at least one result will hang
        // off it; a report whose every value is unverified leaves no empty order behind.
        $anchored = array_values(array_filter($lab->results, static fn(LabResultExtraction $r): bool => $r->citation->anchored));
        $orderId = 0;
        $reportId = 0;
        if ($anchored !== []) {
            // procedure_order: provider_id 0 (no ordering clinician; this was uploaded),
            // status "complete" (results are already in hand), clinical_hx names the
            // source file so a reader of the chart can trace the order back to it.
            $orderId = (int) QueryUtils::sqlInsert(
                "INSERT INTO procedure_order (uuid, provider_id, patient_id, encounter_id, date_collected, date_ordered, order_status, activity, procedure_order_type, clinical_hx)
                 VALUES (?, 0, ?, ?, ?, ?, 'complete', 1, 'laboratory_test', ?)",
                [$this->uuid('procedure_order'), $pid->value, $encounterId, $collected, $collected, 'Uploaded lab report; documents.id ' . $documentId]
            );
            // procedure_order_code: the one line item on the order. The panel code is fixed;
            // the name is the lab's name as printed, so the chart shows where it was run.
            QueryUtils::sqlStatementThrowException(
                "INSERT INTO procedure_order_code (procedure_order_id, procedure_order_seq, procedure_code, procedure_name, procedure_source, procedure_type) VALUES (?, 1, ?, ?, '1', 'laboratory_test')",
                [$orderId, self::PANEL_CODE, mb_substr($lab->labName ?? 'Uploaded lab report', 0, 255)]
            );
            // procedure_report: "final" because the paper report is the lab's final word;
            // review_status "received" because no clinician has signed it off yet.
            $reportId = (int) QueryUtils::sqlInsert(
                "INSERT INTO procedure_report (uuid, procedure_order_id, procedure_order_seq, date_collected, date_report, source, report_status, review_status)
                 VALUES (?, ?, 1, ?, ?, 0, 'final', 'received')",
                [$this->uuid('procedure_report'), $orderId, $collected, $reported]
            );
        }

        // One pass over every result. Anchored and not already in the chart -> a real
        // procedure_result row. Unanchored -> counted as unverified. Either way -> a
        // provenance row, linked to the result row when there is one.
        $persisted = 0;
        $unverified = 0;
        foreach ($lab->results as $i => $r) {
            $resultId = null;
            if ($r->citation->anchored && !$this->duplicateResult($pid, $r, $lab->collectionDate)) {
                $numeric = $r->numericValue();
                // result_data_type tells OpenEMR whether the value is a number (N) or text (S, for
                // "<5" or "positive"). result_code is the LOINC the sidecar mapped, or blank.
                // Text columns are cut to their column width rather than failing the insert.
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
                        // A unit mismatch means the printed unit is not the LOINC's canonical unit, so
                        // the printed H/L flag was judged on a different scale: store the value and unit
                        // as printed, but no abnormal flag, rather than a flag that may be wrong.
                        $r->unitMismatch ? '' : $this->abnormal($r->abnormalFlag),
                        $documentId,
                    ]
                );
                $persisted++;
            } elseif (!$r->citation->anchored) {
                $unverified++;
            }
            // The provenance row carries the unit_mismatch flag itself, so the reason a result
            // shows no abnormal flag is on record.
            $this->fact($documentId, "/results/$i/value", 'lab_result', $r->analyte, $r->value, $r->unit, $r->loinc, $r->referenceRange, $r->abnormalFlag, $r->unitMismatch, $r->citation, $resultId);
        }
        // The dates are cited fields too, so an unanchored date surfaces as unverified.
        $this->fact($documentId, '/collection_date', 'collection_date', null, $lab->collectionDate->format('Y-m-d'), null, null, null, null, false, $lab->collectionDateCitation, null);
        if ($lab->reportedDate !== null && $lab->reportedDateCitation !== null) {
            $this->fact($documentId, '/reported_date', 'reported_date', null, $lab->reportedDate->format('Y-m-d'), null, null, null, null, false, $lab->reportedDateCitation, null);
        }
        // Wrong patient's report? The name is compared and thrown away; only a fixed sentence
        // is stored, so no name from the paper ever lands in a table.
        if ($lab->patientNameOnReport !== null && $this->demographicsMismatches($pid, ['name' => $lab->patientNameOnReport]) !== []) {
            QueryUtils::sqlInsert(
                "INSERT INTO copilot_document_fact (document_id, field_path, kind, value, anchored, page) VALUES (?, '/patient_name_on_report', 'patient_mismatch', 'patient name on the report does not match the chart', 1, 1)",
                [$documentId]
            );
        }
        // Rows the model skipped: stored with their page and row box so the panel can point at them.
        foreach ($lab->unextracted as $i => $u) {
            QueryUtils::sqlInsert(
                "INSERT INTO copilot_document_fact (document_id, field_path, kind, value, anchored, page, row_bbox_json) VALUES (?, ?, 'unextracted_row', ?, 0, ?, ?)",
                [$documentId, "/unextracted/$i", mb_substr($u->text, 0, 255), $u->page, json_encode($u->rowBbox->toArray(), JSON_THROW_ON_ERROR)]
            );
        }
        return ['results_persisted' => $persisted, 'unverified' => $unverified, 'unextracted' => count($lab->unextracted)];
    }

    /**
     * An intake form into copilot_intake: one row per item the patient
     * wrote, plus one row per demographic that disagrees with the chart.
     * Nothing goes to the lab tables; a patient's own list of medications is
     * information for the clinician, not a clinical record.
     *
     * @return array{results_persisted: int, unverified: int, unextracted: int}
     */
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
        // Each item keeps its citation (page and boxes) as JSON so the panel can highlight
        // the handwritten line. Unanchored items are stored too, counted as unverified.
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
        // Lower-case and collapse punctuation to spaces, so "O'Brien, MARY" and "mary obrien" compare equal.
        $norm = static fn(string $s): string => preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower($s)) ?? '';
        if (isset($onForm['name'])) {
            // Wrap in spaces so each chart token is matched as a whole word, not a fragment.
            $form = ' ' . trim($norm($onForm['name'])) . ' ';
            foreach ([Row::str($chart, 'fname'), Row::str($chart, 'lname')] as $token) {
                $t = trim($norm($token));
                if ($t !== '' && !str_contains($form, " $t ")) {
                    $out[] = 'name on the form does not match the chart';
                    break;
                }
            }
        }
        // A chart DOB of 0000-00-00 is OpenEMR's "unknown": nothing to compare against.
        if (isset($onForm['dob']) && is_string($chart['DOB'] ?? null) && $chart['DOB'] !== '0000-00-00') {
            // Patients write dates in several formats; the first that parses wins. US month-first
            // is tried before day-first. A date that parses in none is not a mismatch, just unread.
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
        // First letter only: "F", "Female" and "female" all agree.
        if (isset($onForm['sex']) && is_string($chart['sex'] ?? null) && $chart['sex'] !== '') {
            if (strtolower(substr(trim($onForm['sex']), 0, 1)) !== strtolower(substr($chart['sex'], 0, 1))) {
                $out[] = 'sex on the form does not match the chart';
            }
        }
        // Digits only, and either chart number counts: "(555) 010-2000" matches "555-010-2000".
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

    /**
     * One provenance row in copilot_document_fact: the field's JSON pointer,
     * its value as read, where on the page it was found (or that it was
     * not), and the procedure_result row it became, when it became one. Text
     * is cut to column width; the boxes are stored as JSON.
     */
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

    /**
     * The encounter dated on the collection date, else the latest, else 0 (OpenEMR accepts 0 on an order).
     *
     * An order in OpenEMR belongs to a visit. The visit on the day the
     * specimen was drawn is the natural home; failing that, the most recent
     * one; a patient with no visits at all still gets the order, unattached.
     */
    private function encounterFor(PatientId $pid, \DateTimeImmutable $collected): int
    {
        $same = QueryUtils::fetchSingleValue("SELECT encounter FROM form_encounter WHERE pid = ? AND DATE(date) = ? ORDER BY encounter DESC LIMIT 1", 'encounter', [$pid->value, $collected->format('Y-m-d')]);
        if (is_numeric($same)) {
            return (int) $same;
        }
        $latest = QueryUtils::fetchSingleValue("SELECT encounter FROM form_encounter WHERE pid = ? ORDER BY date DESC, encounter DESC LIMIT 1", 'encounter', [$pid->value]);
        return is_numeric($latest) ? (int) $latest : 0;
    }

    /**
     * An existing result with the same LOINC, date and value is the same result
     * (e.g. the lab interface already imported it).
     *
     * This guards against a different kind of double from the idempotency
     * check above: the same result arriving by two routes (electronic feed
     * and a scanned copy). Without a LOINC there is no reliable identity,
     * so the result is written and the clinician sees both.
     */
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

    /**
     * The printed flag in OpenEMR's vocabulary for procedure_result.abnormal.
     * The lab's HH/LL (critical) collapse to high/low; an unknown or missing
     * flag becomes an empty string, meaning no flag.
     */
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

    /**
     * A fresh UUID registered with OpenEMR for the given table. The lab
     * tables need one on every row so the FHIR API can address the record.
     */
    private function uuid(string $table): string
    {
        $registry = new UuidRegistry(['table_name' => $table, 'table_id' => $table . '_id']);
        return $registry->createUuid();
    }
}
