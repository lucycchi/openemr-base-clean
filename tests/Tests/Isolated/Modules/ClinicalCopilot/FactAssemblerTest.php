<?php

/**
 * FactAssembler builds the deterministic fact set for a briefing.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Modules\ClinicalCopilot;

use DateTimeImmutable;
use OpenEMR\Modules\ClinicalCopilot\AccessDeniedException;
use OpenEMR\Modules\ClinicalCopilot\AllergyRecord;
use OpenEMR\Modules\ClinicalCopilot\AssembledFacts;
use OpenEMR\Modules\ClinicalCopilot\DateProvenance;
use OpenEMR\Modules\ClinicalCopilot\Demographics;
use OpenEMR\Modules\ClinicalCopilot\Documents\BBox;
use OpenEMR\Modules\ClinicalCopilot\Documents\Citation;
use OpenEMR\Modules\ClinicalCopilot\EncounterRecord;
use OpenEMR\Modules\ClinicalCopilot\Fact;
use OpenEMR\Modules\ClinicalCopilot\FactAssembler;
use OpenEMR\Modules\ClinicalCopilot\FactCategory;
use OpenEMR\Modules\ClinicalCopilot\LabRecord;
use OpenEMR\Modules\ClinicalCopilot\MedicationRecord;
use OpenEMR\Modules\ClinicalCopilot\PatientId;
use OpenEMR\Modules\ClinicalCopilot\PendingOrderRecord;
use OpenEMR\Modules\ClinicalCopilot\ProblemRecord;
use OpenEMR\Modules\ClinicalCopilot\VitalRecord;
use OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Support\FakeAuthorization;
use OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Support\FakeChartSource;
use OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Support\FixedClock;
use OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Support\ModuleAutoload;
use PHPUnit\Framework\TestCase;

/**
 * FactAssembler with FakeChartSource / FakeAuthorization / a fixed clock.
 * The largest test in the suite because the assembler holds the clinical
 * rules. Grouped roughly as:
 *   - ACL: denied medication or encounter permission refuses before any
 *     chart read; a sensitive encounter (and its labs) is dropped.
 *   - Prior visit / history boundary: latest encounter strictly before the
 *     selected encounter's day (or today); same-day and today's encounters
 *     are not history; the hash is unchanged by check-in creating today's
 *     encounter or by whether it is selected (pre-warm correctness).
 *   - Medications / allergies: new vs active relative to the prior visit,
 *     inactive excluded, no prior visit means nothing is "new", date
 *     provenance wording (started / first noted / no date), and the
 *     allergy-drug name match producing a hit fact.
 *   - Labs: abnormal only with a known range and only since the prior
 *     visit; delta against the previous result of the same LOINC.
 *   - Problems, per-category cap with a truncation fact, and fact id /
 *     hash stability (ids derive from source row, not position).
 */
final class FactAssemblerTest extends TestCase
{
    /**
     * @codeCoverageIgnore Fixture wiring; runs before coverage attribution.
     */
    public static function setUpBeforeClass(): void
    {
        ModuleAutoload::register();
    }

    private FakeChartSource $chart;
    private FakeAuthorization $auth;
    private DateTimeImmutable $today;

    protected function setUp(): void
    {
        $this->chart = new FakeChartSource();
        $this->auth = new FakeAuthorization();
        $this->today = new DateTimeImmutable('2026-09-15 09:00:00');
    }

    private function assembler(): FactAssembler
    {
        return new FactAssembler($this->chart, $this->auth, new FixedClock($this->today));
    }

    public function testDeniedMedicationAclRefusesBeforeReadingTheChart(): void
    {
        $this->auth->deny('patients', 'med');

        try {
            $this->assembler()->assemble(new PatientId(7), null);
            self::fail('Expected AccessDeniedException');
        } catch (AccessDeniedException) {
            self::assertSame(0, $this->chart->reads);
        }
    }

    public function testDeniedEncounterAclRefuses(): void
    {
        $this->auth->deny('encounters', 'notes');

        $this->expectException(AccessDeniedException::class);
        $this->assembler()->assemble(new PatientId(7), null);
    }

    private function encounter(int $id, string $date, string $sensitivity = '', string $reason = 'Follow-up'): EncounterRecord
    {
        return new EncounterRecord($id, new DateTimeImmutable($date), $sensitivity, $reason);
    }

    public function testPriorEncounterIsLatestBeforeTheCurrentEncounter(): void
    {
        $this->chart->encounters = [
            $this->encounter(100, '2026-09-15 08:30:00'),
            $this->encounter(99, '2026-09-01 10:00:00'),
            $this->encounter(98, '2026-06-01 10:00:00'),
        ];

        $result = $this->assembler()->assemble(new PatientId(7), 100);

        self::assertSame(99, $result->priorEncounter()?->id);
    }

    public function testPriorEncounterIsLatestBeforeTodayWhenNoCurrentEncounter(): void
    {
        $this->chart->encounters = [
            $this->encounter(99, '2026-09-01 10:00:00'),
            $this->encounter(98, '2026-06-01 10:00:00'),
        ];

        $result = $this->assembler()->assemble(new PatientId(7), null);

        self::assertSame(99, $result->priorEncounter()?->id);
    }

    public function testAnEarlierEncounterOnTheSameDayIsNotThePriorVisit(): void
    {
        $this->chart->encounters = [
            $this->encounter(101, '2026-09-15 08:30:00'),
            $this->encounter(100, '2026-09-15 08:30:00'),
            $this->encounter(99, '2026-09-01 10:00:00'),
        ];

        $result = $this->assembler()->assemble(new PatientId(7), 101);

        self::assertSame(99, $result->priorEncounter()?->id);
    }

    public function testNoPriorEncounterWhenChartHasOnlyTheCurrentVisit(): void
    {
        $this->chart->encounters = [$this->encounter(100, '2026-09-15 08:30:00')];

        $result = $this->assembler()->assemble(new PatientId(7), 100);

        self::assertNull($result->priorEncounter());
    }

    public function testTodaysEncounterIsNotHistoryAndProducesNoFact(): void
    {
        $this->chart->encounters = [
            $this->encounter(100, '2026-09-15 08:30:00', '', 'Annual physical'),
            $this->encounter(99, '2026-09-08 10:00:00', '', 'Urgent visit'),
            $this->encounter(98, '2026-09-01 10:00:00', '', 'Follow-up'),
        ];

        $result = $this->assembler()->assemble(new PatientId(7), null);

        self::assertSame(99, $result->priorEncounter()?->id);
        self::assertSame([], $this->factsIn($result, FactCategory::Encounter));
        foreach ($result->facts()->all() as $fact) {
            self::assertStringNotContainsString('Annual physical', $fact->value);
        }
    }

    public function testFactsHashIsUnchangedWhenTodaysEncounterIsCreatedAtCheckIn(): void
    {
        $this->chart->encounters = [$this->encounter(99, '2026-09-01 10:00:00')];
        $this->chart->medications = [new MedicationRecord(17, 'Metformin 500 MG Oral Tablet', new DateTimeImmutable('2025-01-10'), true)];
        $beforeCheckIn = $this->assembler()->assemble(new PatientId(7), null)->facts()->hash();

        array_unshift($this->chart->encounters, $this->encounter(100, '2026-09-15 08:55:00', '', ''));
        $afterCheckIn = $this->assembler()->assemble(new PatientId(7), null)->facts()->hash();

        self::assertSame($beforeCheckIn, $afterCheckIn);
    }

    public function testFactsHashIsTheSameWhetherOrNotTodaysEncounterIsSelected(): void
    {
        $this->chart->encounters = [
            $this->encounter(101, '2026-09-15 10:15:00', '', 'Nurse visit'),
            $this->encounter(100, '2026-09-15 08:55:00', '', 'Annual physical'),
            $this->encounter(99, '2026-09-01 10:00:00'),
        ];
        $this->chart->medications = [new MedicationRecord(17, 'Metformin 500 MG Oral Tablet', new DateTimeImmutable('2025-01-10'), true)];

        $noneSelected = $this->assembler()->assemble(new PatientId(7), null)->facts()->hash();
        $todaySelected = $this->assembler()->assemble(new PatientId(7), 100)->facts()->hash();

        self::assertSame($noneSelected, $todaySelected);
    }

    public function testPriorVisitIsItselfACitableFact(): void
    {
        $this->chart->encounters = [
            $this->encounter(100, '2026-09-15 08:30:00', '', 'Annual physical'),
            $this->encounter(99, '2026-09-01 10:00:00', '', 'Follow-up'),
        ];

        $result = $this->assembler()->assemble(new PatientId(7), 100);

        $prior = $this->factsIn($result, FactCategory::PriorVisit);
        self::assertCount(1, $prior);
        self::assertSame('2026-09-01: Follow-up', $prior[0]->value);
        self::assertSame(99, $prior[0]->recordId);
        self::assertSame('date', $prior[0]->field);
        self::assertFalse($prior[0]->category->mustSurface());
    }

    public function testSensitiveEncounterTheUserMayNotSeeIsExcluded(): void
    {
        $this->auth->deny('sensitivities', 'high');
        $this->chart->encounters = [
            $this->encounter(100, '2026-09-15 08:30:00', 'high', 'Psychiatry'),
            $this->encounter(99, '2026-09-08 10:00:00', '', 'Urgent visit'),
            $this->encounter(98, '2026-09-01 10:00:00', 'high', 'Psychiatry'),
            $this->encounter(97, '2026-08-01 10:00:00', '', 'Follow-up'),
        ];

        $result = $this->assembler()->assemble(new PatientId(7), null);

        self::assertSame(99, $result->priorEncounter()?->id);
        foreach ($result->facts()->all() as $fact) {
            self::assertStringNotContainsString('Psychiatry', $fact->value);
        }
    }

    /** @return list<Fact> */
    private function factsIn(AssembledFacts $result, FactCategory $category): array
    {
        return array_values(array_filter(
            $result->facts()->all(),
            fn(Fact $f) => $f->category === $category
        ));
    }

    /**
     * Keyed by record id, so a test never depends on sort order.
     *
     * @param list<Fact> $facts
     * @return array<int, Fact>
     */
    private function byRecordId(array $facts): array
    {
        $out = [];
        foreach ($facts as $f) {
            $out[$f->recordId] = $f;
        }
        return $out;
    }

    private function withPriorVisitOn(string $date): void
    {
        $this->chart->encounters = [$this->encounter(99, $date)];
    }

    public function testActiveMedicationStartedBeforePriorVisitIsAnActiveMedicationFact(): void
    {
        $this->withPriorVisitOn('2026-09-01 10:00:00');
        $this->chart->medications = [new MedicationRecord(17, 'Metformin 500 MG Oral Tablet', new DateTimeImmutable('2025-01-10'), true)];

        $result = $this->assembler()->assemble(new PatientId(7), null);

        $facts = $this->factsIn($result, FactCategory::MedicationActive);
        self::assertCount(1, $facts);
        self::assertSame('Metformin 500 MG Oral Tablet (started 2025-01-10)', $facts[0]->value);
        self::assertSame('PrescriptionService', $facts[0]->service);
        self::assertSame(17, $facts[0]->recordId);
        self::assertSame('drug', $facts[0]->field);
        self::assertSame([], $this->factsIn($result, FactCategory::MedicationNew));
    }

    public function testMedicationStartedAfterPriorVisitIsANewMedicationFactOnly(): void
    {
        $this->withPriorVisitOn('2026-09-01 10:00:00');
        $this->chart->medications = [new MedicationRecord(18, 'Lisinopril 10 MG Oral Tablet', new DateTimeImmutable('2026-09-10'), true)];

        $result = $this->assembler()->assemble(new PatientId(7), null);

        self::assertCount(1, $this->factsIn($result, FactCategory::MedicationNew));
        self::assertSame([], $this->factsIn($result, FactCategory::MedicationActive));
    }

    public function testInactiveMedicationIsNotAFact(): void
    {
        $this->withPriorVisitOn('2026-09-01 10:00:00');
        $this->chart->medications = [new MedicationRecord(19, 'Amoxicillin 500 MG', new DateTimeImmutable('2026-09-10'), false)];

        $result = $this->assembler()->assemble(new PatientId(7), null);

        self::assertSame([FactCategory::PriorVisit], array_map(fn(Fact $f) => $f->category, $result->facts()->all()));
    }

    public function testWithNoPriorVisitEveryActiveMedicationIsActiveNotNew(): void
    {
        $this->chart->medications = [new MedicationRecord(18, 'Lisinopril 10 MG Oral Tablet', new DateTimeImmutable('2026-09-10'), true)];

        $result = $this->assembler()->assemble(new PatientId(7), null);

        self::assertCount(1, $this->factsIn($result, FactCategory::MedicationActive));
        self::assertSame([], $this->factsIn($result, FactCategory::MedicationNew));
    }

    public function testAllergyRecordedAfterPriorVisitIsNewOtherwiseActive(): void
    {
        $this->withPriorVisitOn('2026-09-01 10:00:00');
        $this->chart->allergies = [
            new AllergyRecord(812, 'penicillin', new DateTimeImmutable('2026-09-12')),
            new AllergyRecord(813, 'Mold (organism)', new DateTimeImmutable('2019-03-14')),
        ];

        $result = $this->assembler()->assemble(new PatientId(7), null);

        $new = $this->factsIn($result, FactCategory::AllergyNew);
        self::assertCount(1, $new);
        self::assertSame('penicillin (onset 2026-09-12)', $new[0]->value);
        self::assertSame('AllergyIntoleranceService', $new[0]->service);
        self::assertSame(812, $new[0]->recordId);
        self::assertSame('title', $new[0]->field);
        self::assertSame(['Mold (organism) (onset 2019-03-14)'], array_map(fn(Fact $f) => $f->value, $this->factsIn($result, FactCategory::AllergyActive)));
    }

    public function testMedicationWithoutAStartDateIsDatedByWhenItWasFirstNoted(): void
    {
        $this->withPriorVisitOn('2026-09-01 10:00:00');
        $this->chart->medications = [new MedicationRecord(18, 'Lisinopril 10 MG Oral Tablet', new DateTimeImmutable('2026-09-10'), true, DateProvenance::FirstNoted)];

        $result = $this->assembler()->assemble(new PatientId(7), null);

        $new = $this->factsIn($result, FactCategory::MedicationNew);
        self::assertCount(1, $new);
        self::assertSame('Lisinopril 10 MG Oral Tablet (first noted 2026-09-10)', $new[0]->value);
    }

    public function testMedicationWithNoDateAtAllCarriesNoDate(): void
    {
        $this->withPriorVisitOn('2026-09-01 10:00:00');
        $this->chart->medications = [new MedicationRecord(18, 'Lisinopril 10 MG Oral Tablet', new DateTimeImmutable('1970-01-01'), true, DateProvenance::Unknown)];

        $result = $this->assembler()->assemble(new PatientId(7), null);

        $active = $this->factsIn($result, FactCategory::MedicationActive);
        self::assertCount(1, $active);
        self::assertSame('Lisinopril 10 MG Oral Tablet', $active[0]->value);
    }

    public function testAllergyWithoutAnOnsetDateIsDatedByWhenItWasFirstNoted(): void
    {
        $this->withPriorVisitOn('2026-09-01 10:00:00');
        $this->chart->allergies = [new AllergyRecord(812, 'penicillin', new DateTimeImmutable('2026-09-12'), DateProvenance::FirstNoted)];

        $result = $this->assembler()->assemble(new PatientId(7), null);

        $new = $this->factsIn($result, FactCategory::AllergyNew);
        self::assertCount(1, $new);
        self::assertSame('penicillin (first noted 2026-09-12)', $new[0]->value);
    }

    public function testAllergyWithNoDateAtAllCarriesNoDate(): void
    {
        $this->withPriorVisitOn('2026-09-01 10:00:00');
        $this->chart->allergies = [new AllergyRecord(812, 'penicillin', new DateTimeImmutable('1970-01-01'), DateProvenance::Unknown)];

        $result = $this->assembler()->assemble(new PatientId(7), null);

        self::assertSame(['penicillin'], array_map(fn(Fact $f) => $f->value, $this->factsIn($result, FactCategory::AllergyActive)));
    }

    public function testAllergyMatchingAnActiveMedicationProducesAHitFact(): void
    {
        $this->withPriorVisitOn('2026-09-01 10:00:00');
        $this->chart->allergies = [
            new AllergyRecord(812, 'penicillin', new DateTimeImmutable('2019-03-14')),
            new AllergyRecord(813, 'Mold (organism)', new DateTimeImmutable('2019-03-14')),
        ];
        $this->chart->medications = [
            new MedicationRecord(20, 'Penicillin V Potassium 500 MG Oral Tablet', new DateTimeImmutable('2026-09-10'), true),
            new MedicationRecord(17, 'Metformin 500 MG Oral Tablet', new DateTimeImmutable('2025-01-10'), true),
        ];

        $result = $this->assembler()->assemble(new PatientId(7), null);

        $hits = $this->factsIn($result, FactCategory::AllergyMedicationHit);
        self::assertCount(1, $hits);
        self::assertSame("Documented allergy 'penicillin' matches active medication 'Penicillin V Potassium 500 MG Oral Tablet'", $hits[0]->value);
        self::assertSame(812, $hits[0]->recordId);
    }

    private function lab(int $id, string $loinc, string $name, ?float $value, string $units, string $date, int $encounterId = 100, ?string $printedRange = null, string $labFlag = '', ?string $text = null, bool $fromDocument = false, bool $unitMismatch = false): LabRecord
    {
        $citation = $fromDocument ? new Citation('document', '12', '1', "/results/$id/value", $text ?? (string) $value, true, new BBox(1, 10.0, 10.0, 20.0, 20.0, 612.0, 792.0), null) : null;
        return new LabRecord($id, $encounterId, $loinc, $name, $value, $units, new DateTimeImmutable($date), $citation, $unitMismatch, $printedRange, $labFlag, $text);
    }

    public function testAssemblerSetsTriggerAttributesOnLabMedicationAndProblemFacts(): void
    {
        $this->withPriorVisitOn('2026-09-01 10:00:00');
        $this->chart->labs = [$this->lab(930, '2089-1', 'LDL Cholesterol', 165.0, 'mg/dL', '2026-09-10')];
        $this->chart->medications = [new MedicationRecord(31, 'Atorvastatin 20 MG Oral Tablet', new DateTimeImmutable('2026-09-05'), true)];
        $this->chart->problems = [new ProblemRecord(41, 'Essential hypertension', new DateTimeImmutable('2026-09-06')), new ProblemRecord(42, 'Type 2 diabetes mellitus', new DateTimeImmutable('2020-01-01'))];

        $result = $this->assembler()->assemble(new PatientId(7), null);

        $ldl = $this->factsIn($result, FactCategory::LabAbnormal)[0];
        self::assertSame(['loinc' => '2089-1', 'direction' => 'above'], $ldl->attributes);
        $med = $this->factsIn($result, FactCategory::MedicationNew)[0];
        self::assertSame(['drug' => 'Atorvastatin 20 MG Oral Tablet'], $med->attributes);
        $problem = $this->factsIn($result, FactCategory::ProblemNew)[0];
        self::assertSame(['title' => 'Essential hypertension'], $problem->attributes);
        // Every active problem, new or old, is available to the trigger rules without being a fact.
        self::assertSame(['Essential hypertension', 'Type 2 diabetes mellitus'], $result->activeProblemTitles());
    }

    // -- Task 10: vital signs ---------------------------------------------------

    private function vitals(int $id, string $date, ?int $sys = null, ?int $dia = null, ?float $pulse = null, ?float $spo2 = null, ?float $tempF = null, ?float $resp = null, ?float $weightLb = null, ?float $bmi = null, int $encounterId = 100): VitalRecord
    {
        return new VitalRecord($id, $encounterId, new DateTimeImmutable($date), $sys, $dia, $pulse, $spo2, $tempF, $resp, $weightLb, $bmi);
    }

    private function adult(): void
    {
        $this->chart->demographics = new Demographics('F', new DateTimeImmutable('1975-04-02'));
    }

    public function testHighBloodPressureIsAbnormal(): void
    {
        $this->withPriorVisitOn('2026-09-01 10:00:00');
        $this->adult();
        $this->chart->vitals = [$this->vitals(701, '2026-09-10', 152, 94)];

        $abnormal = $this->factsIn($this->assembler()->assemble(new PatientId(7), null), FactCategory::VitalAbnormal);

        self::assertCount(1, $abnormal);
        self::assertSame('Blood pressure 152/94 mmHg on 2026-09-10 (above 140/90)', $abnormal[0]->value);
        self::assertSame('VitalsService', $abnormal[0]->service);
        self::assertSame(701, $abnormal[0]->recordId);
        self::assertSame('bp', $abnormal[0]->field);
        self::assertTrue($abnormal[0]->category->mustSurface());
        self::assertSame(['vital' => 'bp', 'direction' => 'above'], $abnormal[0]->attributes);
    }

    public function testLowSpo2IsAbnormal(): void
    {
        $this->withPriorVisitOn('2026-09-01 10:00:00');
        $this->adult();
        $this->chart->vitals = [$this->vitals(702, '2026-09-10', spo2: 91.0)];

        $abnormal = $this->factsIn($this->assembler()->assemble(new PatientId(7), null), FactCategory::VitalAbnormal);

        self::assertCount(1, $abnormal);
        self::assertSame('Oxygen saturation 91 % on 2026-09-10 (below 94 %)', $abnormal[0]->value);
        self::assertSame(['vital' => 'spo2', 'direction' => 'below'], $abnormal[0]->attributes);
    }

    public function testHighBmiIsAbnormal(): void
    {
        $this->withPriorVisitOn('2026-09-01 10:00:00');
        $this->adult();
        $this->chart->vitals = [$this->vitals(703, '2026-09-10', bmi: 31.2)];

        $abnormal = $this->factsIn($this->assembler()->assemble(new PatientId(7), null), FactCategory::VitalAbnormal);

        self::assertCount(1, $abnormal);
        self::assertSame('BMI 31.2 on 2026-09-10 (at or above 30)', $abnormal[0]->value);
        self::assertSame(['vital' => 'bmi', 'direction' => 'above'], $abnormal[0]->attributes);
    }

    public function testFeverAndBradycardiaAndTachypnoeaAreAbnormal(): void
    {
        $this->withPriorVisitOn('2026-09-01 10:00:00');
        $this->adult();
        $this->chart->vitals = [$this->vitals(704, '2026-09-10', pulse: 45.0, tempF: 101.2, resp: 24.0)];

        $abnormal = $this->factsIn($this->assembler()->assemble(new PatientId(7), null), FactCategory::VitalAbnormal);
        $texts = array_map(fn(Fact $f) => $f->value, $abnormal);
        sort($texts);

        self::assertSame([
            'Pulse 45 bpm on 2026-09-10 (below 50 bpm)',
            'Respiration 24 per minute on 2026-09-10 (above 20 per minute)',
            'Temperature 101.2 F on 2026-09-10 (at or above 100.4 F)',
        ], $texts);
    }

    public function testNormalVitalsProduceNoFact(): void
    {
        $this->withPriorVisitOn('2026-09-01 10:00:00');
        $this->adult();
        $this->chart->vitals = [$this->vitals(705, '2026-09-10', 122, 78, 72.0, 98.0, 98.4, 14.0, 160.0, 24.1)];

        $result = $this->assembler()->assemble(new PatientId(7), null);

        self::assertSame([], $this->factsIn($result, FactCategory::VitalAbnormal));
        self::assertSame([], $this->factsIn($result, FactCategory::VitalDelta));
    }

    public function testVitalBeforePriorVisitIsNotAFact(): void
    {
        $this->withPriorVisitOn('2026-09-01 10:00:00');
        $this->adult();
        $this->chart->vitals = [$this->vitals(706, '2026-08-10', 160, 100)];

        self::assertSame([], $this->factsIn($this->assembler()->assemble(new PatientId(7), null), FactCategory::VitalAbnormal));
    }

    public function testWeightDeltaUnderThresholdIsNotAFact(): void
    {
        $this->withPriorVisitOn('2026-09-01 10:00:00');
        $this->adult();
        $this->chart->vitals = [$this->vitals(707, '2026-09-10', weightLb: 178.0), $this->vitals(700, '2026-03-02', weightLb: 182.0)];

        self::assertSame([], $this->factsIn($this->assembler()->assemble(new PatientId(7), null), FactCategory::VitalDelta));
    }

    public function testWeightAndSystolicDeltasOverThresholdAreDeltaFacts(): void
    {
        $this->withPriorVisitOn('2026-09-01 10:00:00');
        $this->adult();
        $this->chart->vitals = [$this->vitals(708, '2026-09-10', 152, 94, weightLb: 171.0), $this->vitals(700, '2026-03-02', 128, 80, weightLb: 182.0)];

        $texts = array_map(fn(Fact $f) => $f->value, $this->factsIn($this->assembler()->assemble(new PatientId(7), null), FactCategory::VitalDelta));
        sort($texts);

        self::assertSame([
            'Systolic blood pressure changed from 128 (2026-03-02) to 152 (2026-09-10): up 24',
            'Weight changed from 182 lb (2026-03-02) to 171 lb (2026-09-10): down 11 lb (6 %)',
        ], $texts);
        self::assertFalse(FactCategory::VitalDelta->mustSurface());
    }

    public function testMalformedBloodPressureIsSkipped(): void
    {
        $this->withPriorVisitOn('2026-09-01 10:00:00');
        $this->adult();
        // The chart source maps an unparseable pressure to null; nothing is judged and nothing crashes.
        $this->chart->vitals = [$this->vitals(709, '2026-09-10', null, null, 70.0)];

        $result = $this->assembler()->assemble(new PatientId(7), null);

        self::assertSame([], $this->factsIn($result, FactCategory::VitalAbnormal));
        self::assertSame([], $this->factsIn($result, FactCategory::VitalDelta));
    }

    public function testUnder18ProducesNoVitalAbnormalFacts(): void
    {
        $this->withPriorVisitOn('2026-09-01 10:00:00');
        $this->chart->demographics = new Demographics('M', new DateTimeImmutable('2012-06-01'));
        $this->chart->vitals = [$this->vitals(710, '2026-09-10', 152, 94, 45.0)];

        self::assertSame([], $this->factsIn($this->assembler()->assemble(new PatientId(7), null), FactCategory::VitalAbnormal));
    }

    public function testVitalFromASensitiveEncounterTheUserMayNotSeeIsExcluded(): void
    {
        $this->auth->deny('sensitivities', 'high');
        $this->adult();
        $this->chart->encounters = [$this->encounter(99, '2026-09-01 10:00:00'), $this->encounter(120, '2026-09-10 09:00:00', 'high')];
        $this->chart->vitals = [$this->vitals(711, '2026-09-10', 152, 94, encounterId: 120)];

        self::assertSame([], $this->factsIn($this->assembler()->assemble(new PatientId(7), 130), FactCategory::VitalAbnormal));
    }

    // -- Task 9: chart-state changes ------------------------------------------

    public function testMedicationStoppedAfterPriorVisitIsAStoppedFact(): void
    {
        $this->withPriorVisitOn('2026-09-01 10:00:00');
        $this->chart->medications = [new MedicationRecord(21, 'Metformin 500 MG Oral Tablet', new DateTimeImmutable('2024-03-02'), false, endDate: new DateTimeImmutable('2026-09-05'))];

        $result = $this->assembler()->assemble(new PatientId(7), null);

        $stopped = $this->factsIn($result, FactCategory::MedicationStopped);
        self::assertCount(1, $stopped);
        self::assertSame('Metformin 500 MG Oral Tablet stopped 2026-09-05 (started 2024-03-02)', $stopped[0]->value);
        self::assertSame('stopped', $stopped[0]->field);
        self::assertTrue($stopped[0]->category->mustSurface());
        self::assertSame(['drug' => 'Metformin 500 MG Oral Tablet'], $stopped[0]->attributes);
        self::assertSame([], $this->factsIn($result, FactCategory::MedicationActive));
        self::assertSame([], $this->factsIn($result, FactCategory::MedicationNew));
    }

    public function testMedicationStoppedBeforePriorVisitIsNotAFact(): void
    {
        $this->withPriorVisitOn('2026-09-01 10:00:00');
        $this->chart->medications = [new MedicationRecord(22, 'Amoxicillin 500 MG', new DateTimeImmutable('2026-07-01'), false, endDate: new DateTimeImmutable('2026-07-10'))];

        $result = $this->assembler()->assemble(new PatientId(7), null);

        self::assertSame([FactCategory::PriorVisit], array_map(fn(Fact $f) => $f->category, $result->facts()->all()));
    }

    public function testInactiveMedicationWithNoStopDateAfterPriorVisitUsesItsModifiedDate(): void
    {
        $this->withPriorVisitOn('2026-09-01 10:00:00');
        $this->chart->medications = [new MedicationRecord(23, 'Atenolol 50 MG', new DateTimeImmutable('2025-01-01'), false, modifiedDate: new DateTimeImmutable('2026-09-03'))];

        $stopped = $this->factsIn($this->assembler()->assemble(new PatientId(7), null), FactCategory::MedicationStopped);

        self::assertCount(1, $stopped);
        self::assertSame('Atenolol 50 MG stopped 2026-09-03 (started 2025-01-01)', $stopped[0]->value);
    }

    public function testSameDrugStoppedAndRestartedCollapsesToChanged(): void
    {
        $this->withPriorVisitOn('2026-09-01 10:00:00');
        $this->chart->medications = [
            new MedicationRecord(24, 'Lisinopril 10 MG Oral Tablet', new DateTimeImmutable('2025-02-01'), false, endDate: new DateTimeImmutable('2026-09-04'), interval: 'daily'),
            new MedicationRecord(25, 'Lisinopril 20 MG Oral Tablet', new DateTimeImmutable('2026-09-04'), true, interval: 'daily'),
        ];

        $result = $this->assembler()->assemble(new PatientId(7), null);

        $changed = $this->factsIn($result, FactCategory::MedicationChanged);
        self::assertCount(1, $changed);
        self::assertSame('Lisinopril changed from 10 MG Oral Tablet daily to 20 MG Oral Tablet daily on 2026-09-04', $changed[0]->value);
        self::assertSame(25, $changed[0]->recordId);
        self::assertSame(['drug' => 'Lisinopril 20 MG Oral Tablet'], $changed[0]->attributes);
        self::assertSame([], $this->factsIn($result, FactCategory::MedicationStopped), 'the pair is one change, not a stop and a start');
        self::assertSame([], $this->factsIn($result, FactCategory::MedicationNew));
    }

    public function testResolvedProblemAfterPriorVisitIsAResolvedFactAndLeavesTheActiveList(): void
    {
        $this->withPriorVisitOn('2026-09-01 10:00:00');
        $this->chart->problems = [
            new ProblemRecord(41, 'Acute bronchitis', new DateTimeImmutable('2026-07-20'), endDate: new DateTimeImmutable('2026-09-06'), active: false),
            new ProblemRecord(42, 'Essential hypertension', new DateTimeImmutable('2020-01-01')),
        ];

        $result = $this->assembler()->assemble(new PatientId(7), null);

        $resolved = $this->factsIn($result, FactCategory::ProblemResolved);
        self::assertCount(1, $resolved);
        self::assertSame('Acute bronchitis resolved 2026-09-06', $resolved[0]->value);
        self::assertFalse($resolved[0]->category->mustSurface());
        self::assertSame(['Essential hypertension'], $result->activeProblemTitles());
        self::assertSame([], $this->factsIn($result, FactCategory::ProblemNew));
    }

    public function testProblemResolvedBeforePriorVisitIsNotAFact(): void
    {
        $this->withPriorVisitOn('2026-09-01 10:00:00');
        $this->chart->problems = [new ProblemRecord(43, 'Otitis media', new DateTimeImmutable('2026-05-01'), endDate: new DateTimeImmutable('2026-05-20'), active: false)];

        self::assertSame([], $this->factsIn($this->assembler()->assemble(new PatientId(7), null), FactCategory::ProblemResolved));
    }

    public function testPendingOrderAfterPriorVisitIsAPendingLabFact(): void
    {
        $this->withPriorVisitOn('2026-09-01 10:00:00');
        $this->chart->pendingOrders = [
            new PendingOrderRecord(501, 'Lipid panel', new DateTimeImmutable('2026-09-02'), 'pending'),
            new PendingOrderRecord(502, 'CBC', new DateTimeImmutable('2026-08-02'), 'pending'),
        ];

        $result = $this->assembler()->assemble(new PatientId(7), null);

        $pending = $this->factsIn($result, FactCategory::LabPending);
        self::assertCount(1, $pending);
        self::assertSame('Lipid panel ordered 2026-09-02, no result on file', $pending[0]->value);
        self::assertSame(501, $pending[0]->recordId);
        self::assertSame('ProcedureOrderService', $pending[0]->service);
        self::assertTrue($pending[0]->category->mustSurface());
    }

    // -- Task 2: ranges from the lab, the report and the standard table ------

    public function testAbnormalFromBuiltInRangeWhenReportPrintedNone(): void
    {
        $this->withPriorVisitOn('2026-09-01 10:00:00');
        $this->chart->labs = [$this->lab(910, '1742-6', 'ALT', 62.0, 'U/L', '2026-09-10', fromDocument: true)];

        $abnormal = $this->factsIn($this->assembler()->assemble(new PatientId(7), null), FactCategory::LabAbnormal);

        self::assertCount(1, $abnormal);
        self::assertSame('ALT 62 U/L on 2026-09-10 (above the standard range 7-56 U/L; no range printed on the report)', $abnormal[0]->value);
        self::assertNotNull($abnormal[0]->citation);
    }

    public function testAbnormalFromLabsPrintedRange(): void
    {
        $this->withPriorVisitOn('2026-09-01 10:00:00');
        $this->chart->labs = [$this->lab(911, '2823-3', 'Potassium', 5.4, 'mmol/L', '2026-09-10', printedRange: '3.5-5.1')];

        $abnormal = $this->factsIn($this->assembler()->assemble(new PatientId(7), null), FactCategory::LabAbnormal);

        self::assertCount(1, $abnormal);
        self::assertSame("Potassium 5.4 mmol/L on 2026-09-10 (above the lab's range 3.5-5.1)", $abnormal[0]->value);
    }

    public function testAbnormalFromLabFlagAlone(): void
    {
        $this->withPriorVisitOn('2026-09-01 10:00:00');
        // No standard range for this analyte and the value sits inside the printed range: only the lab's flag says abnormal.
        $this->chart->labs = [$this->lab(912, '99999-9', 'Obscure assay', 5.0, 'x', '2026-09-10', printedRange: '0-10', labFlag: 'high')];

        $abnormal = $this->factsIn($this->assembler()->assemble(new PatientId(7), null), FactCategory::LabAbnormal);

        self::assertCount(1, $abnormal);
        self::assertSame("Obscure assay 5 x on 2026-09-10 (flagged high by the lab; lab's range 0-10)", $abnormal[0]->value);
    }

    public function testLabRangeAndStandardRangeDisagreementIsStatedInTheFact(): void
    {
        $this->withPriorVisitOn('2026-09-01 10:00:00');
        $this->chart->labs = [$this->lab(913, '3016-3', 'TSH', 4.2, 'uIU/mL', '2026-09-10', printedRange: '0.5-5.5')];

        $result = $this->assembler()->assemble(new PatientId(7), null);

        $abnormal = $this->factsIn($result, FactCategory::LabAbnormal);
        self::assertCount(1, $abnormal);
        self::assertSame("TSH 4.2 uIU/mL on 2026-09-10 (within the lab's range 0.5-5.5; above the standard range 0.4-4 uIU/mL)", $abnormal[0]->value);
        self::assertSame([], $this->factsIn($result, FactCategory::LabNormal));
    }

    public function testNormalResultIsALabNormalFactWithItsRange(): void
    {
        $this->withPriorVisitOn('2026-09-01 10:00:00');
        $this->chart->labs = [
            $this->lab(914, '2951-2', 'Sodium', 139.0, 'mmol/L', '2026-09-10'),
            $this->lab(915, '2075-0', 'Chloride', 101.0, 'mmol/L', '2026-09-10', printedRange: '98-107'),
        ];

        $result = $this->assembler()->assemble(new PatientId(7), null);

        $normal = $this->byRecordId($this->factsIn($result, FactCategory::LabNormal));
        self::assertCount(2, $normal);
        self::assertSame('Sodium 139 mmol/L on 2026-09-10 (reference range 135-145 mmol/L)', $normal[914]->value);
        self::assertSame("Chloride 101 mmol/L on 2026-09-10 (lab's range 98-107)", $normal[915]->value);
        self::assertSame('result', $normal[914]->field);
        self::assertFalse($normal[914]->category->mustSurface());
        self::assertSame([], $this->factsIn($result, FactCategory::LabAbnormal));
    }

    public function testQualitativePositiveIsAbnormal(): void
    {
        $this->withPriorVisitOn('2026-09-01 10:00:00');
        $this->chart->labs = [
            $this->lab(916, '', 'Urine culture', null, '', '2026-09-10', labFlag: 'yes', text: 'positive'),
            $this->lab(917, '', 'Hepatitis C antibody', null, '', '2026-09-10', text: 'Reactive'),
        ];

        $abnormal = $this->byRecordId($this->factsIn($this->assembler()->assemble(new PatientId(7), null), FactCategory::LabAbnormal));

        self::assertCount(2, $abnormal);
        self::assertSame('Urine culture: positive on 2026-09-10 (flagged abnormal by the lab)', $abnormal[916]->value);
        self::assertSame('Hepatitis C antibody: Reactive on 2026-09-10 (reported as reactive)', $abnormal[917]->value);
    }

    public function testQualitativeUnflaggedIsNormal(): void
    {
        $this->withPriorVisitOn('2026-09-01 10:00:00');
        $this->chart->labs = [$this->lab(918, '', 'Urine culture', null, '', '2026-09-10', text: 'negative')];

        $result = $this->assembler()->assemble(new PatientId(7), null);

        $normal = $this->factsIn($result, FactCategory::LabNormal);
        self::assertCount(1, $normal);
        self::assertSame('Urine culture: negative on 2026-09-10', $normal[0]->value);
        self::assertSame([], $this->factsIn($result, FactCategory::LabAbnormal));
    }

    public function testCriticalValueIsALabCriticalFact(): void
    {
        $this->withPriorVisitOn('2026-09-01 10:00:00');
        $this->chart->labs = [
            $this->lab(919, '2823-3', 'Potassium', 6.4, 'mmol/L', '2026-09-10', printedRange: '3.5-5.1'),
            $this->lab(920, '718-7', 'Hemoglobin', 9.0, 'g/dL', '2026-09-10', labFlag: 'vlow'),
        ];

        $result = $this->assembler()->assemble(new PatientId(7), null);

        $critical = $this->byRecordId($this->factsIn($result, FactCategory::LabCritical));
        self::assertCount(2, $critical);
        self::assertSame("Potassium 6.4 mmol/L on 2026-09-10 (above the panic limit 6 mmol/L; lab's range 3.5-5.1)", $critical[919]->value);
        self::assertSame('Hemoglobin 9 g/dL on 2026-09-10 (flagged vlow by the lab; below the standard range 12-17.5 g/dL)', $critical[920]->value);
        self::assertTrue($critical[919]->category->mustSurface());
        self::assertSame([], $this->factsIn($result, FactCategory::LabAbnormal), 'a critical result is one fact, not two');
    }

    public function testComparatorResultIsQualitativeNotDropped(): void
    {
        $this->withPriorVisitOn('2026-09-01 10:00:00');
        $this->chart->labs = [$this->lab(921, '', 'HCG, quantitative', null, 'mIU/mL', '2026-09-10', text: '<5')];

        $normal = $this->factsIn($this->assembler()->assemble(new PatientId(7), null), FactCategory::LabNormal);

        self::assertCount(1, $normal);
        self::assertSame('HCG, quantitative: <5 mIU/mL on 2026-09-10', $normal[0]->value);
    }

    public function testUnparseablePrintedRangeFallsBackToBuiltIn(): void
    {
        $this->withPriorVisitOn('2026-09-01 10:00:00');
        $this->chart->labs = [$this->lab(922, '2951-2', 'Sodium', 150.0, 'mmol/L', '2026-09-10', printedRange: 'Negative', fromDocument: true)];

        $abnormal = $this->factsIn($this->assembler()->assemble(new PatientId(7), null), FactCategory::LabAbnormal);

        self::assertCount(1, $abnormal);
        self::assertSame('Sodium 150 mmol/L on 2026-09-10 (above the standard range 135-145 mmol/L)', $abnormal[0]->value);
    }

    public function testSexSpecificRangeUsesPatientSex(): void
    {
        $this->withPriorVisitOn('2026-09-01 10:00:00');
        $this->chart->labs = [$this->lab(923, '718-7', 'Hemoglobin', 13.0, 'g/dL', '2026-09-10')];

        $this->chart->demographics = new Demographics('F', null);
        $female = $this->assembler()->assemble(new PatientId(7), null);
        self::assertCount(1, $this->factsIn($female, FactCategory::LabNormal));
        self::assertSame([], $this->factsIn($female, FactCategory::LabAbnormal));

        $this->chart->demographics = new Demographics('M', null);
        $male = $this->assembler()->assemble(new PatientId(7), null);
        $abnormal = $this->factsIn($male, FactCategory::LabAbnormal);
        self::assertCount(1, $abnormal);
        self::assertSame('Hemoglobin 13 g/dL on 2026-09-10 (below the standard range 13.5-17.5 g/dL)', $abnormal[0]->value);
    }

    public function testUnitMismatchStillHonoursTheLabFlag(): void
    {
        $this->withPriorVisitOn('2026-09-01 10:00:00');
        $this->chart->labs = [
            $this->lab(924, '2345-7', 'Glucose', 5.9, 'mmol/L', '2026-09-10', labFlag: 'high', unitMismatch: true),
            $this->lab(925, '2345-7', 'Glucose', 5.1, 'mmol/L', '2026-09-09', unitMismatch: true),
        ];

        $result = $this->assembler()->assemble(new PatientId(7), null);

        $abnormal = $this->factsIn($result, FactCategory::LabAbnormal);
        self::assertCount(1, $abnormal);
        self::assertSame('Glucose 5.9 mmol/L on 2026-09-10 (flagged high by the lab)', $abnormal[0]->value);
        // The second reading is in an unexpected unit with no flag: not judged against the standard range, no fact.
        self::assertSame([], $this->factsIn($result, FactCategory::LabNormal));
    }

    public function testLabOutsideReferenceRangeSinceLastVisitIsAnAbnormalFact(): void
    {
        $this->withPriorVisitOn('2026-09-01 10:00:00');
        $this->chart->labs = [
            $this->lab(901, '4548-4', 'Hemoglobin A1c', 7.8, '%', '2026-09-10'),
            $this->lab(902, '718-7', 'Hemoglobin', 14.0, 'g/dL', '2026-09-10'),
        ];

        $result = $this->assembler()->assemble(new PatientId(7), null);

        $abnormal = $this->factsIn($result, FactCategory::LabAbnormal);
        self::assertCount(1, $abnormal);
        self::assertSame('Hemoglobin A1c 7.8 % on 2026-09-10 (above the standard range 4-5.6 %)', $abnormal[0]->value);
        self::assertSame('ObservationLabService', $abnormal[0]->service);
        self::assertSame(901, $abnormal[0]->recordId);
        self::assertSame('result', $abnormal[0]->field);
    }

    public function testLabWithoutAReferenceRangeIsNeverFlaggedAbnormal(): void
    {
        $this->withPriorVisitOn('2026-09-01 10:00:00');
        $this->chart->labs = [$this->lab(903, '99999-9', 'Obscure assay', 999.0, 'x', '2026-09-10')];

        $result = $this->assembler()->assemble(new PatientId(7), null);

        self::assertSame([], $this->factsIn($result, FactCategory::LabAbnormal));
        self::assertSame([], $this->factsIn($result, FactCategory::LabNormal), 'no range at all: no fact, as before');
    }

    public function testLabBeforeThePriorVisitIsNotFlagged(): void
    {
        $this->withPriorVisitOn('2026-09-01 10:00:00');
        $this->chart->labs = [$this->lab(901, '4548-4', 'Hemoglobin A1c', 7.8, '%', '2026-08-10')];

        $result = $this->assembler()->assemble(new PatientId(7), null);

        self::assertSame([], $this->factsIn($result, FactCategory::LabAbnormal));
    }

    public function testChangeSincePriorResultOfSameTestIsADeltaFact(): void
    {
        $this->withPriorVisitOn('2026-09-01 10:00:00');
        $this->chart->labs = [
            $this->lab(901, '4548-4', 'Hemoglobin A1c', 7.8, '%', '2026-09-10'),
            $this->lab(890, '4548-4', 'Hemoglobin A1c', 7.0, '%', '2026-06-10', 98),
        ];

        $result = $this->assembler()->assemble(new PatientId(7), null);

        $delta = $this->factsIn($result, FactCategory::LabDelta);
        self::assertCount(1, $delta);
        self::assertSame('Hemoglobin A1c changed from 7 % (2026-06-10) to 7.8 % (2026-09-10): up 0.8 (reference range 4-5.6 %)', $delta[0]->value);
        self::assertSame(901, $delta[0]->recordId);
        self::assertSame('delta', $delta[0]->field);
    }

    public function testLabFromASensitiveEncounterTheUserMayNotSeeIsExcluded(): void
    {
        $this->auth->deny('sensitivities', 'high');
        $this->chart->encounters = [
            $this->encounter(100, '2026-09-10 08:30:00', 'high', 'Psychiatry'),
            $this->encounter(99, '2026-09-01 10:00:00'),
        ];
        $this->chart->labs = [$this->lab(901, '4548-4', 'Hemoglobin A1c', 7.8, '%', '2026-09-10', 100)];

        $result = $this->assembler()->assemble(new PatientId(7), null);

        self::assertSame([], $this->factsIn($result, FactCategory::LabAbnormal));
    }

    public function testProblemRecordedAfterPriorVisitIsANewProblemFact(): void
    {
        $this->withPriorVisitOn('2026-09-01 10:00:00');
        $this->chart->problems = [
            new ProblemRecord(501, 'Type 2 diabetes mellitus', new DateTimeImmutable('2026-09-12')),
            new ProblemRecord(500, 'Hypertension', new DateTimeImmutable('2020-01-01')),
        ];

        $result = $this->assembler()->assemble(new PatientId(7), null);

        $new = $this->factsIn($result, FactCategory::ProblemNew);
        self::assertCount(1, $new);
        self::assertSame('Type 2 diabetes mellitus', $new[0]->value);
        self::assertSame('ConditionService', $new[0]->service);
        self::assertSame(501, $new[0]->recordId);
        self::assertSame('title', $new[0]->field);
        self::assertCount(2, $result->facts()->all());
    }

    public function testCategoryOverCapIsTruncatedWithACountFact(): void
    {
        $this->withPriorVisitOn('2026-09-01 10:00:00');
        for ($i = 1; $i <= 53; $i++) {
            $this->chart->medications[] = new MedicationRecord($i, "Drug $i", new DateTimeImmutable('2020-01-01'), true);
        }

        $result = $this->assembler()->assemble(new PatientId(7), null);

        self::assertCount(50, $this->factsIn($result, FactCategory::MedicationActive));
        $truncation = $this->factsIn($result, FactCategory::Truncation);
        self::assertCount(1, $truncation);
        self::assertSame('3 additional active medications not shown', $truncation[0]->value);
    }

    public function testFactIdsAreDerivedFromSourceNotPosition(): void
    {
        $this->withPriorVisitOn('2026-09-01 10:00:00');
        $this->chart->medications = [new MedicationRecord(17, 'Metformin 500 MG Oral Tablet', new DateTimeImmutable('2025-01-10'), true)];
        $first = $this->factsIn($this->assembler()->assemble(new PatientId(7), null), FactCategory::MedicationActive)[0]->id;

        array_unshift($this->chart->medications, new MedicationRecord(16, 'Aspirin 81 MG', new DateTimeImmutable('2025-01-10'), true));
        $meds = $this->factsIn($this->assembler()->assemble(new PatientId(7), null), FactCategory::MedicationActive);

        self::assertSame($first, $meds[1]->id);
        self::assertSame(Fact::idFor('PrescriptionService', 17, 'drug'), $first);
    }

    public function testFactsHashIsStableAndChangesWhenAnyValueChanges(): void
    {
        $this->withPriorVisitOn('2026-09-01 10:00:00');
        $this->chart->medications = [new MedicationRecord(17, 'Metformin 500 MG Oral Tablet', new DateTimeImmutable('2025-01-10'), true)];
        $a = $this->assembler()->assemble(new PatientId(7), null)->facts()->hash();
        $b = $this->assembler()->assemble(new PatientId(7), null)->facts()->hash();

        $this->chart->medications = [new MedicationRecord(17, 'Metformin 1000 MG Oral Tablet', new DateTimeImmutable('2025-01-10'), true)];
        $c = $this->assembler()->assemble(new PatientId(7), null)->facts()->hash();

        self::assertSame($a, $b);
        self::assertNotSame($a, $c);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $a);
    }
}
