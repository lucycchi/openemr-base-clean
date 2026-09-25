<?php

/**
 * The guideline section for one briefing, plus what building it cost and
 * where it came from.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Guidelines;

use OpenEMR\Modules\ClinicalCopilot\EvidenceChunk;
use OpenEMR\Modules\ClinicalCopilot\Pricing;

/**
 * Returned by GuidelineEvidence to both callers: chart open copies the
 * handoffs and usage into its request log and trace, the pre-warm sweep
 * records the status on its receipt and adds up the cost.
 */
final readonly class GuidelineOutcome
{
    /**
     * @param list<array{from: string, to: string, reason: string, state_keys_changed: list<string>, ms: int}> $handoffs the sidecar's hops; empty when it was not called
     * @param list<array{model: string, kind: string, input: int, output: int, cost_usd: ?float}> $usage the sidecar's model calls, priced
     */
    public function __construct(
        public GuidelineSection $section,
        public GuidelineStatus $status,
        public bool $fromCache = false,
        public array $handoffs = [],
        public array $usage = [],
    ) {
    }

    public static function noneFired(): self
    {
        return new self(GuidelineSection::none('no_triggers'), GuidelineStatus::NoneFired);
    }

    public static function unavailable(): self
    {
        return new self(GuidelineSection::none('unavailable'), GuidelineStatus::Unavailable);
    }

    /**
     * The passages the narration may restate: only from cards the critic judged
     * to apply to this patient. A card with no verdict (the critic failed, or
     * did not run) is still shown, labelled "applicability not assessed", but
     * its passage never reaches the summary, where a sentence would carry no
     * such label.
     *
     * @return list<EvidenceChunk>
     */
    public function vettedChunks(): array
    {
        $out = [];
        foreach ($this->section->cards as $card) {
            if ($card->applicable !== true) {
                continue;
            }
            foreach ($card->chunks as $chunk) {
                $out[] = $chunk;
            }
        }
        return $out;
    }

    /** What the sidecar's calls for this section cost, or 0.0 when it was not called. */
    public function costUsd(): float
    {
        return Pricing::totalCost($this->usage) ?? 0.0;
    }
}
