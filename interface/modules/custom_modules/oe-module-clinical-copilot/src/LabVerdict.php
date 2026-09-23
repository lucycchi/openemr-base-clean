<?php

/**
 * What the briefing concluded about one lab result after weighing the
 * laboratory's flag, the range printed on the report and the standard
 * reference table. Unranged means nothing could judge it, so no fact is made.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot;

enum LabVerdict
{
    case Critical;
    case Abnormal;
    case Normal;
    case Unranged;
}
