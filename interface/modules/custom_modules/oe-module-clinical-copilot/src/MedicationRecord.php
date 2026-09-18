<?php

/**
 * One prescription row as the assembler needs it.
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
 * One medication from the chart. $active mirrors OpenEMR's active flag;
 * inactive meds are still listed but narrated as such.
 */
final readonly class MedicationRecord
{
    public function __construct(
        public int $id,
        public string $drug,
        public \DateTimeImmutable $startDate,
        public bool $active,
        public DateProvenance $startDateProvenance = DateProvenance::Recorded,
    ) {
    }
}
