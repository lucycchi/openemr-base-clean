<?php

/**
 * Cache of verified briefing narrations keyed by facts, prompt version, and model.
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
 * Key/value store for generated briefings. The key comes from
 * NarrationPipeline::cacheKey() (facts hash + prompt version + model), so a
 * hit is only possible when nothing that influenced the output has changed.
 * DbBriefingCache is the production implementation; tests use an in-memory fake.
 */
interface BriefingCache
{
    /** Returns null on a miss. */
    public function get(string $key): ?CachedNarration;

    /** Stores the raw decoded model JSON (not the verified sentences). @param array<string, mixed> $narration */
    public function put(string $key, array $narration): void;
}
