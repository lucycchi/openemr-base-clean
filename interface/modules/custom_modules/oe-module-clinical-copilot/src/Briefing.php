<?php

/**
 * One briefing as BriefingService built it: the guideline cards, the
 * narration, and what the caller needs to log and check.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot;

use OpenEMR\Modules\ClinicalCopilot\Guidelines\GuidelineOutcome;

final readonly class Briefing
{
    /**
     * @param ?BriefingResult $narration null when no chat model is configured on the site; the cards are still built
     * @param ?string $keyRead the narration cache key this briefing looked up; null with no narration
     */
    public function __construct(
        public GuidelineOutcome $cards,
        public ?BriefingResult $narration = null,
        public ?string $keyRead = null,
        public int $llmMs = 0,
        public int $llmAttempts = 0,
    ) {
    }

    /**
     * The route this briefing took: the sidecar's hops for the cards, then the
     * answer stage, so the log shows when the briefing was ready.
     *
     * @return list<array{from: string, to: string, reason: string, state_keys_changed: list<string>, ms: int}>
     */
    public function handoffs(): array
    {
        return $this->narration === null
            ? $this->cards->handoffs
            : [...$this->cards->handoffs, ...AnswerRoute::forBriefing($this->narration, $this->llmMs)];
    }
}
