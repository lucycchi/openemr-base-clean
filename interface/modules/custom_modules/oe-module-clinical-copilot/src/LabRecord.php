<?php

/**
 * One numeric lab result as the assembler needs it.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot;

/**
 * One numeric lab result. Only numeric results are loaded (see
 * ChartSource::labs) because the abnormal/delta logic needs a float to
 * compare against ReferenceRanges. $loinc is the standard test code.
 */
final readonly class LabRecord
{
    public function __construct(
        public int $id,
        public int $encounterId,
        public string $loinc,
        public string $name,
        public float $value,
        public string $units,
        public \DateTimeImmutable $date,
    ) {
    }
}
