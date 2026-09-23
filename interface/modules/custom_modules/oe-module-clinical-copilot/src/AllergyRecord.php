<?php

/**
 * One allergy row as the assembler needs it.
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
 * One allergy from the chart. $beginDateProvenance says whether $beginDate is
 * a true onset date or just the day the allergy was first entered.
 */
final readonly class AllergyRecord
{
    public function __construct(
        public int $id,
        public string $title,
        public \DateTimeImmutable $beginDate,
        public DateProvenance $beginDateProvenance = DateProvenance::Recorded,
    ) {
    }
}
