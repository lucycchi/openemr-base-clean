<?php

/**
 * Builds the deterministic fact set for one patient briefing.
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
use Psr\Clock\ClockInterface;

final class FactAssembler
{
    public function __construct(
        private readonly ChartSource $chart,
        private readonly Authorization $auth,
        private readonly ClockInterface $clock,
        private readonly ReferenceRanges $ranges = new ReferenceRanges(),
    ) {
    }

    public function assemble(PatientId $pid, ?int $currentEncounterId): AssembledFacts
    {
        foreach ([['patients', 'med'], ['encounters', 'notes']] as [$section, $value]) {
            if (!$this->auth->canView($section, $value)) {
                throw new AccessDeniedException("Not authorized: $section/$value");
            }
        }

        $hiddenEncounterIds = [];
        $encounters = [];
        foreach ($this->chart->encounters($pid) as $e) {
            if ($e->sensitivity !== '' && !$this->auth->canView('sensitivities', $e->sensitivity)) {
                $hiddenEncounterIds[$e->id] = true;
                continue;
            }
            $encounters[] = $e;
        }
        usort($encounters, fn(EncounterRecord $a, EncounterRecord $b) => [$b->date, $b->id] <=> [$a->date, $a->id]);

        $prior = $this->priorEncounter($encounters, $currentEncounterId);

        $facts = [];
        foreach ($encounters as $e) {
            if ($prior !== null && [$e->date, $e->id] <= [$prior->date, $prior->id]) {
                continue;
            }
            $facts[] = $this->fact('EncounterService', $e->id, 'reason', $e->date->format('Y-m-d') . ': ' . $e->reason, FactCategory::Encounter);
        }

        $since = $prior?->date;

        $activeMeds = array_values(array_filter(
            $this->chart->medications($pid),
            fn(MedicationRecord $m) => $m->active
        ));
        foreach ($activeMeds as $m) {
            $facts[] = $this->fact('PrescriptionService', $m->id, 'drug', $m->drug, $this->isNew($m->startDate, $since)
                ? FactCategory::MedicationNew
                : FactCategory::MedicationActive);
        }

        $allergies = $this->chart->allergies($pid);
        foreach ($allergies as $a) {
            $facts[] = $this->fact('AllergyIntoleranceService', $a->id, 'title', $a->title, $this->isNew($a->beginDate, $since)
                ? FactCategory::AllergyNew
                : FactCategory::AllergyActive);
        }

        foreach ($allergies as $a) {
            foreach ($activeMeds as $m) {
                if ($this->allergyMatchesDrug($a->title, $m->drug)) {
                    $facts[] = $this->fact(
                        'AllergyIntoleranceService',
                        $a->id,
                        'medication_hit:' . $m->id,
                        "Documented allergy '{$a->title}' matches active medication '{$m->drug}'",
                        FactCategory::AllergyMedicationHit,
                    );
                }
            }
        }

        $labs = array_values(array_filter(
            $this->chart->labs($pid),
            fn(LabRecord $l) => !isset($hiddenEncounterIds[$l->encounterId])
        ));
        usort($labs, fn(LabRecord $a, LabRecord $b) => [$b->date, $b->id] <=> [$a->date, $a->id]);
        foreach ($labs as $i => $l) {
            if (!$this->isNew($l->date, $since)) {
                continue;
            }
            $range = $this->ranges->for($l->loinc);
            if ($range !== null && ($l->value < $range[0] || $l->value > $range[1])) {
                $direction = $l->value < $range[0] ? 'below' : 'above';
                $facts[] = $this->fact(
                    'ObservationLabService',
                    $l->id,
                    'result',
                    sprintf('%s %s %s on %s (%s reference range %s-%s %s)', $l->name, $this->num($l->value), $l->units, $l->date->format('Y-m-d'), $direction, $this->num($range[0]), $this->num($range[1]), $range[2]),
                    FactCategory::LabAbnormal,
                );
            }
            $previous = $this->previousResult($labs, $i);
            if ($previous !== null && $previous->value !== $l->value) {
                $diff = $l->value - $previous->value;
                $facts[] = $this->fact(
                    'ObservationLabService',
                    $l->id,
                    'delta',
                    sprintf('%s changed from %s %s (%s) to %s %s (%s): %s %s', $l->name, $this->num($previous->value), $previous->units, $previous->date->format('Y-m-d'), $this->num($l->value), $l->units, $l->date->format('Y-m-d'), $diff > 0 ? 'up' : 'down', $this->num(abs($diff))),
                    FactCategory::LabDelta,
                );
            }
        }

        return new AssembledFacts(new FactSet($facts), $prior);
    }

    /** @param list<LabRecord> $labs sorted newest first */
    private function previousResult(array $labs, int $index): ?LabRecord
    {
        $current = $labs[$index];
        for ($j = $index + 1, $n = count($labs); $j < $n; $j++) {
            if ($labs[$j]->loinc === $current->loinc) {
                return $labs[$j];
            }
        }
        return null;
    }

    private function num(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }

    private function fact(string $service, int $recordId, string $field, string $value, FactCategory $category): Fact
    {
        return new Fact(Fact::idFor($service, $recordId, $field), $service, $recordId, $field, $value, $category);
    }

    private function isNew(DateTimeImmutable $date, ?DateTimeImmutable $since): bool
    {
        return $since !== null && $date > $since;
    }

    // v1 rule (D18): first word of the allergy title, case-insensitive, as a
    // substring of the drug name. Coarse by design; an RxNorm mapping is a TODO.
    private function allergyMatchesDrug(string $allergyTitle, string $drug): bool
    {
        $needle = strtolower(strtok(trim($allergyTitle), ' ') ?: '');
        return $needle !== '' && str_contains(strtolower($drug), $needle);
    }

    /**
     * Rule 6A: the latest encounter strictly before the current one (by date,
     * then id); with no current encounter, the latest before now.
     *
     * @param list<EncounterRecord> $encounters sorted newest first
     */
    private function priorEncounter(array $encounters, ?int $currentEncounterId): ?EncounterRecord
    {
        $boundary = null;
        if ($currentEncounterId !== null) {
            foreach ($encounters as $e) {
                if ($e->id === $currentEncounterId) {
                    $boundary = [$e->date, $e->id];
                    break;
                }
            }
        }
        // No current encounter: anything dated today is today's visit, so the
        // prior visit is the latest one before the start of today.
        $boundary ??= [$this->clock->now()->setTime(0, 0), 0];

        foreach ($encounters as $e) {
            if ([$e->date, $e->id] < $boundary) {
                return $e;
            }
        }
        return null;
    }
}
