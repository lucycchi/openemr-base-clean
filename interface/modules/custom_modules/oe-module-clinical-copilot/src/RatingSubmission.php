<?php

/**
 * A parsed action=rate request: which summary, which rating, what comment.
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
 * The summary is named by the cache key of its narration (the exact text
 * shown) and by the correlation id of the brief request that showed it (the
 * Langfuse trace the score attaches to). Both come back from the briefing
 * response; neither is trusted to belong to the open patient until the
 * controller finds the cache row for that patient.
 */
final readonly class RatingSubmission
{
    public const COMMENT_MAX = 2000;

    public function __construct(
        public BriefingRating $rating,
        /** Trimmed free text, null when the physician typed nothing. May name the patient: never logged or traced. */
        public ?string $comment,
        public string $cacheKey,
        public string $briefingCorrelationId,
    ) {
        if (!preg_match('/^[0-9a-f]{64}$/', $cacheKey)) {
            throw new \DomainException('cache_key must be a sha256');
        }
        if (!preg_match('/^[0-9a-f]{32}$/', $briefingCorrelationId)) {
            throw new \DomainException('briefing_correlation_id must be a correlation id');
        }
        if ($comment !== null && ($comment === '' || mb_strlen($comment) > self::COMMENT_MAX)) {
            throw new \DomainException('comment must be 1 to 2000 characters, or absent');
        }
    }

    /** Characters in the comment, 0 when there is none: the only trace of it outside the rating table. */
    public function commentChars(): int
    {
        return $this->comment === null ? 0 : mb_strlen($this->comment);
    }
}
