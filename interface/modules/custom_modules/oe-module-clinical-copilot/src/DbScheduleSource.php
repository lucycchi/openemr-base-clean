<?php

/**
 * The day's appointments from the OpenEMR calendar, joined to the provider's
 * username so the pre-warm can assemble under that provider's ACL.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot;

use DateTimeImmutable;
use OpenEMR\Common\Database\QueryUtils;

/**
 * Production ScheduleSource: reads OpenEMR's calendar table for a given day,
 * joined to the provider's user row so the pre-warm knows which username's
 * ACL view to warm under. Rows with no patient or no provider are dropped.
 */
final readonly class DbScheduleSource implements ScheduleSource
{
    // Cancelled, no-show and cancelled-under-24h from list_options.apptstat.
    // Site-configurable codes are a follow-up; these are the shipped defaults.
    private const EXCLUDED_STATUSES = ['x', '?', '%'];

    public function appointmentsOn(DateTimeImmutable $day): array
    {
        // Build "?,?,?" for the NOT IN list so the statuses are bound, not interpolated.
        $placeholders = implode(',', array_fill(0, count(self::EXCLUDED_STATUSES), '?'));
        $rows = QueryUtils::fetchRecords(
            "SELECT e.pc_eid, e.pc_pid, u.username
               FROM openemr_postcalendar_events e
               JOIN users u ON u.id = e.pc_aid
              WHERE e.pc_eventDate = ?
                AND e.pc_pid <> ''
                AND e.pc_pid IS NOT NULL
                AND u.username <> ''
                AND e.pc_apptstatus NOT IN ($placeholders)
              ORDER BY e.pc_startTime, e.pc_eid",
            [$day->format('Y-m-d'), ...self::EXCLUDED_STATUSES]
        );
        // pc_pid is a string column in OpenEMR; accept only positive integers.
        $appointments = [];
        foreach ($rows as $r) {
            $pid = Row::str($r, 'pc_pid');
            if (!ctype_digit($pid) || (int) $pid <= 0) {
                continue;
            }
            $appointments[] = new ScheduledAppointment(Row::int($r, 'pc_eid'), new PatientId((int) $pid), Row::str($r, 'username'), $day);
        }
        return $appointments;
    }
}
