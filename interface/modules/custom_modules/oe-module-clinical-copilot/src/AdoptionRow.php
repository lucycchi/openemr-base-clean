<?php

/**
 * One row of KEY_METRICS.md metric 7 (chat adoption per encounter).
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
 * For one physician (or "all") over one period (a day, an ISO week, or the
 * whole window): the patient encounters they had, how many of those the chat
 * was used on (at least one typed question), and the questions asked on them.
 */
final readonly class AdoptionRow
{
    public function __construct(
        public string $physician,
        public string $period,
        public int $encounters,
        public int $used,
        public int $asks,
    ) {
    }

    /** Percent of encounters the chat was used on; null with no encounters. */
    public function share(): ?float
    {
        return $this->encounters === 0 ? null : round(100 * $this->used / $this->encounters, 1);
    }

    /** Mean questions per encounter the chat was used on; null when it was used on none. */
    public function meanAsks(): ?float
    {
        return $this->used === 0 ? null : round($this->asks / $this->used, 1);
    }

    /** @return array{physician: string, period: string, encounters: int, used: int, asks: int, share: ?float, mean_asks_per_used_encounter: ?float} */
    public function toArray(): array
    {
        return [
            'physician' => $this->physician,
            'period' => $this->period,
            'encounters' => $this->encounters,
            'used' => $this->used,
            'asks' => $this->asks,
            'share' => $this->share(),
            'mean_asks_per_used_encounter' => $this->meanAsks(),
        ];
    }
}
