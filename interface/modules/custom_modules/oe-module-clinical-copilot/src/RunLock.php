<?php

/**
 * Single-instance guard for the pre-warm sweep.
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
 * Mutual exclusion for the pre-warm command, so two cron ticks (or a cron
 * tick plus a manual run) never warm the same schedule at the same time.
 * FileRunLock is the production implementation.
 */
interface RunLock
{
    /** True when this process now holds the lock; false when another does. */
    public function acquire(): bool;

    public function release(): void;
}
