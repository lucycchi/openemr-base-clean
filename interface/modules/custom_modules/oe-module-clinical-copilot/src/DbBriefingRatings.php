<?php

/**
 * BriefingRatings stored in copilot_briefing_rating.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot;

use OpenEMR\Common\Database\QueryUtils;

/**
 * The comment is stored here and nowhere else: it is free text a physician
 * typed and may name the patient.
 */
final readonly class DbBriefingRatings implements BriefingRatings
{
    public function briefing(PatientId $pid, string $cacheKey): ?RatedBriefing
    {
        $row = QueryUtils::querySingleRow(
            "SELECT prompt_version, model FROM copilot_briefing_cache WHERE cache_key = ? AND pid = ?",
            [$cacheKey, $pid->value]
        );
        if ($row === false) {
            return null;
        }
        return new RatedBriefing($cacheKey, Row::str($row, 'prompt_version'), Row::str($row, 'model'));
    }

    public function record(PatientId $pid, ?int $encounterId, int $userId, RatingSubmission $rating, RatedBriefing $briefing): void
    {
        if ($userId <= 0) {
            throw new \DomainException('A rating needs the signed-in user id');
        }
        // Upsert on (user_id, briefing_cache_key): a second click replaces the
        // first, so one physician is one vote per summary. correlation_id
        // follows the latest rating's briefing request (a cache hit can show
        // the same narration on several requests).
        QueryUtils::sqlInsert(
            "INSERT INTO copilot_briefing_rating
                (pid, encounter_id, user_id, briefing_cache_key, prompt_version, model, correlation_id, rating, comment)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE rating = VALUES(rating), comment = VALUES(comment), correlation_id = VALUES(correlation_id),
                encounter_id = VALUES(encounter_id)",
            [$pid->value, $encounterId, $userId, $briefing->cacheKey, $briefing->promptVersion, $briefing->model, $rating->briefingCorrelationId, $rating->rating->value, $rating->comment]
        );
    }
}
