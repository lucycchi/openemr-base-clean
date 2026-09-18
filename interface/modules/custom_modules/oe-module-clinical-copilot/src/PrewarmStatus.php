<?php

/**
 * Outcome of one pre-warm row. Backed because it is persisted in the receipt.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot;

enum PrewarmStatus: string
{
    case Warmed = 'warmed';
    case AlreadyCached = 'already_cached';
    case Skipped = 'skipped';
    case Error = 'error';
}
