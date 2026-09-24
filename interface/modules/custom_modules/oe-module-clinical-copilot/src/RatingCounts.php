<?php

/**
 * The counts metric 6 is computed from.
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
 * Days are Y-m-d in the site's time zone; $from is inclusive, $to exclusive.
 * DbRatingCounts reads the audit log and copilot_briefing_rating.
 */
interface RatingCounts
{
    /** @return array<string, int> day => briefings rendered (successful action=brief requests) */
    public function renderedByDay(\DateTimeImmutable $from, \DateTimeImmutable $to): array;

    /** @return array<string, array{up: int, down: int}> day => ratings recorded that day */
    public function ratingsByDay(\DateTimeImmutable $from, \DateTimeImmutable $to): array;

    /** @return list<array{prompt_version: string, model: string, up: int, down: int}> ratings per prompt version and model */
    public function ratingsByVersion(\DateTimeImmutable $from, \DateTimeImmutable $to): array;
}
