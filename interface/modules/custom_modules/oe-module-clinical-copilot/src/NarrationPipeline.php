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
use OpenEMR\Modules\ClinicalCopilot\Llm\LlmCompletion;
use OpenEMR\Modules\ClinicalCopilot\Llm\LlmException;
use OpenEMR\Modules\ClinicalCopilot\Ops\StepRecorder;

final class NarrationPipeline
{
    public function __construct(
        private readonly LanguageModel $llm,
        private readonly Verifier $verifier,
        private readonly OmissionGuard $guard,
        private readonly BriefingCache $cache,
        private readonly Prompt $prompt = new Prompt(),
        private readonly StepRecorder $steps = new StepRecorder(),
    ) {
    }

    public function steps(): StepRecorder
    {
        return $this->steps;
    }

    public function cacheKey(AssembledFacts $assembled): string
    {
        return hash('sha256', $assembled->facts()->hash() . '|' . Prompt::VERSION . '|' . $this->llm->model());
    }

    public function brief(AssembledFacts $assembled): BriefingResult
    {
        $facts = $assembled->facts();
        $key = $this->cacheKey($assembled);

        $cached = $this->steps->measure('cache_lookup', fn() => $this->cache->get($key), static fn(?array $hit) => ['hit' => $hit !== null]);
        if ($cached !== null) {
            $verified = $this->verify($this->narrationFrom($cached), $facts);
            return new BriefingResult($verified->kept(), $verified->strippedCount(), $this->omitted($verified, $facts), null, true, false, 0, 0);
        }

        try {
            $completion = $this->complete(
                $this->prompt->briefingSystem(),
                $this->prompt->briefingUser($assembled),
                'briefing',
                $this->prompt->briefingSchema(),
            );
        } catch (LlmException $e) {
            $empty = new VerificationResult([], []);
            return new BriefingResult([], 0, $this->omitted($empty, $facts), $e->statusLabel(), false, false, 0, 0);
        }

        $verified = $this->verify($this->narrationFrom($completion->data), $facts);
        if (!$verified->isTotalFailure()) {
            $this->steps->measure('cache_store', fn() => $this->cache->put($key, $completion->data));
        }
        return new BriefingResult(
            $verified->kept(),
            $verified->strippedCount(),
            $this->omitted($verified, $facts),
            null,
            false,
            $verified->isTotalFailure(),
            $completion->promptTokens,
            $completion->completionTokens,
        );
    }

    /** @param list<array{role: string, text: string}> $transcript */
    public function answer(AssembledFacts $assembled, string $question, array $transcript, ?PatientId $open = null): AnswerResult
    {
        if ($open !== null && QuestionScope::refersToAnotherPatient($question, $open)) {
            $this->steps->add('scope_check', (int) round(microtime(true) * 1000), 0, null, ['refused' => 'other_patient']);
            return new AnswerResult('not_in_facts', [], 0, null, 0, 0);
        }
        try {
            $completion = $this->complete(
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
        $verified = $this->verify($this->narrationFrom($completion->data), $assembled->facts());
        return new AnswerResult($type, $verified->kept(), $verified->strippedCount(), null, $completion->promptTokens, $completion->completionTokens);
    }

    /** @param array<string, mixed> $schema */
    private function complete(string $system, string $user, string $schemaName, array $schema): LlmCompletion
    {
        return $this->steps->measure(
            'llm.' . $schemaName,
            fn() => $this->llm->complete($system, $user, $schemaName, $schema),
            fn(LlmCompletion $c) => ['model' => $this->llm->model(), 'prompt_tokens' => $c->promptTokens, 'completion_tokens' => $c->completionTokens],
        );
    }

    private function verify(Narration $narration, FactSet $facts): VerificationResult
    {
        return $this->steps->measure(
            'verify',
            fn() => $this->verifier->verify($narration, $facts),
            static fn(VerificationResult $v) => ['kept' => count($v->kept()), 'stripped' => $v->strippedCount(), 'total_failure' => $v->isTotalFailure()],
        );
    }

    /** @return list<Fact> */
    private function omitted(VerificationResult $verified, FactSet $facts): array
    {
        return $this->steps->measure(
            'omission_guard',
            fn() => $this->guard->omitted($verified, $facts),
            static fn(array $omitted) => ['appended' => count($omitted)],
        );
    }

    /**
     * Models sometimes leak inline "[id]" citations or JSON punctuation into
     * sentence text. Citations live in fact_ids, so remove the inline echoes,
     * then trim anything that is not sentence text from both ends.
     *
     * @param list<string> $ids
     */
    private function scrub(string $text, array $ids): string
    {
        $text = preg_replace_callback(
            '/\s*\[([A-Za-z0-9]+(?:\s*,\s*[A-Za-z0-9]+)*)\]/',
            static function (array $m) use ($ids): string {
                $tokens = preg_split('/\s*,\s*/', $m[1]) ?: [];
                $allCited = array_diff($tokens, $ids) === [];
                $allHexIds = array_filter($tokens, static fn(string $t) => !preg_match('/^[0-9a-f]{8}$/', $t)) === [];
                return ($allCited || $allHexIds) ? '' : $m[0];
            },
            $text
        ) ?? $text;
        $text = preg_replace('/^[\s{}\[\]",:]*(?:text"?\s*:\s*"?)?/', '', $text) ?? $text;
        $text = preg_replace('/[\s{}\[\]",:]+$/', '', $text) ?? $text;
        $text = trim(preg_replace('/\s+([.,;:!?])/', '$1', $text) ?? $text);
        if ($text !== '' && !preg_match('/[.!?]$/', $text)) {
            $text .= '.';
        }
        return $text;
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
                $sentences[] = new Sentence($this->scrub($item['text'], $ids), $ids);
            }
        }
        return new Narration($sentences);
    }
}
