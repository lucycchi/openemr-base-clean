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

/**
 * What happened to one patient during an overnight pre-warm run. String-backed
 * because the value is persisted in the receipts table and shown on the
 * dashboard.
 *
 *   Warmed         - called the model and stored a fresh briefing
 *   AlreadyCached  - a valid briefing was already in the cache; no model call
 *   Skipped        - not attempted (e.g. no facts, or the run was cut short)
 *   Error          - the model call failed
 */
enum PrewarmStatus: string
{
    case Warmed = 'warmed';
    case AlreadyCached = 'already_cached';
    case Skipped = 'skipped';
    case Error = 'error';
}
