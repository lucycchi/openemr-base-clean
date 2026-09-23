<?php

/**
 * One lab result as the chart holds it. `value` is the numeric reading, or
 * null for a qualitative result ("positive", "<5") whose wording is in
 * `text`. `printedRange` and `labFlag` are what the reporting laboratory
 * said about the result (the `range` and `abnormal` columns); the briefing
 * judges them alongside the standard reference table.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot;

use OpenEMR\Modules\ClinicalCopilot\Documents\Citation;

final readonly class LabRecord
{
    public function __construct(
        public int $id,
        public int $encounterId,
        public string $loinc,
        public string $name,
        public ?float $value,
        public string $units,
        public \DateTimeImmutable $date,
        public ?Citation $citation = null,
        public bool $unitMismatch = false,
        public ?string $printedRange = null,
        public string $labFlag = '',
        public ?string $text = null,
    ) {
    }

    public function isNumeric(): bool
    {
        return $this->value !== null;
    }
}
