<?php

/**
 * Builds the guideline section for a briefing.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Guidelines;

use DateTimeImmutable;
use OpenEMR\Modules\ClinicalCopilot\AssembledFacts;
use OpenEMR\Modules\ClinicalCopilot\PatientId;

/**
 * The one way a briefing gets its guideline cards, whether the chart is
 * being opened or the 06:00 sweep is warming it. GuidelineEvidence is the
 * real one; the pre-warm's unit tests use a fake.
 */
interface GuidelineSource
{
    /**
     * @param DateTimeImmutable $day the day the briefing is for; the patient's age is taken on it
     * @param string $correlationId sent to the sidecar so its log lines join this request's
     */
    public function build(AssembledFacts $assembled, PatientId $pid, DateTimeImmutable $day, string $correlationId): GuidelineOutcome;
}
