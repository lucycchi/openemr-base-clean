<?php

/**
 * Pins the chart-driven guideline triggers: which facts fire which topic,
 * which exclusions suppress them, and that every rule points at a document
 * the corpus actually holds.
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
use OpenEMR\Modules\ClinicalCopilot\AssembledFacts;
use OpenEMR\Modules\ClinicalCopilot\Demographics;
use OpenEMR\Modules\ClinicalCopilot\Fact;
use OpenEMR\Modules\ClinicalCopilot\FactCategory;
use OpenEMR\Modules\ClinicalCopilot\FactSet;
use OpenEMR\Modules\ClinicalCopilot\GuidelineManifest;
use OpenEMR\Modules\ClinicalCopilot\Guidelines\FiredTrigger;
use OpenEMR\Modules\ClinicalCopilot\Guidelines\GuidelineTriggers;
use OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Support\ModuleAutoload;
use PHPUnit\Framework\TestCase;

final class GuidelineTriggersTest extends TestCase
{
    private const MODULE = __DIR__ . '/../../../../../interface/modules/custom_modules/oe-module-clinical-copilot';

    public static function setUpBeforeClass(): void
    {
        ModuleAutoload::register();
    }

    private DateTimeImmutable $today;

    protected function setUp(): void
    {
        $this->today = new DateTimeImmutable('2026-09-15 09:00:00');
    }

    /** @param array<string, string> $attributes */
    private function fact(int $recordId, FactCategory $category, string $value, array $attributes): Fact
    {
        return new Fact(Fact::idFor('Test', $recordId, 'x'), 'Test', $recordId, 'x', $value, $category, null, $attributes);
    }

    /**
     * @param list<Fact> $facts
     * @param list<string> $activeProblems
     */
    private function assembled(array $facts, array $activeProblems = [], ?float $latestBmi = null): AssembledFacts
    {
        return new AssembledFacts(new FactSet($facts), null, $activeProblems, $latestBmi);
    }

    /** @param 'M'|'F' $sex */
    private function aged(int $years, string $sex = 'F'): Demographics
    {
        return new Demographics($sex, $this->today->modify("-$years years")->modify('-1 day'));
    }

    /** @return array<string, FiredTrigger> keyed by trigger id */
    private function fire(AssembledFacts $facts, Demographics $who): array
    {
        $out = [];
        foreach ((new GuidelineTriggers())->fire($facts, $who, $this->today) as $t) {
            $out[$t->id] = $t;
        }
        return $out;
    }

    public function testHighLdlFiresLipidsWithTheFactId(): void
    {
        $ldl = $this->fact(1, FactCategory::LabAbnormal, 'LDL Cholesterol 165 mg/dL on 2026-09-10 (above the standard range 0-129 mg/dL)', ['loinc' => '2089-1', 'direction' => 'above']);

        $fired = $this->fire($this->assembled([$ldl]), $this->aged(55));

        self::assertArrayHasKey('lipids', $fired);
        self::assertSame([$ldl->id], $fired['lipids']->factIds);
        self::assertSame('acc-aha-2018-cholesterol', $fired['lipids']->expectedSource);
        self::assertNotSame('', $fired['lipids']->query);
        self::assertCount(1, $fired);
    }

    public function testNormalChartFiresNothing(): void
    {
        $facts = [
            $this->fact(1, FactCategory::LabNormal, 'Sodium 139 mmol/L on 2026-09-10 (reference range 135-145 mmol/L)', ['loinc' => '2951-2']),
            $this->fact(2, FactCategory::MedicationActive, 'Cetirizine 10 mg', ['drug' => 'Cetirizine 10 mg']),
            $this->fact(3, FactCategory::PriorVisit, '2026-09-01: Follow-up', []),
        ];

        self::assertSame([], $this->fire($this->assembled($facts), $this->aged(55)));
    }

    public function testProblemListAloneFiresDiabetes(): void
    {
        $fired = $this->fire($this->assembled([], ['Type 2 diabetes mellitus without complications']), $this->aged(60));

        self::assertArrayHasKey('diabetes', $fired);
        self::assertSame([], $fired['diabetes']->factIds);
        self::assertSame(['on the problem list: Type 2 diabetes mellitus without complications'], $fired['diabetes']->reasons);
    }

    public function testAgeExclusionSuppressesLipids(): void
    {
        $ldl = $this->fact(1, FactCategory::LabAbnormal, 'LDL Cholesterol 165 mg/dL', ['loinc' => '2089-1', 'direction' => 'above']);

        self::assertArrayNotHasKey('lipids', $this->fire($this->assembled([$ldl]), $this->aged(82)));
        self::assertArrayNotHasKey('lipids', $this->fire($this->assembled([$ldl]), $this->aged(31)));
        self::assertArrayHasKey('lipids', $this->fire($this->assembled([$ldl]), $this->aged(40)));
        self::assertArrayHasKey('lipids', $this->fire($this->assembled([$ldl]), $this->aged(75)));
    }

    public function testUnknownAgeDoesNotSuppressAnAgeExcludedRule(): void
    {
        $ldl = $this->fact(1, FactCategory::LabAbnormal, 'LDL Cholesterol 165 mg/dL', ['loinc' => '2089-1', 'direction' => 'above']);

        self::assertArrayHasKey('lipids', $this->fire($this->assembled([$ldl]), Demographics::unknown()));
    }

    public function testPregnancyExclusionSuppressesAnemia(): void
    {
        $hgb = $this->fact(1, FactCategory::LabAbnormal, 'Hemoglobin 10.2 g/dL', ['loinc' => '718-7', 'direction' => 'below']);

        self::assertArrayHasKey('anemia', $this->fire($this->assembled([$hgb]), $this->aged(30)));
        self::assertArrayNotHasKey('anemia', $this->fire($this->assembled([$hgb], ['Pregnancy, second trimester']), $this->aged(30)));
    }

    public function testHighHemoglobinDoesNotFireAnemia(): void
    {
        $hgb = $this->fact(1, FactCategory::LabAbnormal, 'Hemoglobin 18.9 g/dL', ['loinc' => '718-7', 'direction' => 'above']);

        self::assertArrayNotHasKey('anemia', $this->fire($this->assembled([$hgb]), $this->aged(30)));
    }

    public function testScreeningDoesNotFireOnAgeAlone(): void
    {
        self::assertArrayNotHasKey('screening', $this->fire($this->assembled([]), $this->aged(50)));
    }

    public function testEveryRuleNamesASourceInTheManifest(): void
    {
        $manifest = new GuidelineManifest(self::MODULE . '/sidecar/corpus/manifest.json');
        $rules = (new GuidelineTriggers())->rules();
        self::assertNotSame([], $rules);
        foreach ($rules as $rule) {
            self::assertNotNull($manifest->document($rule->source), "rule {$rule->id} names a source the corpus does not hold: {$rule->source}");
            self::assertNotSame('', $rule->query);
            self::assertNotSame('', $rule->label);
        }
    }

    public function testFiredTriggerOrderFollowsTheRulesFile(): void
    {
        $facts = [
            $this->fact(1, FactCategory::LabAbnormal, 'Hemoglobin 10.2 g/dL', ['loinc' => '718-7', 'direction' => 'below']),
            $this->fact(2, FactCategory::LabAbnormal, 'LDL Cholesterol 165 mg/dL', ['loinc' => '2089-1', 'direction' => 'above']),
        ];

        $ids = array_map(static fn(FiredTrigger $t): string => $t->id, (new GuidelineTriggers())->fire($this->assembled($facts), $this->aged(50), $this->today));

        self::assertSame(['lipids', 'anemia'], $ids);
    }

    public function testVersionMirrorsTheFile(): void
    {
        $file = json_decode((string) file_get_contents(self::MODULE . '/contracts/guideline_triggers.json'), true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($file);
        self::assertSame(GuidelineTriggers::VERSION, $file['version']);
    }

    public function testAbnormalBloodPressureFiresHypertension(): void
    {
        $bp = $this->fact(1, FactCategory::VitalAbnormal, 'Blood pressure 152/94 mmHg on 2026-09-10 (above 140/90)', ['vital' => 'bp', 'direction' => 'above']);

        $fired = $this->fire($this->assembled([$bp]), $this->aged(50));

        self::assertArrayHasKey('hypertension', $fired);
        self::assertSame([$bp->id], $fired['hypertension']->factIds);
    }

    public function testScreeningFiresOnAgeAndBmiWithoutDiabetesOrARecentA1c(): void
    {
        $a1c = $this->fact(2, FactCategory::LabNormal, 'Hemoglobin A1c 5.4 % on 2026-09-10 (reference range 4-5.6 %)', ['loinc' => '4548-4']);

        self::assertArrayHasKey('screening', $this->fire($this->assembled([], [], 31.2), $this->aged(50)));
        self::assertArrayNotHasKey('screening', $this->fire($this->assembled([], [], 31.2), $this->aged(72)));
        self::assertArrayNotHasKey('screening', $this->fire($this->assembled([], ['Type 2 diabetes mellitus'], 31.2), $this->aged(50)));
        self::assertArrayNotHasKey('screening', $this->fire($this->assembled([$a1c], [], 31.2), $this->aged(50)));
    }

    public function testEveryRuleMatcherNamesAKnownCategory(): void
    {
        $file = json_decode((string) file_get_contents(self::MODULE . '/contracts/guideline_triggers.json'), true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($file);
        self::assertIsArray($file['rules']);
        foreach ($file['rules'] as $rule) {
            self::assertIsArray($rule);
            self::assertIsString($rule['id']);
            foreach (['any', 'all', 'exclude'] as $list) {
                $matchers = $rule[$list] ?? [];
                self::assertIsArray($matchers);
                foreach ($matchers as $matcher) {
                    self::assertIsArray($matcher);
                    $present = $matcher['category_present'] ?? [];
                    self::assertIsArray($present);
                    $categories = isset($matcher['category']) ? [$matcher['category']] : [];
                    foreach ([...$categories, ...$present] as $category) {
                        self::assertIsString($category);
                        self::assertNotNull(FactCategory::tryFrom($category), "rule {$rule['id']} names unknown category $category");
                    }
                }
            }
        }
    }

    // -- Review fix pass ---------------------------------------------------------

    public function testNystatinDoesNotFireLipids(): void
    {
        $nystatin = $this->fact(1, FactCategory::MedicationActive, 'Nystatin 100000 UNT/ML Oral Suspension', ['drug' => 'Nystatin 100000 UNT/ML Oral Suspension']);
        $atorvastatin = $this->fact(2, FactCategory::MedicationActive, 'Atorvastatin 20 MG', ['drug' => 'Atorvastatin 20 MG']);

        self::assertArrayNotHasKey('lipids', $this->fire($this->assembled([$nystatin]), $this->aged(55)));
        self::assertArrayHasKey('lipids', $this->fire($this->assembled([$atorvastatin]), $this->aged(55)));
    }

    public function testScreeningFiresOnOverweightNotUnderweightAndNeedsNoAbnormalFact(): void
    {
        $underweight = $this->fact(1, FactCategory::VitalAbnormal, 'BMI 17 on 2026-09-10 (below 18.5)', ['vital' => 'bmi', 'direction' => 'below']);

        self::assertArrayNotHasKey('screening', $this->fire($this->assembled([$underweight], [], 17.0), $this->aged(50)));
        self::assertArrayHasKey('screening', $this->fire($this->assembled([], [], 27.4), $this->aged(50)), 'overweight fires without an abnormal fact');
        self::assertArrayNotHasKey('screening', $this->fire($this->assembled([], [], 24.0), $this->aged(50)));
    }

    public function testPregnancySuppressesLipidsAndScreening(): void
    {
        $ldl = $this->fact(1, FactCategory::LabAbnormal, 'LDL Cholesterol 165 mg/dL', ['loinc' => '2089-1', 'direction' => 'above']);

        self::assertArrayNotHasKey('lipids', $this->fire($this->assembled([$ldl], ['Pregnancy, first trimester']), $this->aged(35)));
        self::assertArrayNotHasKey('screening', $this->fire($this->assembled([], ['Pregnancy, first trimester'], 31.0), $this->aged(35)));
    }

    public function testChildrenDoNotFireTheAdultRules(): void
    {
        $bp = $this->fact(1, FactCategory::VitalAbnormal, 'Blood pressure 152/94 mmHg', ['vital' => 'bp', 'direction' => 'above']);
        $hgb = $this->fact(2, FactCategory::LabAbnormal, 'Hemoglobin 10.2 g/dL', ['loinc' => '718-7', 'direction' => 'below']);
        $egfr = $this->fact(3, FactCategory::LabAbnormal, 'eGFR 52', ['loinc' => '62238-1', 'direction' => 'below']);

        $fired = $this->fire($this->assembled([$bp, $hgb, $egfr], ['Type 2 diabetes mellitus']), $this->aged(12));

        self::assertSame([], $fired);
    }

    public function testTypeOneDiabetesMatchesItsSpellingsAndNotHypersensitivity(): void
    {
        $a1c = $this->fact(1, FactCategory::LabAbnormal, 'Hemoglobin A1c 8.8 %', ['loinc' => '4548-4', 'direction' => 'above']);

        self::assertArrayNotHasKey('diabetes', $this->fire($this->assembled([$a1c], ['Diabetes mellitus type I']), $this->aged(40)));
        self::assertArrayNotHasKey('diabetes', $this->fire($this->assembled([$a1c], ['Type 1 diabetes mellitus']), $this->aged(40)));
        self::assertArrayNotHasKey('diabetes', $this->fire($this->assembled([$a1c], ['T1DM']), $this->aged(40)));
        self::assertArrayHasKey('diabetes', $this->fire($this->assembled([$a1c], ['Hypersensitivity type 1']), $this->aged(40)));
    }

    public function testFiredTriggersCarryTheirOwnFactLinesAndTheProblemList(): void
    {
        $ldl = $this->fact(1, FactCategory::LabAbnormal, 'LDL Cholesterol 165 mg/dL on 2026-09-10', ['loinc' => '2089-1', 'direction' => 'above']);
        $hgb = $this->fact(2, FactCategory::LabAbnormal, 'Hemoglobin 10.2 g/dL on 2026-09-10', ['loinc' => '718-7', 'direction' => 'below']);

        $fired = $this->fire($this->assembled([$ldl, $hgb], ['Essential hypertension']), $this->aged(50));

        self::assertSame(['LDL Cholesterol 165 mg/dL on 2026-09-10', 'On the problem list: Essential hypertension'], $fired['lipids']->contextLines);
        self::assertSame(['Hemoglobin 10.2 g/dL on 2026-09-10', 'On the problem list: Essential hypertension'], $fired['anemia']->contextLines);
    }

    public function testCacheKeyChangesWithTriggersAgeSexAndIndexVersion(): void
    {
        $ldl = $this->fact(1, FactCategory::LabAbnormal, 'LDL Cholesterol 165 mg/dL', ['loinc' => '2089-1', 'direction' => 'above']);
        $fired = (new GuidelineTriggers())->fire($this->assembled([$ldl]), $this->aged(55), $this->today);
        $base = GuidelineTriggers::cacheKey('facts', $fired, 55, 'F', 'model', 'v1');

        self::assertSame($base, GuidelineTriggers::cacheKey('facts', $fired, 55, 'F', 'model', 'v1'));
        self::assertNotSame($base, GuidelineTriggers::cacheKey('facts', [], 55, 'F', 'model', 'v1'));
        self::assertNotSame($base, GuidelineTriggers::cacheKey('facts', $fired, 82, 'F', 'model', 'v1'));
        self::assertNotSame($base, GuidelineTriggers::cacheKey('facts', $fired, 55, 'M', 'model', 'v1'));
        self::assertNotSame($base, GuidelineTriggers::cacheKey('facts', $fired, 55, 'F', 'model', 'v2'));
    }
}
