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
use OpenEMR\Modules\ClinicalCopilot\Documents\Citation;
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
        private readonly LabJudge $judge = new LabJudge(),
        private readonly VitalThresholds $vitalThresholds = new VitalThresholds(),
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

        $medications = $this->chart->medications($pid);
        $activeMeds = array_values(array_filter($medications, static fn(MedicationRecord $m) => $m->active && $m->stoppedOn() === null));
        // Stopped since the prior visit: an end date after it, or an inactive row changed after it.
        $stoppedMeds = array_values(array_filter($medications, fn(MedicationRecord $m) => !$m->active && $m->stoppedOn() !== null && $this->isNew($m->stoppedOn(), $since)));
        // A stop and a start of the same drug across the boundary is one change, not two facts.
        $changedPairs = [];
        foreach ($stoppedMeds as $old) {
            foreach ($activeMeds as $new) {
                if ($new->nameKey() === $old->nameKey() && $this->isNew($new->startDate, $since) && !isset($changedPairs[$new->id])) {
                    $changedPairs[$new->id] = $old;
                    break;
                }
            }
        }
        $replaced = array_map(static fn(MedicationRecord $old): int => $old->id, $changedPairs);
        foreach ($activeMeds as $m) {
            if (isset($changedPairs[$m->id])) {
                $old = $changedPairs[$m->id];
                $facts[] = $this->fact('PrescriptionService', $m->id, 'changed', sprintf('%s changed from %s to %s on %s', ucfirst(strtok(trim($m->drug), ' ') ?: $m->drug), $old->describeDose(), $m->describeDose(), $m->startDate->format('Y-m-d')), FactCategory::MedicationChanged, null, ['drug' => $m->drug]);
                continue;
            }
            $facts[] = $this->fact('PrescriptionService', $m->id, 'drug', $this->dated($m->drug, 'started', $m->startDate, $m->startDateProvenance), $this->isNew($m->startDate, $since)
                ? FactCategory::MedicationNew
                : FactCategory::MedicationActive, null, ['drug' => $m->drug]);
        }
        foreach ($stoppedMeds as $m) {
            if (in_array($m->id, $replaced, true)) {
                continue;
            }
            $stoppedOn = $m->stoppedOn();
            $started = match ($m->startDateProvenance) {
                DateProvenance::Recorded => ' (started ' . $m->startDate->format('Y-m-d') . ')',
                DateProvenance::FirstNoted => ' (first noted ' . $m->startDate->format('Y-m-d') . ')',
                DateProvenance::Unknown => '',
            };
            $facts[] = $this->fact('PrescriptionService', $m->id, 'stopped', sprintf('%s stopped %s%s', $m->drug, $stoppedOn?->format('Y-m-d') ?? '', $started), FactCategory::MedicationStopped, null, ['drug' => $m->drug]);
        }

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

        $who = $this->chart->demographics($pid);
        $sex = $who->sex;
        $labs = array_values(array_filter(
            $this->chart->labs($pid),
            fn(LabRecord $l) => !isset($hiddenEncounterIds[$l->encounterId])
        ));
        usort($labs, fn(LabRecord $a, LabRecord $b) => [$b->date, $b->id] <=> [$a->date, $a->id]);
        foreach ($labs as $i => $l) {
            // Only what is new since the prior visit; on a first visit, document-cited results still count.
            if (!$this->isNew($l->date, $since) && !($l->citation !== null && $since === null)) {
                continue;
            }
            $judged = $this->judge->judge($l, $sex);
            $day = $l->date->format('Y-m-d');
            $reading = $l->value === null
                ? trim(sprintf('%s: %s %s', $l->name, trim((string) $l->text), $l->units))
                : trim(sprintf('%s %s %s', $l->name, $this->num($l->value), $l->units));
            $text = $judged->clause === '' ? "$reading on $day" : "$reading on $day ({$judged->clause})";
            $category = match ($judged->verdict) {
                LabVerdict::Critical => FactCategory::LabCritical,
                LabVerdict::Abnormal => FactCategory::LabAbnormal,
                LabVerdict::Normal => FactCategory::LabNormal,
                LabVerdict::Unranged => null,
            };
            if ($category !== null) {
                $attributes = $l->loinc === '' ? [] : ['loinc' => $l->loinc];
                if ($judged->direction !== null) {
                    $attributes['direction'] = $judged->direction;
                }
                $facts[] = $this->fact('ObservationLabService', $l->id, 'result', $text, $category, $l->citation, $attributes);
            }
            if ($l->value === null || $l->loinc === '') {
                continue;
            }
            $previous = $this->previousResult($labs, $i);
            if ($previous !== null && $previous->value !== null && $previous->value !== $l->value) {
                $diff = $l->value - $previous->value;
                $delta = sprintf('%s changed from %s %s (%s) to %s %s (%s): %s %s', $l->name, $this->num($previous->value), $previous->units, $previous->date->format('Y-m-d'), $this->num($l->value), $l->units, $day, $diff > 0 ? 'up' : 'down', $this->num(abs($diff)));
                $facts[] = $this->fact(
                    'ObservationLabService',
                    $l->id,
                    'delta',
                    $judged->rangeClause === '' ? $delta : "$delta ({$judged->rangeClause})",
                    FactCategory::LabDelta,
                    $l->citation,
                );
            }
        }

        $activeProblemTitles = [];
        foreach ($this->chart->problems($pid) as $p) {
            if ($p->isActive()) {
                $activeProblemTitles[] = $p->title;
                if ($this->isNew($p->beginDate, $since)) {
                    $facts[] = $this->fact('ConditionService', $p->id, 'title', $p->title, FactCategory::ProblemNew, null, ['title' => $p->title]);
                }
                continue;
            }
            $resolvedOn = $p->resolvedOn();
            if ($resolvedOn !== null && $this->isNew($resolvedOn, $since)) {
                $facts[] = $this->fact('ConditionService', $p->id, 'resolved', sprintf('%s resolved %s', $p->title, $resolvedOn->format('Y-m-d')), FactCategory::ProblemResolved, null, ['title' => $p->title]);
            }
        }

        // Vital signs new since the prior visit: outside an adult threshold is a
        // must-surface fact; a change past a noise threshold versus the most
        // recent prior reading is a delta fact. No abnormal facts for a child.
        $age = $who->ageOn($this->clock->now());
        $vitals = array_values(array_filter($this->chart->vitals($pid), fn(VitalRecord $v) => !isset($hiddenEncounterIds[$v->encounterId])));
        usort($vitals, fn(VitalRecord $a, VitalRecord $b) => [$b->date, $b->id] <=> [$a->date, $a->id]);
        foreach ($vitals as $i => $v) {
            if (!$this->isNew($v->date, $since)) {
                continue;
            }
            $day = $v->date->format('Y-m-d');
            if ($age === null || $age >= 18) {
                foreach ($this->abnormalVitals($v, $day) as [$field, $text, $direction]) {
                    $facts[] = $this->fact('VitalsService', $v->id, $field, $text, FactCategory::VitalAbnormal, null, ['vital' => $field, 'direction' => $direction]);
                }
            }
            foreach ($this->vitalDeltas($v, $vitals, $i, $day) as [$field, $text]) {
                $facts[] = $this->fact('VitalsService', $v->id, $field . '_delta', $text, FactCategory::VitalDelta, null, ['vital' => $field]);
            }
        }

        // Orders placed since the prior visit that still have no report.
        foreach ($this->chart->pendingOrders($pid) as $o) {
            if ($this->isNew($o->orderedOn, $since)) {
                $facts[] = $this->fact('ProcedureOrderService', $o->id, 'pending', sprintf('%s ordered %s, no result on file', $o->name, $o->orderedOn->format('Y-m-d')), FactCategory::LabPending);
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

        return new AssembledFacts(new FactSet($this->capPerCategory($facts)), $prior, $activeProblemTitles);
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
                FactCategory::LabCritical => 'critical lab values',
                FactCategory::LabAbnormal => 'abnormal lab results',
                FactCategory::LabNormal => 'normal lab results',
                FactCategory::LabDelta => 'changed lab results',
                FactCategory::Encounter => 'encounters',
                FactCategory::PriorVisit => 'prior visits',
                FactCategory::AllergyActive => 'allergies',
                FactCategory::AllergyNew => 'new allergies',
                FactCategory::ProblemNew => 'new problems',
                FactCategory::AllergyMedicationHit => 'allergy/medication matches',
                FactCategory::MedicationChanged => 'changed medications',
                FactCategory::MedicationStopped => 'stopped medications',
                FactCategory::ProblemResolved => 'resolved problems',
                FactCategory::LabPending => 'labs ordered without a result',
                FactCategory::VitalAbnormal => 'abnormal vital signs',
                FactCategory::VitalDelta => 'changed vital signs',
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

    /**
     * Every reading in this set outside the adult bounds: [field, fact text, direction].
     *
     * @return list<array{string, string, string}>
     */
    private function abnormalVitals(VitalRecord $v, string $day): array
    {
        $out = [];
        $bp = $this->vitalThresholds->abnormal('bp');
        if ($v->systolic !== null && $v->diastolic !== null && isset($bp['systolic_high'], $bp['diastolic_high']) && ($v->systolic >= $bp['systolic_high'] || $v->diastolic >= $bp['diastolic_high'])) {
            $out[] = ['bp', sprintf('Blood pressure %d/%d mmHg on %s (above %s/%s)', $v->systolic, $v->diastolic, $day, $this->num($bp['systolic_high']), $this->num($bp['diastolic_high'])), 'above'];
        }
        $pulse = $this->vitalThresholds->abnormal('pulse');
        if ($v->pulse !== null && isset($pulse['low']) && $v->pulse < $pulse['low']) {
            $out[] = ['pulse', sprintf('Pulse %s bpm on %s (below %s bpm)', $this->num($v->pulse), $day, $this->num($pulse['low'])), 'below'];
        } elseif ($v->pulse !== null && isset($pulse['high']) && $v->pulse > $pulse['high']) {
            $out[] = ['pulse', sprintf('Pulse %s bpm on %s (above %s bpm)', $this->num($v->pulse), $day, $this->num($pulse['high'])), 'above'];
        }
        $spo2 = $this->vitalThresholds->abnormal('spo2');
        if ($v->spo2 !== null && isset($spo2['low']) && $v->spo2 < $spo2['low']) {
            $out[] = ['spo2', sprintf('Oxygen saturation %s %% on %s (below %s %%)', $this->num($v->spo2), $day, $this->num($spo2['low'])), 'below'];
        }
        $temp = $this->vitalThresholds->abnormal('temperature_f');
        if ($v->temperatureF !== null && isset($temp['high']) && $v->temperatureF >= $temp['high']) {
            $out[] = ['temperature', sprintf('Temperature %s F on %s (at or above %s F)', $this->num($v->temperatureF), $day, $this->num($temp['high'])), 'above'];
        }
        $resp = $this->vitalThresholds->abnormal('respiration');
        if ($v->respiration !== null && isset($resp['high']) && $v->respiration > $resp['high']) {
            $out[] = ['respiration', sprintf('Respiration %s per minute on %s (above %s per minute)', $this->num($v->respiration), $day, $this->num($resp['high'])), 'above'];
        }
        $bmi = $this->vitalThresholds->abnormal('bmi');
        if ($v->bmi !== null && isset($bmi['low']) && $v->bmi < $bmi['low']) {
            $out[] = ['bmi', sprintf('BMI %s on %s (below %s)', $this->num($v->bmi), $day, $this->num($bmi['low'])), 'below'];
        } elseif ($v->bmi !== null && isset($bmi['high']) && $v->bmi >= $bmi['high']) {
            $out[] = ['bmi', sprintf('BMI %s on %s (at or above %s)', $this->num($v->bmi), $day, $this->num($bmi['high'])), 'above'];
        }
        return $out;
    }

    /**
     * Changes versus the most recent earlier reading that measured the same
     * vital, past the noise thresholds: [field, fact text].
     *
     * @param list<VitalRecord> $vitals newest first
     * @return list<array{string, string}>
     */
    private function vitalDeltas(VitalRecord $v, array $vitals, int $index, string $day): array
    {
        $out = [];
        $earlier = static function (callable $get) use ($vitals, $index): ?VitalRecord {
            for ($j = $index + 1, $n = count($vitals); $j < $n; $j++) {
                if ($get($vitals[$j]) !== null) {
                    return $vitals[$j];
                }
            }
            return null;
        };
        $weight = $this->vitalThresholds->delta('weight_lb');
        $prev = $v->weightLb === null ? null : $earlier(static fn(VitalRecord $r) => $r->weightLb);
        if ($prev !== null && $prev->weightLb !== null) {
            $diff = $v->weightLb - $prev->weightLb;
            $pct = $prev->weightLb > 0 ? abs($diff) / $prev->weightLb * 100 : 0.0;
            if (abs($diff) >= ($weight['absolute'] ?? PHP_FLOAT_MAX) || $pct >= ($weight['percent'] ?? PHP_FLOAT_MAX)) {
                $out[] = ['weight', sprintf('Weight changed from %s lb (%s) to %s lb (%s): %s %s lb (%d %%)', $this->num($prev->weightLb), $prev->date->format('Y-m-d'), $this->num($v->weightLb), $day, $diff > 0 ? 'up' : 'down', $this->num(abs($diff)), (int) round($pct))];
            }
        }
        $systolic = $this->vitalThresholds->delta('systolic');
        $prev = $v->systolic === null ? null : $earlier(static fn(VitalRecord $r) => $r->systolic);
        if ($prev !== null && $prev->systolic !== null && abs($v->systolic - $prev->systolic) >= ($systolic['absolute'] ?? PHP_FLOAT_MAX)) {
            $diff = $v->systolic - $prev->systolic;
            $out[] = ['bp', sprintf('Systolic blood pressure changed from %d (%s) to %d (%s): %s %d', $prev->systolic, $prev->date->format('Y-m-d'), $v->systolic, $day, $diff > 0 ? 'up' : 'down', abs($diff))];
        }
        $bmi = $this->vitalThresholds->delta('bmi');
        $prev = $v->bmi === null ? null : $earlier(static fn(VitalRecord $r) => $r->bmi);
        if ($prev !== null && $prev->bmi !== null && abs($v->bmi - $prev->bmi) >= ($bmi['absolute'] ?? PHP_FLOAT_MAX)) {
            $diff = $v->bmi - $prev->bmi;
            $out[] = ['bmi', sprintf('BMI changed from %s (%s) to %s (%s): %s %s', $this->num($prev->bmi), $prev->date->format('Y-m-d'), $this->num($v->bmi), $day, $diff > 0 ? 'up' : 'down', $this->num(abs($diff)))];
        }
        return $out;
    }

    /**
     * The next-older result with the same LOINC code, or null.
     *
     * @param list<LabRecord> $labs sorted newest first
     */
    private function previousResult(array $labs, int $index): ?LabRecord
    {
        $current = $labs[$index];
        for ($j = $index + 1, $n = count($labs); $j < $n; $j++) {
            if ($labs[$j]->loinc === $current->loinc && $labs[$j]->value !== null) {
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

    /** @param array<string, string> $attributes */
    private function fact(string $service, int $recordId, string $field, string $value, FactCategory $category, ?Citation $citation = null, array $attributes = []): Fact
    {
        return new Fact(Fact::idFor($service, $recordId, $field), $service, $recordId, $field, $value, $category, $citation, $attributes);
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
