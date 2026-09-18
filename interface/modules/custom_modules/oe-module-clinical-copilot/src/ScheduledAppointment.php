<?php

/**
 * One appointment on the day's schedule: who is coming and which provider
 * will open the chart.
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

final readonly class ScheduledAppointment
{
    public function __construct(
        public int $eventId,
        public PatientId $pid,
        public string $providerUsername,
        public DateTimeImmutable $day,
    ) {
        if ($providerUsername === '') {
            throw new \DomainException('Scheduled appointment needs a provider username');
        }
    }
}
