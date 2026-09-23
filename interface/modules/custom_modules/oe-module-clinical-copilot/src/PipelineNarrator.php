<?php

/**
 * BriefingNarrator over the real pipeline: OpenAI, verifier, omission guard,
 * DB cache — the same wiring the panel uses, via BriefingPipelineFactory.
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
 * The production BriefingNarrator. Builds a fresh NarrationPipeline per
 * request via the factory (so each request gets its own HTTP client config,
 * correlation id and step recorder) and delegates to it.
 */
final readonly class PipelineNarrator implements BriefingNarrator
{
    public function __construct(
        private Config $config,
        private BriefingPipelineFactory $factory = new BriefingPipelineFactory(),
    ) {
    }

    public function brief(AssembledFacts $assembled, PatientId $pid, string $correlationId): BriefingResult
    {
        return $this->factory->create($this->config, $assembled, $pid, $correlationId, new StepRecorder())->brief($assembled);
    }
}
