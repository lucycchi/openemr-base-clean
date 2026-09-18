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
