<?php

/**
 * The model refused to answer.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Llm;

/** The model returned a refusal instead of content — typically a safety filter. Label shown to the user is below. */
final class LlmRefusal extends LlmException
{
    public function statusLabel(): string
    {
        return "AI summary unavailable: model declined";
    }
}
