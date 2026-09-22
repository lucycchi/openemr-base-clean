<?php

/**
 * One step of one agent request, in the order it ran: what it was, when it
 * started, how long it took, whether it failed and why. No PHI.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Ops;

/**
 * One timed stage of a request ("cache_lookup", "llm.briefing", "verify"...),
 * as recorded by StepRecorder. $detail holds a few scalars worth keeping
 * about the outcome (hit/miss, token counts, kept/stripped). Becomes a span
 * in the Langfuse trace and a log line.
 */
final readonly class Step
{
    /** @param array<string, scalar|null> $detail */
    public function __construct(
        public string $name,
        public int $startedAtMs,
        public int $durationMs,
        public ?string $error,
        public array $detail,
    ) {
    }

    /**
     * Flattens the step into a PSR-3 context array (step name, ms, error, plus $detail).
     *
     * @return array<string, scalar|null>
     */
    public function toLogContext(): array
    {
        return ['step' => $this->name, 'ms' => $this->durationMs, 'error' => $this->error] + $this->detail;
    }
}
