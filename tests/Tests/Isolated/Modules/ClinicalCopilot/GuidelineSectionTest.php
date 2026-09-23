<?php

/**
 * Pins the "what the guidelines say about this chart" section: cards the
 * critic rejected are dropped, unknown verdicts keep a "not assessed"
 * label, an unreachable sidecar yields an unavailable section with no
 * cards, and the section survives a round trip through the cache.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Modules\ClinicalCopilot;

use OpenEMR\Modules\ClinicalCopilot\Documents\RunResult;
use OpenEMR\Modules\ClinicalCopilot\GuidelineManifest;
use OpenEMR\Modules\ClinicalCopilot\Guidelines\FiredTrigger;
use OpenEMR\Modules\ClinicalCopilot\Guidelines\GuidelineSection;
use OpenEMR\Modules\ClinicalCopilot\Guidelines\GuidelineTriggers;
use OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Support\ModuleAutoload;
use PHPUnit\Framework\TestCase;

final class GuidelineSectionTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        ModuleAutoload::register();
    }

    /** @return list<FiredTrigger> */
    private function triggers(): array
    {
        return [
            new FiredTrigger('lipids', 'Cholesterol management', 'q1', 'acc-aha-2018-cholesterol', ['0a1b2c3d'], []),
            new FiredTrigger('diabetes', 'Diabetes care', 'q2', 'ada-2025-standards', [], ['on the problem list: Type 2 diabetes mellitus']),
            new FiredTrigger('anemia', 'Anemia', 'q3', 'anemia-adults-primary-care', ['1a1b2c3d'], []),
        ];
    }

    private function runResult(): RunResult
    {
        $chunk = static fn(string $id, string $source): array => ['chunk_id' => $id, 'source_id' => $source, 'section' => 'Title > Section', 'quote' => 'A passage.', 'score' => 0.8];
        return RunResult::fromArray([
            'correlation_id' => 'corr-1',
            'extractions' => [],
            'chunks' => [],
            'evidence' => [
                ['trigger_id' => 'lipids', 'chunks' => [$chunk('aaaaaaaaaaaa', 'acc-aha-2018-cholesterol')], 'applicable' => false, 'reason' => 'ages 40 to 75; the patient is 82'],
                ['trigger_id' => 'diabetes', 'chunks' => [$chunk('bbbbbbbbbbbb', 'ada-2025-standards'), $chunk('cccccccccccc', 'ada-2025-standards')], 'applicable' => true, 'reason' => 'no restriction stated'],
                ['trigger_id' => 'anemia', 'chunks' => [$chunk('dddddddddddd', 'anemia-adults-primary-care')], 'applicable' => null, 'reason' => null],
            ],
            'handoffs' => [],
            'usage' => [],
        ]);
    }

    public function testCardsWithApplicableFalseAreDroppedAndCounted(): void
    {
        $section = GuidelineSection::fromRun($this->triggers(), $this->runResult(), new GuidelineManifest());

        self::assertSame('ok', $section->status);
        self::assertSame(['diabetes', 'anemia'], array_map(static fn($c) => $c->triggerId, $section->cards));
        self::assertSame(1, $section->dropped);
        self::assertSame(GuidelineTriggers::VERSION, $section->triggersVersion);
    }

    public function testCheckedLabelReflectsTheCriticOutcome(): void
    {
        $cards = GuidelineSection::fromRun($this->triggers(), $this->runResult(), new GuidelineManifest())->cards;

        self::assertSame('Checked against age, sex and the problem list', $cards[0]->checkedLabel());
        self::assertSame('Guideline text; applicability not assessed', $cards[1]->checkedLabel());
        self::assertSame('no restriction stated', $cards[0]->reason);
        self::assertSame(['on the problem list: Type 2 diabetes mellitus'], $cards[0]->reasons);
        self::assertSame(['1a1b2c3d'], $cards[1]->becauseFactIds);
        self::assertCount(2, $cards[0]->chunks);
        self::assertSame('bbbbbbbbbbbb', $cards[0]->chunks[0]->chunkId);
    }

    public function testATriggerWithNoPassagesYieldsNoCard(): void
    {
        $run = RunResult::fromArray(['correlation_id' => 'c', 'extractions' => [], 'chunks' => [], 'evidence' => [['trigger_id' => 'lipids', 'chunks' => [], 'applicable' => null, 'reason' => null]], 'handoffs' => [], 'usage' => []]);

        $section = GuidelineSection::fromRun([$this->triggers()[0]], $run, new GuidelineManifest());

        self::assertSame('ok', $section->status);
        self::assertSame([], $section->cards);
    }

    public function testUnavailableAndNoTriggersHaveNoCards(): void
    {
        self::assertSame('unavailable', GuidelineSection::none('unavailable')->status);
        self::assertSame([], GuidelineSection::none('unavailable')->cards);
        self::assertSame('no_triggers', GuidelineSection::none('no_triggers')->status);
    }

    public function testSectionRoundTripsThroughItsArrayForm(): void
    {
        $section = GuidelineSection::fromRun($this->triggers(), $this->runResult(), new GuidelineManifest());

        $again = GuidelineSection::fromArray($section->toArray());

        self::assertSame($section->toArray(), $again->toArray());
        $cards = $section->toArray()['cards'];
        self::assertIsArray($cards);
        $card = $cards[0];
        self::assertIsArray($card);
        self::assertSame(['trigger_id', 'label', 'because_fact_ids', 'reasons', 'chunks', 'applicable', 'reason', 'checked_label'], array_keys($card));
        $chunks = $card['chunks'];
        self::assertIsArray($chunks);
        $first = $chunks[0];
        self::assertIsArray($first);
        self::assertSame('ADA Standards of Care in Diabetes 2025 (summary of glycemic targets, screening and CKD)', $first['title']);
    }

    public function testRunResultTreatsAMissingEvidenceListAsEmpty(): void
    {
        $run = RunResult::fromArray(['correlation_id' => 'c', 'extractions' => [], 'chunks' => [], 'handoffs' => [], 'usage' => []]);
        self::assertSame([], $run->evidence);
    }
}
