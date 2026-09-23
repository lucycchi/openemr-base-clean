<?php

/**
 * One reference interval for a lab analyte: the normal bounds, the unit
 * they are expressed in, optional critical ("panic") bounds, and the source
 * the numbers came from. A bound of null means the interval is one-sided
 * (eGFR has a lower bound only).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot;

final readonly class Range
{
    public function __construct(
        public ?float $low,
        public ?float $high,
        public string $unit,
        public ?float $panicLow = null,
        public ?float $panicHigh = null,
        public string $source = '',
        public ?string $sex = null,
    ) {
    }

    /** "135-145", "60 or above", "129 or below": the interval as a clinician reads it. */
    public function describe(): string
    {
        $n = static fn(float $v): string => rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
        if ($this->low !== null && $this->high !== null) {
            return $n($this->low) . '-' . $n($this->high);
        }
        if ($this->low !== null) {
            return $n($this->low) . ' or above';
        }
        if ($this->high !== null) {
            return $n($this->high) . ' or below';
        }
        return '';
    }

    public function contains(float $value): bool
    {
        return ($this->low === null || $value >= $this->low) && ($this->high === null || $value <= $this->high);
    }

    /** Outside the panic bounds, when any are defined. */
    public function isCritical(float $value): bool
    {
        return ($this->panicLow !== null && $value < $this->panicLow) || ($this->panicHigh !== null && $value > $this->panicHigh);
    }
}
