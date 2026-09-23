<?php

/**
 * The model output did not parse as the requested schema.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Llm;

/** The reply was not valid JSON or did not match the requested schema. Label shown to the user is below. */
final class LlmSchemaMismatch extends LlmException
{
    public function statusLabel(): string
    {
        return "AI summary unavailable: malformed response";
    }
}
