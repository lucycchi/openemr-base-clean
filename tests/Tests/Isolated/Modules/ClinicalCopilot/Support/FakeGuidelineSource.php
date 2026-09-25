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

use DateTimeImmutable;
use OpenEMR\Modules\ClinicalCopilot\AssembledFacts;
use OpenEMR\Modules\ClinicalCopilot\Guidelines\GuidelineOutcome;
use OpenEMR\Modules\ClinicalCopilot\Guidelines\GuidelineSource;
use OpenEMR\Modules\ClinicalCopilot\PatientId;

/**
 * Scripted guideline cards: returns $outcome (no cards by default) or throws
 * $throw, and records what each build was asked for.
 */
final class FakeGuidelineSource implements GuidelineSource
{
    public ?GuidelineOutcome $outcome = null;
    public ?\Throwable $throw = null;
    /** @var list<array{pid: int, day: string, correlationId: string, factsHash: string}> */
    public array $builds = [];

    public function build(AssembledFacts $assembled, PatientId $pid, DateTimeImmutable $day, string $correlationId): GuidelineOutcome
    {
        $this->builds[] = ['pid' => $pid->value, 'day' => $day->format('Y-m-d H:i'), 'correlationId' => $correlationId, 'factsHash' => $assembled->facts()->hash()];
        if ($this->throw !== null) {
            throw $this->throw;
        }
        return $this->outcome ?? GuidelineOutcome::noneFired();
    }
}
