<?php

/**
 * A narration read back from the briefing cache, with when it was generated.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot;

final readonly class CachedNarration
{
    /**
     * @param array<string, mixed> $data the model output as stored
     * @param string $generatedAt ISO 8601 with offset, in the site's zone
     */
    public function __construct(public array $data, public string $generatedAt)
    {
    }
}
