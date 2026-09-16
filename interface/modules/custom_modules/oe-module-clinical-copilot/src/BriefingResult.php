<?php

/**
 * Verified briefing narration ready to render, or a status explaining why there is none.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot;

final readonly class BriefingResult
{
    /**
     * @param list<Sentence> $sentences kept sentences
     * @param list<Fact> $omitted must-surface facts to append deterministically
     */
    public function __construct(
        public array $sentences,
        public int $strippedCount,
        public array $omitted,
        public ?string $status,
        public bool $fromCache,
        public bool $totalFailure,
        public int $promptTokens,
        public int $completionTokens,
    ) {
    }
}
