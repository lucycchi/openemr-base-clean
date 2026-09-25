<?php

/**
 * RatingCounts from the OpenEMR audit log and copilot_briefing_rating.
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
 * Briefings rendered are the successful action=brief audit rows, the same
 * source as metric 5 (read and decoded by CopilotAuditLog).
 */
final readonly class DbRatingCounts implements RatingCounts
{
    public function __construct(private CopilotAuditLog $audit)
    {
    }

    public function renderedByDay(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $out = [];
        foreach ($this->audit->successful($from, $to) as $row) {
            if (str_starts_with($row['comment'], 'action=brief ')) {
                $out[$row['day']] = ($out[$row['day']] ?? 0) + 1;
            }
        }
        return $out;
    }

    public function ratingsByDay(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $out = [];
        foreach ($this->grouped('DATE(created_at)', $from, $to) as $row) {
            $out[Row::str($row, 'grp')] = ['up' => Row::int($row, 'up'), 'down' => Row::int($row, 'down')];
        }
        return $out;
    }

    public function ratingsByVersion(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $rows = QueryUtils::fetchRecords(
            "SELECT prompt_version, model, SUM(rating = 'up') AS up, SUM(rating = 'down') AS down
               FROM copilot_briefing_rating WHERE created_at >= ? AND created_at < ?
              GROUP BY prompt_version, model ORDER BY prompt_version, model",
            [$from->format('Y-m-d 00:00:00'), $to->format('Y-m-d 00:00:00')]
        );
        return array_map(static fn(array $r): array => ['prompt_version' => Row::str($r, 'prompt_version'), 'model' => Row::str($r, 'model'), 'up' => Row::int($r, 'up'), 'down' => Row::int($r, 'down')], $rows);
    }

    /** @return list<array<mixed>> */
    private function grouped(string $by, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return QueryUtils::fetchRecords(
            "SELECT $by AS grp, SUM(rating = 'up') AS up, SUM(rating = 'down') AS down
               FROM copilot_briefing_rating WHERE created_at >= ? AND created_at < ? GROUP BY grp",
            [$from->format('Y-m-d 00:00:00'), $to->format('Y-m-d 00:00:00')]
        );
    }
}
