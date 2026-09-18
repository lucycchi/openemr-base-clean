<?php

/**
 * One place that wires a NarrationPipeline, shared by the panel endpoint and
 * the pre-warm command so both narrate and cache identically.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use OpenEMR\Modules\ClinicalCopilot\Llm\OpenAiClient;
use OpenEMR\Modules\ClinicalCopilot\Ops\StepRecorder;

final readonly class BriefingPipelineFactory
{
    public function __construct(private ClientInterface $http = new Client())
    {
    }

    public function create(Config $config, AssembledFacts $assembled, PatientId $pid, ?string $correlationId, StepRecorder $steps): NarrationPipeline
    {
        $llm = new OpenAiClient($this->http, $config->openAiApiKey, $config->openAiModel, correlationId: $correlationId);
        $cache = new DbBriefingCache($pid, $assembled->facts()->hash(), $config->openAiModel);
        return new NarrationPipeline($llm, new Verifier(), new OmissionGuard(), $cache, steps: $steps);
    }
}
