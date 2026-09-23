<?php

/**
 * Which part of the prior visit's documentation a note is: the assessment
 * (what the clinician concluded) or the plan (what was decided to do).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot;

enum NoteKind
{
    case Assessment;
    case Plan;
}
