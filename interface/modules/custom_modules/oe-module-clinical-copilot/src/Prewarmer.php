<?php

/**
 * Pre-warms the briefing cache for every patient on a day's schedule.
 *
 * Each appointment is assembled as the scheduled provider (their ACL decides
 * which sensitive encounters are visible) with the clock pinned to the start
 * of that day, so the facts hash equals what the provider's own chart open
 * computes, and the narration the pipeline caches now is the one served then.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot;

use Closure;
use DateTimeImmutable;
use DateTimeZone;

final readonly class Prewarmer
{
    /** @param Closure(string): Authorization $authorizationFor builds the ACL view for a username */
    public function __construct(
        private ScheduleSource $schedule,
        private ChartSource $chart,
        private Closure $authorizationFor,
        private BriefingNarrator $narrator,
        private DateTimeZone $tz,
    ) {
    }

    public function run(DateTimeImmutable $day, ?int $onlyPid, bool $dryRun): PrewarmSummary
    {
        $clock = FixedClock::startOfDay($day->format('Y-m-d'), $this->tz);
        $rows = [];
        foreach ($this->select($day, $onlyPid) as $appointment) {
            $rows[] = $dryRun
                ? new PrewarmRow($appointment, PrewarmStatus::Skipped, null, CorrelationId::generate(), 0, false)
                : $this->warm($appointment, $clock);
        }
        return new PrewarmSummary($rows);
    }

    /**
     * One row per (patient, provider): a patient with two slots for the same
     * provider needs one briefing, and two providers may see different facts.
     *
     * @return list<ScheduledAppointment>
     */
    private function select(DateTimeImmutable $day, ?int $onlyPid): array
    {
        $seen = [];
        $selected = [];
        foreach ($this->schedule->appointmentsOn($day) as $a) {
            if ($onlyPid !== null && $a->pid->value !== $onlyPid) {
                continue;
            }
            $key = $a->pid->value . '|' . $a->providerUsername;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $selected[] = $a;
        }
        return $selected;
    }

    private function warm(ScheduledAppointment $appointment, FixedClock $clock): PrewarmRow
    {
        $correlationId = CorrelationId::generate();
        $started = hrtime(true);
        $factsHash = null;
        try {
            $assembler = new FactAssembler($this->chart, ($this->authorizationFor)($appointment->providerUsername), $clock);
            $assembled = $assembler->assemble($appointment->pid, null);
            $factsHash = $assembled->facts()->hash();
            $result = $this->narrator->brief($assembled, $appointment->pid, $correlationId);
            $status = $result->fromCache ? PrewarmStatus::AlreadyCached : PrewarmStatus::Warmed;
            return new PrewarmRow($appointment, $status, $factsHash, $correlationId, $this->elapsedMs($started), !$result->fromCache);
        } catch (\RuntimeException | \LogicException $e) {
            // One patient's upstream, data or ACL failure (LlmException,
            // SqlQueryException, AccessDeniedException, Guzzle transport errors
            // are all RuntimeException) must not stop the sweep. A PHP \Error
            // or ErrorException is a bug and propagates so the run fails loudly.
            return new PrewarmRow($appointment, PrewarmStatus::Error, $factsHash, $correlationId, $this->elapsedMs($started), false, $e->getMessage());
        }
    }

    private function elapsedMs(int $startedNs): int
    {
        return (int) round((hrtime(true) - $startedNs) / 1e6);
    }
}
