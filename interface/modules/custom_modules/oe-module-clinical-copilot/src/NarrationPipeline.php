<?php

/**
 * Facts in, verified narration out: model call, verifier, omission guard, cache.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot;

use OpenEMR\Modules\ClinicalCopilot\Llm\LanguageModel;
use OpenEMR\Modules\ClinicalCopilot\Llm\LlmException;

final class NarrationPipeline
{
    public function __construct(
        private readonly LanguageModel $llm,
        private readonly Verifier $verifier,
        private readonly OmissionGuard $guard,
        private readonly BriefingCache $cache,
        private readonly Prompt $prompt = new Prompt(),
    ) {
    }

    public function cacheKey(AssembledFacts $assembled): string
    {
        return hash('sha256', $assembled->facts()->hash() . '|' . Prompt::VERSION . '|' . $this->llm->model());
    }

    public function brief(AssembledFacts $assembled): BriefingResult
    {
        $facts = $assembled->facts();
        $key = $this->cacheKey($assembled);

        $cached = $this->cache->get($key);
        if ($cached !== null) {
            $verified = $this->verifier->verify($this->narrationFrom($cached), $facts);
            return new BriefingResult($verified->kept(), $verified->strippedCount(), $this->guard->omitted($verified, $facts), null, true, false, 0, 0);
        }

        try {
            $completion = $this->llm->complete(
                $this->prompt->briefingSystem(),
                $this->prompt->briefingUser($assembled),
                'briefing',
                $this->prompt->briefingSchema(),
            );
        } catch (LlmException $e) {
            $empty = new VerificationResult([], []);
            return new BriefingResult([], 0, $this->guard->omitted($empty, $facts), $e->statusLabel(), false, false, 0, 0);
        }

        $verified = $this->verifier->verify($this->narrationFrom($completion->data), $facts);
        if (!$verified->isTotalFailure()) {
            $this->cache->put($key, $completion->data);
        }
        return new BriefingResult(
            $verified->kept(),
            $verified->strippedCount(),
            $this->guard->omitted($verified, $facts),
            null,
            false,
            $verified->isTotalFailure(),
            $completion->promptTokens,
            $completion->completionTokens,
        );
    }

    /** @param list<array{role: string, text: string}> $transcript */
    public function answer(AssembledFacts $assembled, string $question, array $transcript): AnswerResult
    {
        try {
            $completion = $this->llm->complete(
                $this->prompt->followUpSystem(),
                $this->prompt->followUpUser($assembled, $question, $transcript),
                'follow_up',
                $this->prompt->followUpSchema(),
            );
        } catch (LlmException $e) {
            return new AnswerResult('error', [], 0, $e->statusLabel(), 0, 0);
        }

        $type = $completion->data['answer_type'] ?? null;
        $type = $type === 'not_in_facts' ? 'not_in_facts' : 'cited';
        $verified = $this->verifier->verify($this->narrationFrom($completion->data), $assembled->facts());
        return new AnswerResult($type, $verified->kept(), $verified->strippedCount(), null, $completion->promptTokens, $completion->completionTokens);
    }

    /** @param array<string, mixed> $data */
    private function narrationFrom(array $data): Narration
    {
        $sentences = [];
        $raw = $data['sentences'] ?? [];
        if (is_array($raw)) {
            foreach ($raw as $item) {
                if (!is_array($item) || !is_string($item['text'] ?? null)) {
                    continue;
                }
                $ids = [];
                foreach (is_array($item['fact_ids'] ?? null) ? $item['fact_ids'] : [] as $id) {
                    if (is_string($id)) {
                        $ids[] = $id;
                    }
                }
                $sentences[] = new Sentence($item['text'], $ids);
            }
        }
        return new Narration($sentences);
    }
}
