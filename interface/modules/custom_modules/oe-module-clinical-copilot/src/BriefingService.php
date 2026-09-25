<?php

/**
 * Builds a patient's briefing, for chart open and the 06:00 pre-warm alike.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot;

use DateTimeImmutable;
use OpenEMR\Modules\ClinicalCopilot\Guidelines\GuidelineOutcome;
use OpenEMR\Modules\ClinicalCopilot\Guidelines\GuidelineSource;
use OpenEMR\Modules\ClinicalCopilot\Ops\StepRecorder;

/**
 * The one place a briefing is built: the guideline cards, then the
 * narration with the passages the critic vetted. Chart open and the pre-warm
 * sweep both call only this, so a step added to the briefing reaches both
 * and the cache rows the sweep writes are the ones chart open reads. What
 * each caller does with the result (render and check a warm hit, or write a
 * receipt) stays with the caller.
 */
final readonly class BriefingService
{
    public function __construct(
        private GuidelineSource $guidelines,
        private NarrationPipelines $pipelines,
        private Config $config,
    ) {
    }

    /**
     * @param DateTimeImmutable $day the day the briefing is for: now at chart open, the scheduled day in the sweep
     * @param StepRecorder $steps records the card and narration steps; chart open passes its request's recorder
     */
    public function brief(AssembledFacts $assembled, PatientId $pid, DateTimeImmutable $day, string $correlationId, StepRecorder $steps): Briefing
    {
        // The guideline section needs no chat model: retrieval runs in the sidecar
        // and the critic is the sidecar's own call. It is built before the
        // narration so an unconfigured or failed model still leaves it on the page.
        $cards = $steps->measure(
            'retrieve_chart_evidence',
            fn() => $this->guidelines->build($assembled, $pid, $day, $correlationId),
            static fn(GuidelineOutcome $g) => ['guideline_status' => $g->section->status, 'guideline_cards' => count($g->section->cards), 'guideline_dropped' => $g->section->dropped],
        );
        if (!$this->config->hasOpenAi()) {
            return new Briefing($cards);
        }
        // The passages of cards the critic vetted for this patient are offered to the
        // narration under the same contract as an answer's evidence: cited by chunk
        // id, numbers verified. An unassessed card is shown but never restated.
        // They are part of the narration's cache key.
        $evidence = new EvidenceSet($cards->vettedChunks());
        $t = hrtime(true);
        $pipeline = $this->pipelines->create($this->config, $assembled, $pid, $correlationId, $steps);
        $result = $pipeline->brief($assembled, $evidence);
        return new Briefing($cards, $result, $pipeline->cacheKey($assembled, $evidence), (int) round((hrtime(true) - $t) / 1e6), $pipeline->llmAttempts());
    }
}
