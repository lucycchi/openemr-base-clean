<?php

/**
 * In-memory briefing cache for pipeline tests.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Support;

use OpenEMR\Modules\ClinicalCopilot\BriefingCache;
use OpenEMR\Modules\ClinicalCopilot\CachedNarration;

/**
 * In-memory BriefingCache. $entries is public so a test can pre-seed a hit,
 * assert what was stored, or count entries; $generatedAt is what every hit
 * reports as its timestamp.
 */
final class FakeBriefingCache implements BriefingCache
{
    /** @var array<string, array<string, mixed>> */
    public array $entries = [];
    /** What every stored entry reports as its generation time. */
    public string $generatedAt = '2026-09-18T06:02:11+00:00';

    public function get(string $key): ?CachedNarration
    {
        return isset($this->entries[$key]) ? new CachedNarration($this->entries[$key], $this->generatedAt) : null;
    }

    public function put(string $key, array $narration): void
    {
        $this->entries[$key] = $narration;
    }
}
