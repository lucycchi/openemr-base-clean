<?php

/**
 * Where the day's appointments come from (the calendar in production).
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

/**
 * Where the pre-warm gets its list of "who is coming in tomorrow".
 * DbScheduleSource reads OpenEMR's appointment table; tests hand in a fixed list.
 */
interface ScheduleSource
{
    /**
     * All appointments on the given calendar day.
     *
     * @return list<ScheduledAppointment>
     */
    public function appointmentsOn(DateTimeImmutable $day): array;
}
