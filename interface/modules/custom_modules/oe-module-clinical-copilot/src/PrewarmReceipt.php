<?php

/**
 * What one pre-warm row recorded: enough to explain, at chart open, whether
 * the warmed briefing matched and, if not, why. Fact lines are id/service/
 * category/value references, the same non-identifying form the cache holds.
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
 * One persisted row from the pre-warm receipts table. Compared to
 * PrewarmRow (the in-memory version) it also carries the prompt version,
 * model, cache key and created-at timestamp, because those are what
 * WarmOutcome compares against at chart open to explain a hit or miss.
 */
final readonly class PrewarmReceipt
{
    /** @param list<string> $factLines FactSet::lines() at warm time; empty when nothing was assembled */
    public function __construct(
        public string $runId,
        public string $targetDate,
        public int $eventId,
        public int $pid,
        public string $providerUsername,
        public ?string $factsHash,
        public ?string $cacheKey,
        public string $promptVersion,
        public string $model,
        public array $factLines,
        public PrewarmStatus $status,
        public int $durationMs,
        public bool $modelCalled,
        public string $correlationId,
        public string $createdAt,
    ) {
    }
}
