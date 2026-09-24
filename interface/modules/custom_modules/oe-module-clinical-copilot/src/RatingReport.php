<?php

/**
 * KEY_METRICS.md metric 6, physician rating of the summary, over a window of days.
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
 * Pure arithmetic over RatingCounts, so it is tested without a database.
 * Every day in the window is listed, including days with no briefings.
 */
final readonly class RatingReport
{
    public function __construct(private RatingCounts $counts)
    {
    }

    /** @return list<RatingDay> oldest first */
    public function days(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $rendered = $this->counts->renderedByDay($from, $to);
        $ratings = $this->counts->ratingsByDay($from, $to);
        $days = [];
        for ($d = $from; $d < $to; $d = $d->modify('+1 day')) {
            $key = $d->format('Y-m-d');
            $days[] = new RatingDay($key, $rendered[$key] ?? 0, $ratings[$key]['up'] ?? 0, $ratings[$key]['down'] ?? 0);
        }
        return $days;
    }

    /** The whole window as one row, for the headline number. */
    public function total(\DateTimeImmutable $from, \DateTimeImmutable $to): RatingDay
    {
        $rendered = $up = $down = 0;
        foreach ($this->days($from, $to) as $day) {
            $rendered += $day->rendered;
            $up += $day->up;
            $down += $day->down;
        }
        return new RatingDay($from->format('Y-m-d') . '..' . $to->modify('-1 day')->format('Y-m-d'), $rendered, $up, $down);
    }

    /** @return list<array{prompt_version: string, model: string, up: int, down: int}> */
    public function byVersion(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->counts->ratingsByVersion($from, $to);
    }
}
