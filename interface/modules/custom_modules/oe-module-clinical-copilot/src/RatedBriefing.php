<?php

/**
 * The cached briefing a rating is about: its prompt version and model.
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
 * Read from copilot_briefing_cache on the server, never taken from the
 * request, so the per-version comparison in KEY_METRICS.md metric 6 cannot be
 * skewed by what a browser sends.
 */
final readonly class RatedBriefing
{
    public function __construct(
        public string $cacheKey,
        public string $promptVersion,
        public string $model,
    ) {
    }
}
