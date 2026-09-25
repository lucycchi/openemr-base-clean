<?php

/**
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Support;

use OpenEMR\Modules\ClinicalCopilot\AssembledFacts;
use OpenEMR\Modules\ClinicalCopilot\Config;
use OpenEMR\Modules\ClinicalCopilot\NarrationPipeline;
use OpenEMR\Modules\ClinicalCopilot\NarrationPipelines;
use OpenEMR\Modules\ClinicalCopilot\OmissionGuard;
use OpenEMR\Modules\ClinicalCopilot\Ops\StepRecorder;
use OpenEMR\Modules\ClinicalCopilot\PatientId;
use OpenEMR\Modules\ClinicalCopilot\Verifier;

/**
 * Real NarrationPipelines over the scripted model and an in-memory cache
 * shared by every pipeline it builds, so a second briefing of the same
 * chart is a cache hit, as it is with the briefing cache table.
 */
final class FakeNarrationPipelines implements NarrationPipelines
{
    public FakeLanguageModel $llm;
    public FakeBriefingCache $cache;

    public function __construct()
    {
        $this->llm = new FakeLanguageModel();
        $this->cache = new FakeBriefingCache();
    }

    public function create(Config $config, AssembledFacts $assembled, PatientId $pid, ?string $correlationId, StepRecorder $steps): NarrationPipeline
    {
        return new NarrationPipeline($this->llm, new Verifier(), new OmissionGuard(), $this->cache, steps: $steps);
    }
}
