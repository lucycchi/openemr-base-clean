<?php

/**
 * Where physician ratings of the AI summary are kept (KEY_METRICS.md metric 6).
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
 * An interface so the controller can be tested without a database;
 * DbBriefingRatings is the copilot_briefing_rating implementation.
 */
interface BriefingRatings
{
    /**
     * The cached briefing this key names, if it belongs to this patient.
     * Null for a key that is unknown or cached for another patient: a
     * rating is only taken on a summary this chart actually showed.
     */
    public function briefing(PatientId $pid, string $cacheKey): ?RatedBriefing;

    /**
     * Stores the rating. One row per user and briefing: rating the same
     * summary again replaces the rating and the comment.
     */
    public function record(PatientId $pid, ?int $encounterId, int $userId, RatingSubmission $rating, RatedBriefing $briefing): void;
}
