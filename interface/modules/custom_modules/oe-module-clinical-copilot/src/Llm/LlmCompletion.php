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

/**
 * A successful model reply: the decoded JSON body plus the token usage the
 * provider reported (used for cost tracking) and how many HTTP attempts it took.
 */
final readonly class LlmCompletion
{
    /** @param array<string, mixed> $data  Decoded JSON that already passed schema validation. */
    public function __construct(
        public array $data,
        public int $promptTokens,
        public int $completionTokens,
        /** HTTP attempts the call took: 1 normally, 2 when the one retry was used. */
        public int $attempts = 1,
    ) {
    }
}
