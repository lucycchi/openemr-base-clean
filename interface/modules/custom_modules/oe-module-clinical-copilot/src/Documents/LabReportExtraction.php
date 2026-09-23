<?php

/**
 * A lab report as extracted and anchored by the sidecar (contracts/lab-report.schema.json).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Documents;

/**
 * The sidecar's reading of a lab report PDF: the header facts (who the
 * report names, when the specimen was collected, when it was reported,
 * which lab ran it) and one LabResultExtraction per result row, plus the
 * rows the model missed. DocumentIngestService turns this into OpenEMR's
 * lab tables.
 *
 * JSON field -> property: patient_name_on_report -> patientNameOnReport
 * (compared with the chart, never stored), collection_date ->
 * collectionDate with collection_date_citation -> collectionDateCitation,
 * reported_date / reported_date_citation -> reportedDate /
 * reportedDateCitation (optional), lab_name -> labName (metadata, no
 * citation), results -> results, unextracted -> unextracted.
 */
final readonly class LabReportExtraction
{
    /**
     * A report with no results at all is refused: the sidecar should have
     * reported a failure instead, and an empty report would create an empty
     * lab order in the chart.
     *
     * @param list<LabResultExtraction> $results
     * @param list<UnextractedRow> $unextracted
     */
    public function __construct(
        public ?string $patientNameOnReport,
        public \DateTimeImmutable $collectionDate,
        public Citation $collectionDateCitation,
        public ?\DateTimeImmutable $reportedDate,
        public ?Citation $reportedDateCitation,
        public ?string $labName,
        public array $results,
        public array $unextracted,
    ) {
        if ($results === []) {
            throw new SidecarException('schema_mismatch');
        }
    }

    /**
     * Builds the report from decoded JSON. The collection date is the one
     * field that must be present and cited: every lab row written to the
     * chart is dated by it. Any date that does not parse is refused rather
     * than guessed, so a misread "2O26" can never become a real date.
     *
     * @param array<mixed> $a  decoded JSON; every value is narrowed here
     */
    public static function fromArray(array $a): self
    {
        if (($a['doc_type'] ?? null) !== 'lab_pdf' || !is_string($a['collection_date'] ?? null) || !is_array($a['collection_date_citation'] ?? null) || !is_array($a['results'] ?? null)) {
            throw new SidecarException('schema_mismatch');
        }
        // Parses an ISO date (YYYY-MM-DD). The leading "!" resets the time part to midnight
        // instead of "now", so two runs on different days produce the same value.
        $date = static function (?string $s): ?\DateTimeImmutable {
            if ($s === null) {
                return null;
            }
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $s);
            return $d === false ? throw new SidecarException('schema_mismatch') : $d;
        };
        $collection = $date($a['collection_date']);
        if ($collection === null) {
            throw new SidecarException('schema_mismatch');
        }
        return new self(
            is_string($a['patient_name_on_report'] ?? null) ? $a['patient_name_on_report'] : null,
            $collection,
            Citation::fromArray($a['collection_date_citation']),
            $date(is_string($a['reported_date'] ?? null) ? $a['reported_date'] : null),
            is_array($a['reported_date_citation'] ?? null) ? Citation::fromArray($a['reported_date_citation']) : null,
            is_string($a['lab_name'] ?? null) ? $a['lab_name'] : null,
            array_values(array_map(static fn(mixed $r): LabResultExtraction => is_array($r) ? LabResultExtraction::fromArray($r) : throw new SidecarException('schema_mismatch'), $a['results'])),
            array_values(array_map(static fn(mixed $r): UnextractedRow => is_array($r) ? UnextractedRow::fromArray($r) : throw new SidecarException('schema_mismatch'), is_array($a['unextracted'] ?? null) ? $a['unextracted'] : [])),
        );
    }

    /**
     * Every citation in the report: the dates first, then one per result.
     *
     * @return list<Citation>
     */
    public function citations(): array
    {
        $out = [$this->collectionDateCitation];
        if ($this->reportedDateCitation !== null) {
            $out[] = $this->reportedDateCitation;
        }
        foreach ($this->results as $r) {
            $out[] = $r->citation;
        }
        return $out;
    }
}
