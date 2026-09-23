<?php

/**
 * A verdict plus the parenthetical the fact carries ("above the lab's range
 * 3.5-5.1"), and the range clause a delta fact appends ("reference range
 * 4-5.6 %"). Produced by LabJudge, consumed by FactAssembler.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot;

final readonly class LabJudgement
{
    public function __construct(
        public LabVerdict $verdict,
        public string $clause,
        public string $rangeClause,
        /** 'above' or 'below' the range or limit that was violated, null when nothing was */
        public ?string $direction = null,
    ) {
    }
}
