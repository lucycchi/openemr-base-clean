<?php

/**
 * AdoptionCounts from form_encounter and the OpenEMR audit log.
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
 * Denominator: OpenEMR's own definition of a patient encounter, a
 * form_encounter row dated that day whose provider is the physician. An
 * encounter with no chart open at all counts, on purpose: that is a visit
 * where the chat was not used.
 *
 * Numerator: successful action=ask audit rows (typed questions). Each carries
 * the encounter the chart was open on (encounter_id=N, added 2026-09-24; older
 * rows have none and are not counted). The auto-rendered briefing
 * (action=brief) and a rating (action=rate) are not use: the physician did
 * not choose to chat.
 */
final readonly class DbAdoptionCounts implements AdoptionCounts
{
    public function __construct(private CopilotAuditLog $audit)
    {
    }

    public function encounters(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $rows = QueryUtils::fetchRecords(
            "SELECT DATE(fe.date) AS day, u.username, fe.encounter
               FROM form_encounter fe JOIN users u ON u.id = fe.provider_id
              WHERE fe.date >= ? AND fe.date < ? AND u.username <> ''
              ORDER BY fe.date, fe.encounter",
            [$from->format('Y-m-d 00:00:00'), $to->format('Y-m-d 00:00:00')]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[Row::str($row, 'username')][Row::str($row, 'day')][] = Row::int($row, 'encounter');
        }
        return $out;
    }

    public function asks(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $out = [];
        foreach ($this->audit->successful($from, $to) as $row) {
            if (!str_starts_with($row['comment'], 'action=ask ') || preg_match('/(?:^| )encounter_id=(\d+)(?: |$)/', $row['comment'], $m) !== 1) {
                continue;
            }
            $encounter = (int) $m[1];
            if ($encounter > 0) {
                $out[$row['user']][$encounter] = ($out[$row['user']][$encounter] ?? 0) + 1;
            }
        }
        return $out;
    }
}
