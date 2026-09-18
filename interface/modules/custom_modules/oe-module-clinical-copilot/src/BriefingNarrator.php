<?php

/**
 * Narrates one assembled fact set: the pipeline behind the panel and the
 * pre-warm alike, hidden behind a seam so the pre-warm loop is testable.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot;

interface BriefingNarrator
{
    public function brief(AssembledFacts $assembled, PatientId $pid, string $correlationId): BriefingResult;
}
