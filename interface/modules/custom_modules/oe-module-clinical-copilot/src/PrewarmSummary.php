<?php

/**
 * Totals for one pre-warm run, plus every row for the receipt.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot;

final readonly class PrewarmSummary
{
    public int $scheduled;
    public int $warmed;
    public int $alreadyCached;
    public int $skipped;
    public int $errored;

    /** @param list<PrewarmRow> $rows */
    public function __construct(public array $rows)
    {
        $this->scheduled = count($rows);
        $this->warmed = $this->count(PrewarmStatus::Warmed);
        $this->alreadyCached = $this->count(PrewarmStatus::AlreadyCached);
        $this->skipped = $this->count(PrewarmStatus::Skipped);
        $this->errored = $this->count(PrewarmStatus::Error);
    }

    private function count(PrewarmStatus $status): int
    {
        return count(array_filter($this->rows, static fn(PrewarmRow $r) => $r->status === $status));
    }
}
