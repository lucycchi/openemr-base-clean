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

interface BriefingCache
{
    public function get(string $key): ?CachedNarration;

    /** @param array<string, mixed> $narration */
    public function put(string $key, array $narration): void;
}
