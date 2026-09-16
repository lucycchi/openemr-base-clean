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
