<?php

/**
 * Everything observability needs about one agent request. No PHI: fact counts, not values.
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
 * Everything observability wants to know about one completed request,
 * assembled by the controller and handed to a Tracer. Immutable snapshot:
 * total duration, per-step timings, which model was called and how many
 * tokens it used, the estimated cost, and a status label if it failed.
 */
final readonly class RequestTrace
{
    /**
     * @param array<string, scalar|null|list<array{from: string, to: string, reason: string, state_keys_changed: list<string>, ms: int}>> $metadata  scalars, plus the Week 2 handoff list
     * @param list<Step> $steps
     * @param list<array{model: string, kind: string, input: int, output: int, cost_usd: ?float}> $sidecarUsage  Week 2: one entry per model call the sidecar made (chat per page, embedding, rerank), each becoming a generation
     */
    public function __construct(
        public string $correlationId,
        public string $name,
        public string $user,
        public int $startedAtMs,
        public int $durationMs,
        public array $metadata,
        public ?string $model,
        public int $promptTokens,
        public int $completionTokens,
        public int $llmDurationMs,
        public ?string $status,
        public array $steps = [],
        public ?float $costUsd = null,
        public array $sidecarUsage = [],
    ) {
    }
}
