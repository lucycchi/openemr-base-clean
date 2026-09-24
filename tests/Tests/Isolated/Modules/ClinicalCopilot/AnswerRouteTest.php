<?php

/**
 * AnswerRoute: the answer-stage hops PHP appends to the graph's route log.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Modules\ClinicalCopilot;

use OpenEMR\Modules\ClinicalCopilot\AnswerResult;
use OpenEMR\Modules\ClinicalCopilot\AnswerRoute;
use OpenEMR\Modules\ClinicalCopilot\BriefingResult;
use OpenEMR\Modules\ClinicalCopilot\Contracts;
use OpenEMR\Modules\ClinicalCopilot\Sentence;
use OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Support\ModuleAutoload;
use PHPUnit\Framework\TestCase;

final class AnswerRouteTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        ModuleAutoload::register();
    }

    /**
     * @param list<array{from: string, to: string, reason: string, state_keys_changed: list<string>, ms: int}> $hops
     * @return list<string>
     */
    private static function route(array $hops): array
    {
        foreach ($hops as $hop) {
            self::assertSame([], Contracts::violations('handoff', $hop), 'every hop conforms to the handoff contract');
        }
        return array_map(static fn(array $h): string => $h['from'] . '>' . $h['to'] . ':' . $h['reason'], $hops);
    }

    private static function briefing(array $sentences, int $stripped, ?string $status, bool $fromCache = false): BriefingResult
    {
        return new BriefingResult($sentences, $stripped, [], $status, $fromCache, false, 0, 0);
    }

    public function testAVerifiedBriefingEndsWithTheVerifierSayingReady(): void
    {
        $hops = AnswerRoute::forBriefing(self::briefing([new Sentence('Kept.', ['rx0001'])], 1, null), 1200);
        self::assertSame(['supervisor>answer_writer:evidence_ready', 'answer_writer>verifier:draft_written', 'verifier>done:answer_verified'], self::route($hops));
        self::assertSame(1200, $hops[1]['ms'], 'the writing step carries the model call time');
    }

    public function testABriefingWithEverySentenceStrippedSaysSo(): void
    {
        self::assertSame(['supervisor>answer_writer:evidence_ready', 'answer_writer>verifier:draft_written', 'verifier>done:all_stripped'], self::route(AnswerRoute::forBriefing(self::briefing([], 3, 'all stripped'), 900)));
    }

    public function testABriefingWhoseModelCallFailedStopsAtTheWriter(): void
    {
        self::assertSame(['supervisor>answer_writer:evidence_ready', 'answer_writer>done:model_failed'], self::route(AnswerRoute::forBriefing(self::briefing([], 0, 'AI summary unavailable'), 24000)));
    }

    public function testACachedBriefingGoesStraightToTheVerifier(): void
    {
        self::assertSame(['supervisor>verifier:cached_draft', 'verifier>done:answer_verified'], self::route(AnswerRoute::forBriefing(self::briefing([new Sentence('Kept.', ['rx0001'])], 0, null, true), 0)));
    }

    public function testABriefingWithNothingToStateIsNoClaims(): void
    {
        self::assertSame(['supervisor>answer_writer:evidence_ready', 'answer_writer>verifier:draft_written', 'verifier>done:no_claims'], self::route(AnswerRoute::forBriefing(self::briefing([], 0, null), 800)));
    }

    public function testACitedAnswerIsVerified(): void
    {
        $answer = new AnswerResult('cited', [new Sentence('Kept.', ['rx0001'])], 0, null, 400, 30);
        self::assertSame(['supervisor>answer_writer:evidence_ready', 'answer_writer>verifier:draft_written', 'verifier>done:answer_verified'], self::route(AnswerRoute::forAnswer($answer, 700, 1)));
    }

    public function testAModelRefusalIsVerifiedAsARefusal(): void
    {
        $answer = new AnswerResult('not_in_facts', [], 0, null, 400, 10);
        self::assertSame(['supervisor>answer_writer:evidence_ready', 'answer_writer>verifier:draft_written', 'verifier>done:answer_refused'], self::route(AnswerRoute::forAnswer($answer, 650, 1)));
    }

    public function testAQuestionRefusedBeforeAnyModelCallNeverReachesTheWriter(): void
    {
        self::assertSame(['supervisor>done:answer_refused'], self::route(AnswerRoute::forAnswer(new AnswerResult('not_in_facts', [], 0, null, 0, 0), 0, 0)));
    }

    public function testAnAnswerWhoseModelCallFailedStopsAtTheWriter(): void
    {
        self::assertSame(['supervisor>answer_writer:evidence_ready', 'answer_writer>done:model_failed'], self::route(AnswerRoute::forAnswer(new AnswerResult('error', [], 0, 'timeout', 0, 0), 24000, 2)));
    }

    public function testTheEvalOutcomeIsTheAnswerStagesFinalVerdict(): void
    {
        $graph = [['from' => 'supervisor', 'to' => 'evidence_retriever', 'reason' => 'question_present', 'state_keys_changed' => [], 'ms' => 1], ['from' => 'supervisor', 'to' => 'done', 'reason' => 'worker_finished', 'state_keys_changed' => [], 'ms' => 0]];
        $answer = new AnswerResult('cited', [new Sentence('Kept.', ['rx0001'])], 0, null, 400, 30);
        self::assertSame('answer_verified', AnswerRoute::outcomeOf([...$graph, ...AnswerRoute::forAnswer($answer, 700, 1)]));
        self::assertSame('model_failed', AnswerRoute::outcomeOf(AnswerRoute::forAnswer(new AnswerResult('error', [], 0, 'timeout', 0, 0), 1, 1)));
        self::assertNull(AnswerRoute::outcomeOf($graph), 'the graph alone has no answer verdict');
    }

    public function testAnAnswerWithEverySentenceStrippedSaysSo(): void
    {
        self::assertSame(['supervisor>answer_writer:evidence_ready', 'answer_writer>verifier:draft_written', 'verifier>done:all_stripped'], self::route(AnswerRoute::forAnswer(new AnswerResult('cited', [], 2, null, 400, 30), 700, 1)));
    }
}
