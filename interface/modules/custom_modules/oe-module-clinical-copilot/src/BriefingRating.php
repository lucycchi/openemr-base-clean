<?php

/**
 * A physician's thumbs up or down on one AI summary (KEY_METRICS.md metric 6).
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
 * Backed because the value is stored (copilot_briefing_rating.rating) and
 * arrives in the request body (rating=up).
 */
enum BriefingRating: string
{
    case Up = 'up';
    case Down = 'down';

    /** The Langfuse score value: 1 for up, 0 for down (a boolean score, so the share of up is a built-in rate). */
    public function scoreValue(): int
    {
        return match ($this) {
            self::Up => 1,
            self::Down => 0,
        };
    }
}
