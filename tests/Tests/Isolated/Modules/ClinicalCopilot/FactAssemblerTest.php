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
use OpenEMR\Modules\ClinicalCopilot\EncounterRecord;
use OpenEMR\Modules\ClinicalCopilot\Fact;
use OpenEMR\Modules\ClinicalCopilot\FactAssembler;
use OpenEMR\Modules\ClinicalCopilot\FactCategory;
use OpenEMR\Modules\ClinicalCopilot\LabRecord;
use OpenEMR\Modules\ClinicalCopilot\MedicationRecord;
use OpenEMR\Modules\ClinicalCopilot\PatientId;
use OpenEMR\Modules\ClinicalCopilot\ProblemRecord;
use OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Support\FakeAuthorization;
use OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Support\FakeChartSource;
use OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Support\FixedClock;
use OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Support\ModuleAutoload;
use PHPUnit\Framework\TestCase;

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

    public function testSameDayPriorEncounterIsChosenByLowerId(): void
    {
        $this->chart->encounters = [
            $this->encounter(101, '2026-09-15 08:30:00'),
            $this->encounter(100, '2026-09-15 08:30:00'),
            $this->encounter(99, '2026-09-01 10:00:00'),
        ];

        $result = $this->assembler()->assemble(new PatientId(7), 101);

        self::assertSame(100, $result->priorEncounter()?->id);
    }

    public function testNoPriorEncounterWhenChartHasOnlyTheCurrentVisit(): void
    {
        $this->chart->encounters = [$this->encounter(100, '2026-09-15 08:30:00')];

        $result = $this->assembler()->assemble(new PatientId(7), 100);

        self::assertNull($result->priorEncounter());
    }

    public function testEncountersAfterThePriorVisitBecomeEncounterFacts(): void
    {
        $this->chart->encounters = [
            $this->encounter(100, '2026-09-15 08:30:00', '', 'Annual physical'),
            $this->encounter(99, '2026-09-08 10:00:00', '', 'Urgent visit'),
            $this->encounter(98, '2026-09-01 10:00:00', '', 'Follow-up'),
        ];

        $result = $this->assembler()->assemble(new PatientId(7), null);

        $encounterFacts = array_values(array_filter(
            $result->facts()->all(),
            fn(Fact $f) => $f->category === FactCategory::Encounter
        ));
        self::assertSame(99, $result->priorEncounter()?->id);
        self::assertCount(1, $encounterFacts);
        self::assertSame('2026-09-15: Annual physical', $encounterFacts[0]->value);
        self::assertSame('EncounterService', $encounterFacts[0]->service);
        self::assertSame(100, $encounterFacts[0]->recordId);
        self::assertSame('reason', $encounterFacts[0]->field);
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
        self::assertSame('Metformin 500 MG Oral Tablet', $facts[0]->value);
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

        self::assertSame([], $result->facts()->all());
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
        self::assertSame('penicillin', $new[0]->value);
        self::assertSame('AllergyIntoleranceService', $new[0]->service);
        self::assertSame(812, $new[0]->recordId);
        self::assertSame('title', $new[0]->field);
        self::assertSame(['Mold (organism)'], array_map(fn(Fact $f) => $f->value, $this->factsIn($result, FactCategory::AllergyActive)));
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

    private function lab(int $id, string $loinc, string $name, float $value, string $units, string $date, int $encounterId = 100): LabRecord
    {
        return new LabRecord($id, $encounterId, $loinc, $name, $value, $units, new DateTimeImmutable($date));
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
        self::assertSame('Hemoglobin A1c 7.8 % on 2026-09-10 (above reference range 4-5.6 %)', $abnormal[0]->value);
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
        self::assertSame('Hemoglobin A1c changed from 7 % (2026-06-10) to 7.8 % (2026-09-10): up 0.8', $delta[0]->value);
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
        self::assertCount(1, $result->facts()->all());
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
        $first = $this->assembler()->assemble(new PatientId(7), null)->facts()->all()[0]->id;

        array_unshift($this->chart->medications, new MedicationRecord(16, 'Aspirin 81 MG', new DateTimeImmutable('2025-01-10'), true));
        $facts = $this->assembler()->assemble(new PatientId(7), null)->facts()->all();

        self::assertSame($first, $facts[1]->id);
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
