<?php

/**
 * One extracted lab result (contracts/lab-report.schema.json results[]).
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
 * One row of a lab report as the sidecar read it. Values are kept as the
 * strings printed on the page: "<5" and "1.2" both survive, and nothing is
 * rounded or converted before the clinician sees it.
 *
 * JSON field -> property: analyte -> analyte (test name as printed),
 * loinc -> loinc (the code the sidecar looked up in contracts/loinc_map.json,
 * null when the analyte is not mapped), value -> value, unit -> unit,
 * reference_range -> referenceRange, abnormal_flag -> abnormalFlag (H, L,
 * HH, LL, A, N as printed), unit_mismatch -> unitMismatch (the printed
 * unit is not the canonical unit for that LOINC), citation -> citation.
 */
final readonly class LabResultExtraction
{
    public function __construct(
        public string $analyte,
        public ?string $loinc,
        public string $value,
        public ?string $unit,
        public ?string $referenceRange,
        public ?string $abnormalFlag,
        public bool $unitMismatch,
        public Citation $citation,
    ) {
    }

    /**
     * Builds a result from decoded JSON. Only the analyte, the value and the
     * citation are required; the rest may be absent on a sparse report.
     *
     * @param array<mixed> $a  decoded JSON; every value is narrowed here
     */
    public static function fromArray(array $a): self
    {
        // Optional string fields: present and a string, or null. Never an empty string.
        $opt = static fn(string $k): ?string => is_string($a[$k] ?? null) ? $a[$k] : null;
        if (!is_string($a['analyte'] ?? null) || !is_string($a['value'] ?? null) || !is_array($a['citation'] ?? null)) {
            throw new SidecarException('schema_mismatch');
        }
        return new self($a['analyte'], $opt('loinc'), $a['value'], $opt('unit'), $opt('reference_range'), $opt('abnormal_flag'), ($a['unit_mismatch'] ?? false) === true, Citation::fromArray($a['citation']));
    }

    /**
     * The value as a number when it is a plain decimal ("105.6", "-2"), or
     * null for anything else ("<5", "positive"). DocumentIngestService uses
     * this to mark the stored result numeric or textual.
     */
    public function numericValue(): ?float
    {
        return preg_match('/^-?\d+(?:\.\d+)?$/', $this->value) === 1 ? (float) $this->value : null;
    }
}
