<?php

/**
 * Verified follow-up answer.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot;

/**
 * Outcome of a follow-up question. $answerType is one of 'cited'
 * (answer grounded in facts), 'not_in_facts' (model or scope check said
 * the chart cannot answer it), or 'error' (model call failed; see $status).
 */
final readonly class AnswerResult
{
    /** @param list<Sentence> $sentences kept sentences (after the Verifier) */
    public function __construct(
        public string $answerType,
        public array $sentences,
        public int $strippedCount,
        public ?string $status,
        public int $promptTokens,
        public int $completionTokens,
        /** Week 2: the guideline chunks cited by kept sentences, for the "From guidelines" block. @var list<EvidenceChunk> */
        public array $evidence = [],
        /** Week 2: how the guideline evidence was retrieved (handoffs, reranked), for the trace. @var list<array{from: string, to: string, reason: string, state_keys_changed: list<string>, ms: int}> */
        public array $handoffs = [],
    ) {
    }
}
