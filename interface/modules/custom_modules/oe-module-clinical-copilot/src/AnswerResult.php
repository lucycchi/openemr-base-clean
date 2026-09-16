<?php

/**
 * Verified follow-up answer.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot;

final readonly class AnswerResult
{
    /** @param list<Sentence> $sentences kept sentences */
    public function __construct(
        public string $answerType,
        public array $sentences,
        public int $strippedCount,
        public ?string $status,
        public int $promptTokens,
        public int $completionTokens,
    ) {
    }
}
