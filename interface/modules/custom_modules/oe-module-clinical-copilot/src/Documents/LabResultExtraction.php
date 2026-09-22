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

    /** @param array<mixed> $a  decoded JSON; every value is narrowed here */
    public static function fromArray(array $a): self
    {
        $opt = static fn(string $k): ?string => is_string($a[$k] ?? null) ? $a[$k] : null;
        if (!is_string($a['analyte'] ?? null) || !is_string($a['value'] ?? null) || !is_array($a['citation'] ?? null)) {
            throw new SidecarException('schema_mismatch');
        }
        return new self($a['analyte'], $opt('loinc'), $a['value'], $opt('unit'), $opt('reference_range'), $opt('abnormal_flag'), ($a['unit_mismatch'] ?? false) === true, Citation::fromArray($a['citation']));
    }

    public function numericValue(): ?float
    {
        return preg_match('/^-?\d+(?:\.\d+)?$/', $this->value) === 1 ? (float) $this->value : null;
    }
}
