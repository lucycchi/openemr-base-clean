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

final class FakeBriefingCache implements BriefingCache
{
    /** @var array<string, array<string, mixed>> */
    public array $entries = [];

    public function get(string $key): ?array
    {
        return $this->entries[$key] ?? null;
    }

    public function put(string $key, array $narration): void
    {
        $this->entries[$key] = $narration;
    }
}
