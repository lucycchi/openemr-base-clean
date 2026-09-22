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

use OpenEMR\Modules\ClinicalCopilot\Documents\Citation;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

/**
 * Turns raw chart records into the FactSet the model is shown. This is the
 * clinical logic of the feature, and it is entirely deterministic:
 *   - enforce ACL (throws AccessDeniedException; hides sensitive encounters),
 *   - find the "prior visit" and use its date as the "since" boundary,
 *   - classify each medication/allergy/problem as new or pre-existing,
 *   - flag abnormal labs (ReferenceRanges) and lab-to-lab deltas,
 *   - flag allergy/medication name matches,
 *   - cap each category and add a "N more not shown" fact when it overflows.
 * The clock is injected so "today" is controllable (the pre-warm pins it to
 * midnight; tests pin it to a fixed date).
 */
final class FactAssembler
{
    public function __construct(
        private readonly ChartSource $chart,
        private readonly Authorization $auth,
        private readonly ClockInterface $clock,
        private readonly ReferenceRanges $ranges = new ReferenceRanges(),
    ) {
    }

    /** @param ?int $currentEncounterId  The encounter the clinician has selected, if any; shifts the history boundary to that day. */
    public function assemble(PatientId $pid, ?int $currentEncounterId): AssembledFacts
    {
        // Coarse gate first: no medical-record or encounter-note permission -> nothing at all.
        foreach ([['patients', 'med'], ['encounters', 'notes']] as [$section, $value]) {
            if (!$this->auth->canView($section, $value)) {
                throw new AccessDeniedException("Not authorized: $section/$value");
            }
        }

        // Fine-grained gate: encounters tagged with a sensitivity level the
        // user is not cleared for are dropped, and remembered so their labs
        // are dropped too (a lab result would otherwise leak the visit).
        $hiddenEncounterIds = [];
        $encounters = [];
        foreach ($this->chart->encounters($pid) as $e) {
            if ($e->sensitivity !== '' && !$this->auth->canView('sensitivities', $e->sensitivity)) {
                $hiddenEncounterIds[$e->id] = true;
                continue;
            }
            $encounters[] = $e;
        }
        // Newest first. `<=>` on arrays compares element by element, so ties
        // on date break on id — a deterministic order matters for the hash.
        usort($encounters, fn(EncounterRecord $a, EncounterRecord $b) => [$b->date, $b->id] <=> [$a->date, $a->id]);

        // The briefing is history only: encounters on or after the day being
        // prepared for (today's check-in, or the selected encounter's day) are
        // the visit itself, not history. Dropping them keeps the facts hash
        // stable through check-in so a pre-warmed briefing still matches.
        $boundary = $this->historyBoundary($encounters, $currentEncounterId);
        $encounters = array_values(array_filter(
            $encounters,
            static fn(EncounterRecord $e) => [$e->date, $e->id] < $boundary
        ));
        // The prior visit is the newest remaining encounter; its date is the
        // "since" boundary that decides what counts as new below.
        $prior = $encounters[0] ?? null;

        $facts = [];
        if ($prior !== null) {
            $facts[] = $this->fact('EncounterService', $prior->id, 'date', $prior->date->format('Y-m-d') . ': ' . $prior->reason, FactCategory::PriorVisit);
        }

        $since = $prior?->date;

        $activeMeds = array_values(array_filter(
            $this->chart->medications($pid),
            fn(MedicationRecord $m) => $m->active
        ));
        foreach ($activeMeds as $m) {
            $facts[] = $this->fact('PrescriptionService', $m->id, 'drug', $this->dated($m->drug, 'started', $m->startDate, $m->startDateProvenance), $this->isNew($m->startDate, $since)
                ? FactCategory::MedicationNew
                : FactCategory::MedicationActive);
        }

        // Every allergy becomes a fact; new ones since the prior visit are flagged.
        $allergies = $this->chart->allergies($pid);
        foreach ($allergies as $a) {
            $facts[] = $this->fact('AllergyIntoleranceService', $a->id, 'title', $this->dated($a->title, 'onset', $a->beginDate, $a->beginDateProvenance), $this->isNew($a->beginDate, $since)
                ? FactCategory::AllergyNew
                : FactCategory::AllergyActive);
        }

        // Cross-check: any active drug whose name contains the allergy's
        // first word becomes a must-surface AllergyMedicationHit fact.
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
        // Only labs since the prior visit produce facts, but older labs stay
        // in the sorted list so previousResult() can find the value to diff against.
        foreach ($labs as $i => $l) {
            // Week 2: a result read from an uploaded document is new information
            // even for a patient with no prior visit to compare against.
            if (!$this->isNew($l->date, $since) && !($l->citation !== null && $since === null)) {
                continue;
            }
            // Abnormal: outside the reference range for a LOINC we know.
            $range = $l->unitMismatch ? null : $this->ranges->for($l->loinc);
            if ($range !== null && ($l->value < $range[0] || $l->value > $range[1])) {
                $direction = $l->value < $range[0] ? 'below' : 'above';
                $facts[] = $this->fact(
                    'ObservationLabService',
                    $l->id,
                    'result',
                    sprintf('%s %s %s on %s (%s reference range %s-%s %s)', $l->name, $this->num($l->value), $l->units, $l->date->format('Y-m-d'), $direction, $this->num($range[0]), $this->num($range[1]), $range[2]),
                    FactCategory::LabAbnormal,
                    $l->citation,
                );
            }
            // Delta: same test, earlier result with a different value.
            $previous = $this->previousResult($labs, $i);
            if ($previous !== null && $previous->value !== $l->value) {
                $diff = $l->value - $previous->value;
                $facts[] = $this->fact(
                    'ObservationLabService',
                    $l->id,
                    'delta',
                    sprintf('%s changed from %s %s (%s) to %s %s (%s): %s %s', $l->name, $this->num($previous->value), $previous->units, $previous->date->format('Y-m-d'), $this->num($l->value), $l->units, $l->date->format('Y-m-d'), $diff > 0 ? 'up' : 'down', $this->num(abs($diff))),
                    FactCategory::LabDelta,
                    $l->citation,
                );
            }
        }

        foreach ($this->chart->problems($pid) as $p) {
            if ($this->isNew($p->beginDate, $since)) {
                $facts[] = $this->fact('ConditionService', $p->id, 'title', $p->title, FactCategory::ProblemNew);
            }
        }

        // Week 2: intake-form entries and document-vs-chart mismatches since the prior visit.
        foreach ($this->chart->intakeRecords($pid) as $r) {
            $category = $r->category();
            if ($category !== null && ($since === null || $this->isNew($r->uploadedAt, $since))) {
                $facts[] = $this->fact('IntakeForm', $r->id, $r->kind, $r->describe(), $category, $r->citation);
            }
        }

        // Week 2: what the document extractor could not verify is shown, not hidden.
        foreach ($this->chart->unverifiedExtractions($pid) as $u) {
            if ($since === null || $this->isNew($u->uploadedAt, $since)) {
                $facts[] = $this->fact('DocumentExtraction', $u->id, $u->kind, $u->describe(), FactCategory::ExtractionUnverified, $u->citation);
            }
        }

        return new AssembledFacts(new FactSet($this->capPerCategory($facts)), $prior);
    }

    private const CAP_PER_CATEGORY = 50;

    /**
     * Rule 9A: bound each category so prompt size is predictable, and say so
     * with a fact the omission guard will force onto the screen.
     *
     * @param list<Fact> $facts
     * @return list<Fact>
     */
    private function capPerCategory(array $facts): array
    {
        $seen = [];
        $overflow = [];
        $kept = [];
        foreach ($facts as $fact) {
            $key = $fact->category->value;
            $seen[$key] = ($seen[$key] ?? 0) + 1;
            if ($seen[$key] > self::CAP_PER_CATEGORY) {
                $overflow[$key] = ($overflow[$key] ?? 0) + 1;
                continue;
            }
            $kept[] = $fact;
        }
        foreach ($overflow as $key => $count) {
            $label = str_replace('_', ' ', $key);
            $label = match (FactCategory::from($key)) {
                FactCategory::MedicationActive => 'active medications',
                FactCategory::MedicationNew => 'new medications',
                FactCategory::LabAbnormal => 'abnormal lab results',
                FactCategory::LabDelta => 'changed lab results',
                FactCategory::Encounter => 'encounters',
                FactCategory::PriorVisit => 'prior visits',
                FactCategory::AllergyActive => 'allergies',
                FactCategory::AllergyNew => 'new allergies',
                FactCategory::ProblemNew => 'new problems',
                FactCategory::AllergyMedicationHit => 'allergy/medication matches',
                FactCategory::MedicationChanged => 'changed medications',
                FactCategory::Truncation => $label,
                FactCategory::ExtractionUnverified => 'unverified document values',
                FactCategory::IntakeChiefConcern => 'intake reasons for visit',
                FactCategory::IntakeMedication => 'intake medications',
                FactCategory::IntakeAllergy => 'intake allergies',
                FactCategory::IntakeFamilyHistory => 'intake family history lines',
                FactCategory::DocumentMismatch => 'document mismatches',
            };
            $kept[] = $this->fact('FactAssembler', 0, "truncated:$key", "$count additional $label not shown", FactCategory::Truncation);
        }
        return $kept;
    }

    /** The next-older result with the same LOINC code, or null. @param list<LabRecord> $labs sorted newest first */
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

    /** 7.90 -> "7.9", 150.00 -> "150": trailing zeros would otherwise become literals the Verifier must match. */
    private function num(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }

    private function fact(string $service, int $recordId, string $field, string $value, FactCategory $category, ?Citation $citation = null): Fact
    {
        return new Fact(Fact::idFor($service, $recordId, $field), $service, $recordId, $field, $value, $category, $citation);
    }

    // The date is part of the fact value so the model can cite it and the
    // physician can see it. When the clinician never recorded a start/onset
    // date, the record's own entry date is used and labelled as such rather
    // than presented as clinical truth; with neither, no date is shown.
    private function dated(string $name, string $recordedLabel, DateTimeImmutable $date, DateProvenance $provenance): string
    {
        return match ($provenance) {
            DateProvenance::Recorded => sprintf('%s (%s %s)', $name, $recordedLabel, $date->format('Y-m-d')),
            DateProvenance::FirstNoted => sprintf('%s (first noted %s)', $name, $date->format('Y-m-d')),
            DateProvenance::Unknown => $name,
        };
    }

    /** "New" = strictly after the prior visit. With no prior visit nothing is new (it is all baseline). */
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
     * Rule 6A: history ends at the start of the day being prepared for. With a
     * selected encounter that is the start of its day; otherwise the start of
     * today. Everything at or after the boundary is the visit itself, and the
     * prior visit is the latest encounter strictly before it.
     *
     * @param list<EncounterRecord> $encounters sorted newest first
     * @return array{DateTimeImmutable, int}
     */
    private function historyBoundary(array $encounters, ?int $currentEncounterId): array
    {
        $day = $this->clock->now();
        if ($currentEncounterId !== null) {
            foreach ($encounters as $e) {
                if ($e->id === $currentEncounterId) {
                    $day = $e->date;
                    break;
                }
            }
        }
        return [$day->setTime(0, 0), 0];
    }
}
