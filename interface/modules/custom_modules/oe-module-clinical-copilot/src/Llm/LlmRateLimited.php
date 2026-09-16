<?php

/**
 * The provider rate-limited us (HTTP 429) even after one retry.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Llm;

final class LlmRateLimited extends LlmException
{
    public function statusLabel(): string
    {
        return "AI summary unavailable: provider busy, try again shortly";
    }
}
