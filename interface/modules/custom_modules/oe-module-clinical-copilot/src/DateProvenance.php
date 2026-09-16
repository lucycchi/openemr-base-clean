<?php

/**
 * Where a medication or allergy date came from, so the fact can say so.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot;

enum DateProvenance
{
    /** The clinician recorded an actual start (medication) or onset (allergy) date. */
    case Recorded;
    /** No start date on file; the date is when the clinician first entered the record. */
    case FirstNoted;
    /** Neither date is on file. */
    case Unknown;
}
