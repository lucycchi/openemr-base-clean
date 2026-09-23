<?php

/**
 * Body of prewarm.php: is the sweep enabled here, and what did the last run do.
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
 * Builds the JSON body for public/prewarm.php: whether pre-warm is enabled,
 * the last run's counters (or null if it never ran), and the server time.
 * Kept as a tiny static builder so the endpoint and its test share one shape.
 */
final class PrewarmStatusPayload
{
    /** @return array<string, mixed> */
    public static function build(bool $enabled, ?PrewarmRunStatus $lastRun, string $time): array
    {
        return [
            'enabled' => $enabled,
            'last_run' => $lastRun?->toArray(),
            'time' => $time,
        ];
    }
}
