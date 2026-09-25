<?php

/**
 * Builds the "what the guidelines say about this chart" section of a
 * briefing, for chart open and for the 06:00 pre-warm alike.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Guidelines;

use Closure;
use DateTimeImmutable;
use OpenEMR\Modules\ClinicalCopilot\AssembledFacts;
use OpenEMR\Modules\ClinicalCopilot\BriefingCache;
use OpenEMR\Modules\ClinicalCopilot\ChartSource;
use OpenEMR\Modules\ClinicalCopilot\Config;
use OpenEMR\Modules\ClinicalCopilot\DbBriefingCache;
use OpenEMR\Modules\ClinicalCopilot\Documents\SidecarClient;
use OpenEMR\Modules\ClinicalCopilot\Documents\SidecarException;
use OpenEMR\Modules\ClinicalCopilot\GuidelineManifest;
use OpenEMR\Modules\ClinicalCopilot\PatientId;
use OpenEMR\Modules\ClinicalCopilot\Pricing;
use Psr\Log\LoggerInterface;

/**
 * Fire the trigger rules, serve a cached section when the facts and rules
 * are unchanged, else ask the sidecar (retrieval plus critic) and cache the
 * result. A sidecar that cannot be reached yields an "unavailable" section,
 * never an error. Chart open and the pre-warm sweep both build their cards
 * here, so what the sweep caches is what chart open reads.
 */
final readonly class GuidelineEvidence implements GuidelineSource
{
    /** @var Closure(PatientId, string): BriefingCache */
    private Closure $cacheFor;

    /** @param (Closure(PatientId, string): BriefingCache)|null $cacheFor the cache for a patient and facts hash; the briefing cache table when null */
    public function __construct(
        private ChartSource $chart,
        private SidecarClient $sidecar,
        private Config $config,
        private LoggerInterface $logger,
        ?Closure $cacheFor = null,
        private GuidelineTriggers $triggers = new GuidelineTriggers(),
    ) {
        $model = $config->openAiModel;
        $this->cacheFor = $cacheFor ?? static fn(PatientId $pid, string $factsHash): BriefingCache => new DbBriefingCache($pid, $factsHash, $model);
    }

    public function build(AssembledFacts $assembled, PatientId $pid, DateTimeImmutable $day, string $correlationId): GuidelineOutcome
    {
        $who = $this->chart->demographics($pid);
        $fired = $this->triggers->fire($assembled, $who, $day);
        if ($fired === []) {
            return GuidelineOutcome::noneFired();
        }
        $factsHash = $assembled->facts()->hash();
        $age = $who->ageOn($day);
        $key = GuidelineTriggers::cacheKey($factsHash, $fired, $age, $who->sex, $this->config->openAiModel, GuidelineTriggers::indexVersion(), $assembled->activeProblemTitles());
        $cache = ($this->cacheFor)($pid, $factsHash);
        $hit = $cache->get($key);
        if ($hit !== null) {
            try {
                return new GuidelineOutcome(GuidelineSection::fromArray($hit->data), GuidelineStatus::Built, true);
            } catch (\RuntimeException $e) {
                // A stale or malformed cached section (fromArray throws RuntimeException): rebuild it.
                $this->logger->warning('copilot guideline cache entry unreadable; rebuilding', ['exception_class' => $e::class]);
            }
        }
        // Every fired trigger carries its own fact lines plus the problem list for the
        // critic; the run-level list is the union, for an older sidecar.
        $lines = [];
        foreach ($fired as $trigger) {
            foreach ($trigger->contextLines as $line) {
                $lines[] = $line;
            }
        }
        try {
            $run = $this->sidecar->brief($correlationId, $factsHash, $fired, array_values(array_unique($lines)), $age, $who->sex);
        } catch (SidecarException $e) {
            $this->logger->warning('copilot guideline evidence unavailable; briefing without it', ['code' => $e->errorCode]);
            return GuidelineOutcome::unavailable();
        }
        $section = GuidelineSection::fromRun($fired, $run, new GuidelineManifest());
        // A run that did not finish (a worker failed, a verdict unknown) is shown but
        // not cached, so the next open tries again instead of keeping the gap.
        if ($section->cacheable) {
            $cache->put($key, $section->toArray());
        }
        return new GuidelineOutcome(
            $section,
            $section->cacheable ? GuidelineStatus::Built : GuidelineStatus::Partial,
            false,
            array_map(static fn($h) => $h->toArray(), $run->handoffs),
            Pricing::fromConfig($this->config)->priceUsage($run->usage, $this->config->openAiModel),
        );
    }
}
