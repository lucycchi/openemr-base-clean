<?php

/**
 * Builds the narration pipeline for one briefing.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot;

use OpenEMR\Modules\ClinicalCopilot\Ops\StepRecorder;

/**
 * BriefingPipelineFactory is the real one (OpenAI, the briefing cache
 * table); unit tests build a NarrationPipeline over a fake model and an
 * in-memory cache.
 */
interface NarrationPipelines
{
    public function create(Config $config, AssembledFacts $assembled, PatientId $pid, ?string $correlationId, StepRecorder $steps): NarrationPipeline;
}
