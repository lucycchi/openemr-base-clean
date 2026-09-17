<?php

/**
 * Decoded structured output plus token usage for cost tracking.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Llm;

final readonly class LlmCompletion
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public array $data,
        public int $promptTokens,
        public int $completionTokens,
        /** HTTP attempts the call took: 1 normally, 2 when the one retry was used. */
        public int $attempts = 1,
    ) {
    }
}
