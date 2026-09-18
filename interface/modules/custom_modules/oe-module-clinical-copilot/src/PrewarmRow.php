<?php

/**
 * The receipt for one scheduled patient in a pre-warm run.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot;

final readonly class PrewarmRow
{
    public function __construct(
        public ScheduledAppointment $appointment,
        public PrewarmStatus $status,
        public ?string $factsHash,
        public string $correlationId,
        public int $durationMs,
        public bool $modelCalled,
        public ?string $error = null,
        /** @var list<string>|null FactSet::lines() of what was narrated; null when nothing was assembled */
        public ?array $factLines = null,
    ) {
    }
}
