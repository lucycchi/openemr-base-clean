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

/**
 * The core of the copilot. Takes a patient's chart facts (already pulled from
 * the database and typed by FactAssembler) and produces text a clinician can
 * trust. Every stage is wrapped so the model can never be the last word:
 *
 *   facts -> [cache?] -> LLM -> Verifier (drop uncited sentences)
 *         -> OmissionGuard (append facts the model skipped) -> result
 *
 * "final" means no subclassing; "readonly" on a property means it is set once
 * in the constructor and never reassigned.
 */
final class NarrationPipeline
{
    /**
     * Every collaborator is injected, and the first four are interfaces. The
     * LLM is just another dependency, so unit tests hand in a fake model and a
     * fake cache and never touch the network or the database.
     *
     * `private readonly Type $name` in a constructor is PHP shorthand for
     * "declare this field and assign the argument to it".
     */
    public function __construct(
        private readonly LanguageModel $llm,       // talks to OpenAI (or a fake in tests)
        private readonly Verifier $verifier,       // keeps only sentences that cite a real fact
        private readonly OmissionGuard $guard,     // finds facts the model left out
        private readonly BriefingCache $cache,     // stores generated briefings (DB-backed in prod)
        private readonly Prompt $prompt = new Prompt(),            // versioned prompt text + JSON schemas
        private readonly StepRecorder $steps = new StepRecorder(), // per-stage timing for tracing
    ) {
    }

    /** How many HTTP calls the last model request took (0 = none, 2 = one retry). Read by the controller for logs. */
    private int $llmAttempts = 0;

    /** Exposes the timing recorder so the controller can attach it to the Langfuse trace. */
    public function steps(): StepRecorder
    {
        return $this->steps;
    }

    /**
     * Cache key = hash(facts) + prompt version + model name.
     *
     * A cached briefing is only reusable if all three are unchanged: the chart
     * data, the wording of the prompt, and the model that produced it. This is
     * what makes the overnight pre-warm safe — if a lab result lands after the
     * warm run, the facts hash changes and the stale entry is simply never
     * looked up. Bumping Prompt::VERSION is the deliberate way to flush every
     * cached briefing at once.
     */
    public function cacheKey(AssembledFacts $assembled): string
    {
        return hash('sha256', $assembled->facts()->hash() . '|' . Prompt::VERSION . '|' . $this->llm->model());
    }

    /**
     * Chart-open briefing: the short summary shown when a clinician opens a
     * patient. Returns a BriefingResult, which carries the kept sentences,
     * how many were stripped, any omitted facts, an error label, whether it
     * was a cache hit, and token counts.
     */
    public function brief(AssembledFacts $assembled): BriefingResult
    {
        $facts = $assembled->facts();
        $key = $this->cacheKey($assembled);

        // 1. Cache lookup. `measure` runs the closure, times it, and records the
        //    name plus the metadata returned by the third closure.
        $cached = $this->steps->measure('cache_lookup', fn() => $this->cache->get($key), static fn(?CachedNarration $hit) => ['hit' => $hit !== null]);
        if ($cached !== null) {
            // Cache hit — but still run the verifier. It is cheap and
            // deterministic, and if its rules have tightened since this entry
            // was written, the stale sentences get stripped rather than shown.
            $verified = $this->verify($this->narrationFrom($cached->data), $facts);
            // Positional args: kept sentences, stripped count, omitted facts, error label,
            // cacheHit=true, totalFailure=false, promptTokens=0, completionTokens=0, generatedAt.
            return new BriefingResult($verified->kept(), $verified->strippedCount(), $this->omitted($verified, $facts), null, true, false, 0, 0, $cached->generatedAt);
        }

        // 2. Cache miss — call the model. The prompt object supplies the system
        //    message, the user message built from the facts, and a JSON schema
        //    the model's reply must conform to (structured output).
        try {
            $completion = $this->complete(
                $this->prompt->briefingSystem(),
                $this->prompt->briefingUser($assembled),
                'briefing',
                $this->prompt->briefingSchema(),
            );
        } catch (LlmException $e) {
            // Model down / timed out / rate-limited / refused. Do NOT blank the
            // panel: return an empty narration but still run the omission guard,
            // so the clinician sees the raw facts. statusLabel() is a short
            // machine-readable reason the UI turns into a message.
            $empty = new VerificationResult([], []);
            return new BriefingResult([], 0, $this->omitted($empty, $facts), $e->statusLabel(), false, false, 0, 0);
        }

        // 3. Verify: every sentence must cite at least one real fact id, or it is dropped.
        $verified = $this->verify($this->narrationFrom($completion->data), $facts);

        // 4. Cache the *raw* model output (not the verified form) — but only if
        //    the verifier kept something. A total failure is never cached, so a
        //    bad generation cannot get pinned for the rest of the day.
        if (!$verified->isTotalFailure()) {
            $this->steps->measure('cache_store', fn() => $this->cache->put($key, $completion->data));
        }
        return new BriefingResult(
            $verified->kept(),
            $verified->strippedCount(),
            $this->omitted($verified, $facts),
            null,                          // no error
            false,                         // not a cache hit
            $verified->isTotalFailure(),
            $completion->promptTokens,
            $completion->completionTokens,
        );
    }

    /**
     * Follow-up question in the chat box ("when was her last A1c?").
     *
     * @param list<array{role: string, text: string}> $transcript  Prior turns in this chat, oldest first.
     * @param ?PatientId $open  The chart currently open; used to refuse questions about someone else.
     */
    public function answer(AssembledFacts $assembled, string $question, array $transcript, ?PatientId $open = null, ?EvidenceSet $evidence = null): AnswerResult
    {
        $evidence ??= EvidenceSet::none();
        // Scope check happens BEFORE the model sees the question. The facts are
        // for the open patient only, so a question naming a different patient
        // would either hallucinate or leak. Refuse it up front and record why.
        if ($open !== null && QuestionScope::refersToAnotherPatient($question, $open)) {
            $this->steps->add('scope_check', (int) round(microtime(true) * 1000), 0, null, ['refused' => 'other_patient']);
            return new AnswerResult('not_in_facts', [], 0, null, 0, 0);
        }
        try {
            $completion = $this->complete(
                $this->prompt->followUpSystem(),
                $this->prompt->followUpUser($assembled, $question, $transcript, $evidence),
                'follow_up',
                $this->prompt->followUpSchema(),
            );
        } catch (LlmException $e) {
            return new AnswerResult('error', [], 0, $e->statusLabel(), 0, 0);
        }

        // The model reports whether the answer is in the facts. Coerce that
        // to a closed set: anything other than the exact string 'not_in_facts'
        // is treated as 'cited'. Never trust free-form model output as an enum.
        $type = $completion->data['answer_type'] ?? null;
        $type = $type === 'not_in_facts' ? 'not_in_facts' : 'cited';
        // Same verifier as the briefing — an answer sentence must cite a fact too.
        $verified = $this->verify($this->narrationFrom($completion->data), $assembled->facts(), $evidence);
        $citedChunks = [];
        foreach ($verified->kept() as $s) {
            foreach ($s->factIds as $id) {
                if ($evidence->has($id)) {
                    $citedChunks[$id] = $evidence->get($id);
                }
            }
        }
        return new AnswerResult($type, $verified->kept(), $verified->strippedCount(), null, $completion->promptTokens, $completion->completionTokens, array_values($citedChunks));
    }

    /** HTTP attempts the model call took this request: 0 if no call was made, 2 if the one retry was used. */
    public function llmAttempts(): int
    {
        return $this->llmAttempts;
    }

    /**
     * Single choke point for talking to the model. Times the call as
     * "llm.briefing" or "llm.follow_up", records model name + token counts +
     * attempt count on success, and captures the attempt count even on
     * failure before re-throwing so the controller can log it.
     *
     * @param array<string, mixed> $schema  JSON schema the model's reply must match.
     */
    private function complete(string $system, string $user, string $schemaName, array $schema): LlmCompletion
    {
        try {
            $completion = $this->steps->measure(
                'llm.' . $schemaName,
                fn() => $this->llm->complete($system, $user, $schemaName, $schema),
                fn(LlmCompletion $c) => ['model' => $this->llm->model(), 'prompt_tokens' => $c->promptTokens, 'completion_tokens' => $c->completionTokens, 'attempts' => $c->attempts],
            );
        } catch (LlmException $e) {
            $this->llmAttempts = $e->attempts();
            throw $e;
        }
        $this->llmAttempts = $completion->attempts;
        return $completion;
    }

    /**
     * Timed wrapper around the Verifier. The verifier walks each sentence and
     * keeps it only if every fact id it cites exists in the FactSet; the rest
     * are stripped. Records kept/stripped/total_failure for the trace.
     */
    private function verify(Narration $narration, FactSet $facts, ?EvidenceSet $evidence = null): VerificationResult
    {
        return $this->steps->measure(
            'verify',
            fn() => $this->verifier->verify($narration, $facts, $evidence),
            static fn(VerificationResult $v) => ['kept' => count($v->kept()), 'stripped' => $v->strippedCount(), 'total_failure' => $v->isTotalFailure()],
        );
    }

    /**
     * Timed wrapper around the OmissionGuard. Compares what the model cited
     * against the full FactSet and returns the important facts (allergies,
     * abnormal labs, ...) that no surviving sentence mentioned, so the panel
     * can list them explicitly instead of silently dropping them.
     *
     * @return list<Fact>
     */
    private function omitted(VerificationResult $verified, FactSet $facts): array
    {
        return $this->steps->measure(
            'omission_guard',
            fn() => $this->guard->omitted($verified, $facts),
            static fn(array $omitted) => ['appended' => count($omitted)],
        );
    }

    /**
     * Cosmetic cleanup of one sentence's text. Models sometimes leak inline
     * "[id]" citations or JSON punctuation into sentence text. Citations live
     * in fact_ids, so remove the inline echoes, then trim anything that is not
     * sentence text from both ends.
     *
     * This is NOT the safety gate — the Verifier is. Scrub only tidies what
     * the verifier will then judge.
     *
     * @param list<string> $ids  The fact ids this sentence claims to cite.
     */
    private function scrub(string $text, array $ids): string
    {
        // Remove bracketed citation lists like "[a1b2c3d4, e5f6a7b8]" when every
        // token is either one of this sentence's fact_ids or looks like an
        // 8-char hex fact id. Anything else in brackets is left alone.
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
        // Strip leaked JSON scaffolding: leading braces/quotes/`"text":`, trailing braces/quotes/commas.
        $text = preg_replace('/^[\s{}\[\]",:]*(?:text"?\s*:\s*"?)?/', '', $text) ?? $text;
        $text = preg_replace('/[\s{}\[\]",:]+$/', '', $text) ?? $text;
        // Remove space before punctuation ("mg ." -> "mg.") and trim.
        $text = trim(preg_replace('/\s+([.,;:!?])/', '$1', $text) ?? $text);
        // Make sure it ends like a sentence.
        if ($text !== '' && !preg_match('/[.!?]$/', $text)) {
            $text .= '.';
        }
        return $text;
    }

    /**
     * Convert the model's decoded JSON into a typed Narration (a list of
     * Sentence objects, each with text + fact ids). This is "parse, don't
     * validate": the JSON schema should guarantee the shape, but we still
     * narrow every value with is_array / is_string and silently drop
     * anything malformed rather than throwing. A malformed item costs one
     * sentence, not the whole briefing.
     *
     * @param array<string, mixed> $data  Decoded JSON, expected shape {"sentences": [{"text": "...", "fact_ids": ["..."]}]}.
     */
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
                // Week 2: models sometimes cite a guideline passage inline
                // ("[e0f60b55960c]") and leave fact_ids empty because the field
                // is named for facts. A bracketed id is a citation; recover it
                // so the Verifier judges the sentence against what was cited.
                if (preg_match_all('/\[([0-9a-f]{8,12}(?:\s*,\s*[0-9a-f]{8,12})*)\]/', $item['text'], $m)) {
                    foreach ($m[1] as $group) {
                        foreach (preg_split('/\s*,\s*/', $group) ?: [] as $id) {
                            if (!in_array($id, $ids, true)) {
                                $ids[] = $id;
                            }
                        }
                    }
                }
                $sentences[] = new Sentence($this->scrub($item['text'], $ids), $ids);
            }
        }
        return new Narration($sentences);
    }
}
