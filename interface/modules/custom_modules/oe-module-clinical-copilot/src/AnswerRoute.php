<?php

/**
 * The answer stage of the route log (week 2): the hops PHP appends after the
 * sidecar graph's own, so one handoff log shows the whole route, including
 * when the final answer was ready.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot;

use OpenEMR\Modules\ClinicalCopilot\Documents\Handoff;

/**
 * The sidecar's LangGraph supervisor decides when extraction and evidence
 * retrieval are needed; its graph ends when the evidence is ready. The
 * answer itself is written and verified here in PHP, which owns the chart,
 * the access checks and the Verifier, so chart facts never go to the
 * sidecar. These hops record that stage in the same shape and log as the
 * graph's, so "when the final answer is ready" is an inspectable decision:
 *
 *   supervisor -> answer_writer   evidence_ready   the model writes a draft
 *   answer_writer -> verifier     draft_written    the Verifier checks it
 *   verifier -> done              answer_verified  at least one cited sentence kept
 *                                 answer_refused   a refusal (not in the facts)
 *                                 all_stripped     every sentence was stripped
 *                                 no_claims        nothing to state, nothing stripped
 *   answer_writer -> done         model_failed     the model call failed
 *   supervisor -> verifier        cached_draft     a cached briefing, re-verified on read
 *   supervisor -> done            answer_refused   refused before any model call
 *
 * `ms` is the time of the step that just ended, as in the graph: the model
 * call's time on the answer_writer hop, 0 where the step is in-process.
 */
final class AnswerRoute
{
    /** The verifier's possible final verdicts: the per-encounter eval outcome. */
    public const OUTCOMES = ['answer_verified', 'answer_refused', 'all_stripped', 'no_claims', 'model_failed'];

    /**
     * The encounter's eval outcome: the reason on the answer stage's final hop, or null
     * when no answer stage ran (no model configured, an error before the answer).
     *
     * @param list<array{from: string, to: string, reason: string, state_keys_changed: list<string>, ms: int}> $handoffs
     */
    public static function outcomeOf(array $handoffs): ?string
    {
        foreach (array_reverse($handoffs) as $hop) {
            if ($hop['to'] === 'done' && in_array($hop['reason'], self::OUTCOMES, true)) {
                return $hop['reason'];
            }
        }
        return null;
    }

    /**
     * @return list<array{from: string, to: string, reason: string, state_keys_changed: list<string>, ms: int}>
     */
    public static function forBriefing(BriefingResult $briefing, int $llmMs): array
    {
        $outcome = self::outcome($briefing->sentences !== [], $briefing->strippedCount, false);
        if ($briefing->fromCache) {
            return self::hops([['supervisor', 'verifier', 'cached_draft', 0], ['verifier', 'done', $outcome, 0]]);
        }
        if ($briefing->sentences === [] && $briefing->strippedCount === 0 && $briefing->status !== null) {
            return self::hops([['supervisor', 'answer_writer', 'evidence_ready', 0], ['answer_writer', 'done', 'model_failed', $llmMs]]);
        }
        return self::hops([['supervisor', 'answer_writer', 'evidence_ready', 0], ['answer_writer', 'verifier', 'draft_written', $llmMs], ['verifier', 'done', $outcome, 0]]);
    }

    /**
     * @param int $llmAttempts model calls made; 0 means the question was refused before any
     * @return list<array{from: string, to: string, reason: string, state_keys_changed: list<string>, ms: int}>
     */
    public static function forAnswer(AnswerResult $answer, int $llmMs, int $llmAttempts): array
    {
        if ($answer->answerType === 'error') {
            return self::hops([['supervisor', 'answer_writer', 'evidence_ready', 0], ['answer_writer', 'done', 'model_failed', $llmMs]]);
        }
        $refused = $answer->answerType !== 'cited';
        if ($refused && $llmAttempts === 0) {
            return self::hops([['supervisor', 'done', 'answer_refused', 0]]);
        }
        $outcome = self::outcome($answer->sentences !== [], $answer->strippedCount, $refused);
        return self::hops([['supervisor', 'answer_writer', 'evidence_ready', 0], ['answer_writer', 'verifier', 'draft_written', $llmMs], ['verifier', 'done', $outcome, 0]]);
    }

    private static function outcome(bool $kept, int $stripped, bool $refused): string
    {
        return match (true) {
            $refused => 'answer_refused',
            $kept => 'answer_verified',
            $stripped > 0 => 'all_stripped',
            default => 'no_claims',
        };
    }

    /**
     * @param list<array{string, string, string, int}> $steps from, to, reason, ms
     * @return list<array{from: string, to: string, reason: string, state_keys_changed: list<string>, ms: int}>
     */
    private static function hops(array $steps): array
    {
        return array_map(static fn(array $s): array => (new Handoff($s[0], $s[1], $s[2], [], $s[3]))->toArray(), $steps);
    }
}
