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
    private function assembled(array $facts, array $activeProblems = []): AssembledFacts
    {
        return new AssembledFacts(new FactSet($facts), null, $activeProblems);
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
}
