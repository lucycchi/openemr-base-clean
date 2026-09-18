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

/**
 * Anything that can turn a patient's facts into a briefing. Two
 * implementations: PipelineNarrator (the real one, wraps NarrationPipeline)
 * and UnconfiguredNarrator (used when no API key is set). The controller only
 * ever depends on this interface.
 */
interface BriefingNarrator
{
    /** @param string $correlationId  Request id threaded through logs and traces. */
    public function brief(AssembledFacts $assembled, PatientId $pid, string $correlationId): BriefingResult;
}
