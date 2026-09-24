<?php

/**
 * One day of KEY_METRICS.md metric 6: briefings rendered and how they were rated.
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
 * Up, down and not rated are shares of briefings rendered, so they sum to
 * 100 and an ignored briefing stays visible (an up/(up+down) ratio would
 * hide it).
 */
final readonly class RatingDay
{
    public function __construct(
        public string $day,
        public int $rendered,
        public int $up,
        public int $down,
    ) {
    }

    /** Rendered briefings nobody rated. Never negative: a rating on a summary rendered the day before counts on its own day. */
    public function notRated(): int
    {
        return max(0, $this->rendered - $this->up - $this->down);
    }

    /** @return array{up: float, down: float, not_rated: float}|null percentages of rendered, null on a day with no briefings */
    public function shares(): ?array
    {
        if ($this->rendered === 0) {
            return null;
        }
        $pct = fn(int $n): float => round(100 * $n / max($this->rendered, $this->up + $this->down), 1);
        return ['up' => $pct($this->up), 'down' => $pct($this->down), 'not_rated' => $pct($this->notRated())];
    }

    /** @return array{day: string, rendered: int, up: int, down: int, not_rated: int, shares: array{up: float, down: float, not_rated: float}|null} */
    public function toArray(): array
    {
        return ['day' => $this->day, 'rendered' => $this->rendered, 'up' => $this->up, 'down' => $this->down, 'not_rated' => $this->notRated(), 'shares' => $this->shares()];
    }
}
