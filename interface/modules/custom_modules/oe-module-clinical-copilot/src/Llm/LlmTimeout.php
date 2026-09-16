<?php

/**
 * The provider did not answer within the time budget.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Llm;

final class LlmTimeout extends LlmException
{
    public function statusLabel(): string
    {
        return "AI summary unavailable: timed out";
    }
}
