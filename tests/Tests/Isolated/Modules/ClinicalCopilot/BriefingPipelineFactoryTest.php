<?php

/**
 * BriefingPipelineFactory wires one NarrationPipeline for the panel and the
 * pre-warm command alike.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Modules\ClinicalCopilot;

use OpenEMR\Modules\ClinicalCopilot\AssembledFacts;
use OpenEMR\Modules\ClinicalCopilot\BriefingPipelineFactory;
use OpenEMR\Modules\ClinicalCopilot\Config;
use OpenEMR\Modules\ClinicalCopilot\Fact;
use OpenEMR\Modules\ClinicalCopilot\FactCategory;
use OpenEMR\Modules\ClinicalCopilot\FactSet;
use OpenEMR\Modules\ClinicalCopilot\Ops\StepRecorder;
use OpenEMR\Modules\ClinicalCopilot\PatientId;
use OpenEMR\Modules\ClinicalCopilot\Prompt;
use OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Support\ModuleAutoload;
use PHPUnit\Framework\TestCase;

final class BriefingPipelineFactoryTest extends TestCase
{
    /**
     * @codeCoverageIgnore Fixture wiring; runs before coverage attribution.
     */
    public static function setUpBeforeClass(): void
    {
        ModuleAutoload::register();
    }

    private function config(string $model): Config
    {
        return new Config('sk-test', $model, 'https://cloud.langfuse.com', '', '');
    }

    private function facts(): AssembledFacts
    {
        return new AssembledFacts(new FactSet([
            new Fact('a1b2c3d4', 'PrescriptionService', 17, 'drug', 'Metformin 500 MG Oral Tablet', FactCategory::MedicationActive),
        ]), null);
    }

    public function testPipelineCacheKeyUsesTheConfiguredModelAndPromptVersion(): void
    {
        $assembled = $this->facts();

        $pipeline = (new BriefingPipelineFactory())->create($this->config('gpt-4o-mini'), $assembled, new PatientId(7), 'corr-1', new StepRecorder());

        $expected = hash('sha256', $assembled->facts()->hash() . '|' . Prompt::VERSION . '|gpt-4o-mini');
        self::assertSame($expected, $pipeline->cacheKey($assembled));
    }

    public function testPipelineRecordsStepsIntoTheCallersRecorder(): void
    {
        $steps = new StepRecorder();

        $pipeline = (new BriefingPipelineFactory())->create($this->config('gpt-4o-mini'), $this->facts(), new PatientId(7), null, $steps);

        self::assertSame($steps, $pipeline->steps());
    }
}
