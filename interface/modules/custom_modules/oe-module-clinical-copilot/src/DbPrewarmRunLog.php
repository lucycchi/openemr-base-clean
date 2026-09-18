<?php

/**
 * Reads the most recent pre-warm run out of copilot_prewarm for the status
 * endpoint.
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
 * Reads the most recent pre-warm run's totals out of the receipts table.
 * There is no separate "runs" table: a run is just every receipt row sharing
 * a run_id, so the summary is a GROUP BY over those rows.
 */
final readonly class DbPrewarmRunLog
{
    /** Null if the pre-warm has never run on this server. */
    public function lastRun(): ?PrewarmRunStatus
    {
        // Sub-select finds the newest run_id; the outer query counts its rows
        // by status. SUM(status = 'x') is MySQL's idiom for "count where".
        $row = QueryUtils::querySingleRow(
            "SELECT run_id, target_date,
                    MAX(created_at) AS finished_at,
                    COUNT(*) AS scheduled,
                    SUM(status = 'warmed') AS warmed,
                    SUM(status = 'already_cached') AS already_cached,
                    SUM(status = 'skipped') AS skipped,
                    SUM(status = 'error') AS errored
               FROM copilot_prewarm
              WHERE run_id = (SELECT run_id FROM copilot_prewarm ORDER BY id DESC LIMIT 1)
              GROUP BY run_id, target_date",
            []
        );
        if ($row === false) {
            return null;
        }
        $finished = new \DateTimeImmutable(Row::str($row, 'finished_at'));
        return new PrewarmRunStatus(
            Row::str($row, 'run_id'),
            Row::str($row, 'target_date'),
            $finished->format(\DateTimeInterface::ATOM),
            Row::int($row, 'scheduled'),
            Row::int($row, 'warmed'),
            Row::int($row, 'already_cached'),
            Row::int($row, 'skipped'),
            Row::int($row, 'errored'),
        );
    }
}
