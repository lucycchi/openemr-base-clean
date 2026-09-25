<?php

/**
 * What happened to the guideline cards for one briefing, as the pre-warm
 * receipt records it.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Guidelines;

/**
 * Backed because it is persisted (copilot_prewarm.guideline_status), so a
 * chart-open miss can say whether the 06:00 run had its cards.
 *
 *   NoneFired   - no trigger rule fired; there are no cards to build
 *   Built       - a complete section: fresh from the sidecar or served from
 *                 the cache, including one whose every card the critic rejected
 *   Partial     - shown but not cached: a worker failed, or a card has no
 *                 critic verdict; the next open builds it again
 *   Unavailable - the sidecar could not be reached (or is not configured)
 */
enum GuidelineStatus: string
{
    case NoneFired = 'none_fired';
    case Built = 'built';
    case Partial = 'partial';
    case Unavailable = 'unavailable';
}
