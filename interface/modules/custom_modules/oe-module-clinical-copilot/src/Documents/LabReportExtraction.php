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

final readonly class LabReportExtraction
{
    /**
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

    /** @param array<string, mixed> $a */
    public static function fromArray(array $a): self
    {
        if (($a['doc_type'] ?? null) !== 'lab_pdf' || !is_string($a['collection_date'] ?? null) || !is_array($a['collection_date_citation'] ?? null) || !is_array($a['results'] ?? null)) {
            throw new SidecarException('schema_mismatch');
        }
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

    /** @return list<Citation> */
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
