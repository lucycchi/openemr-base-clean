<?php

/**
 * JSON contract between chat.php and the panel. Facts carry their source
 * coordinates so every chip can show exactly where a value came from.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot;

use OpenEMR\Modules\ClinicalCopilot\Guidelines\GuidelineSection;

/**
 * Builds the JSON bodies chat.php returns to panel.js. Three shapes share a
 * common base (correlation id, prior visit date, facts hash, the full fact
 * list) and add a 'narration', an 'answer', or a 'chart_changed' flag. The
 * exact shapes are pinned by the schemas in ../contracts/ and by ContractsTest.
 */
final class PanelPayload
{
    /**
     * Response to action=brief.
     *
     * @return array<string, mixed>
     */
    public static function briefing(AssembledFacts $assembled, BriefingResult $briefing, string $correlationId, ?GuidelineSection $guidelines = null): array
    {
        $guidelines ??= GuidelineSection::none('not_configured');
        [$sentences, $uncited] = self::sentences($briefing->sentences, $assembled->facts(), $guidelines->chunks());
        return self::base($assembled, $correlationId) + [
            'guidelines' => $guidelines->toArray(),
            'narration' => [
                'sentences' => $sentences,
                'stripped' => $briefing->strippedCount + $uncited,
                'omitted_fact_ids' => array_map(fn(Fact $f) => $f->id, $briefing->omitted),
                'status' => $briefing->status,
                'from_cache' => $briefing->fromCache,
                'total_failure' => $briefing->totalFailure,
                'tokens' => ['prompt' => $briefing->promptTokens, 'completion' => $briefing->completionTokens],
                'generated_at' => $briefing->generatedAt,
                // Sent back with action=rate; null when the summary cannot be rated.
                'cache_key' => $briefing->cacheKey,
            ],
        ];
    }

    /**
     * Response to action=ask when the chart is unchanged.
     *
     * @return array<string, mixed>
     */
    public static function answer(AssembledFacts $assembled, AnswerResult $answer, string $correlationId): array
    {
        [$sentences, $uncited] = self::sentences($answer->sentences, $assembled->facts(), $answer->evidence);
        return self::base($assembled, $correlationId) + [
            'chart_changed' => false,
            'answer' => [
                'type' => $answer->answerType,
                'sentences' => $sentences,
                'stripped' => $answer->strippedCount + $uncited,
                'status' => $answer->status,
                'tokens' => ['prompt' => $answer->promptTokens, 'completion' => $answer->completionTokens],
                // Week 2: guideline passages the kept sentences cite, rendered apart from the patient's facts.
                'guidelines' => array_map(static fn(EvidenceChunk $c): array => $c->toArray(), $answer->evidence),
            ],
        ];
    }

    /**
     * What an answer payload said, as plain text: its kept sentences joined by
     * spaces. This is the text sealed into the answer's turn_token, so a later
     * question carries the server's own words back, not the panel's rendering.
     *
     * @param array<string, mixed> $payload a response built by answer()
     */
    public static function answerText(array $payload): string
    {
        $answer = $payload['answer'] ?? null;
        $sentences = is_array($answer) && is_array($answer['sentences'] ?? null) ? $answer['sentences'] : [];
        $texts = [];
        foreach ($sentences as $sentence) {
            if (is_array($sentence) && is_string($sentence['text'] ?? null) && $sentence['text'] !== '') {
                $texts[] = $sentence['text'];
            }
        }
        return implode(' ', $texts);
    }

    /**
     * Response to action=rate once the rating is stored
     * (contracts/chat.rate.response.schema.json). Echoes the rating so the
     * panel can show what was recorded; never the comment.
     *
     * @return array{correlation_id: string, rating: string, comment_saved: bool}
     */
    public static function rated(RatingSubmission $rating, string $correlationId): array
    {
        return ['correlation_id' => $correlationId, 'rating' => $rating->rating->value, 'comment_saved' => $rating->comment !== null];
    }

    /**
     * Response to action=ask when the facts_hash the panel sent no longer
     * matches the chart: no answer is given, the panel must re-brief first.
     * @return array<string, mixed>
     */
    public static function chartChanged(AssembledFacts $assembled, string $correlationId): array
    {
        return self::base($assembled, $correlationId) + ['chart_changed' => true];
    }

    /**
     * Fields common to every response. The full fact list is always sent so
     * the panel can render citations by id and show the "facts only" view
     * when the narration is empty.
     *
     * @return array<string, mixed>
     */
    private static function base(AssembledFacts $assembled, string $correlationId): array
    {
        return [
            'correlation_id' => $correlationId,
            'prior_visit' => $assembled->priorEncounter()?->date->format('Y-m-d'),
            'facts_hash' => $assembled->facts()->hash(),
            'facts' => array_map(
                fn(Fact $f) => [
                    'id' => $f->id,
                    'category' => $f->category->value,
                    'value' => $f->value,
                    'source' => sprintf('%s#%d.%s', $f->service, $f->recordId, $f->field),
                    'must_surface' => $f->category->mustSurface(),
                    'citation' => $f->citationOrChart()->toArray(),
                ],
                $assembled->facts()->all()
            ),
            // Lab trend lines for the panel's chart (contracts/trends.schema.json).
            'trends' => $assembled->labTrends()->toArray(),
        ];
    }

    /**
     * Each sentence also carries, for every id it cites, the full citation in
     * the five-field shape (contracts/citation.schema.json), so a claim holds
     * its own provenance and a client need not join ids against facts[].
     * Ids resolve against the chart and document facts first, then the
     * guideline passages; the Verifier has already stripped any sentence
     * citing an id that is in neither.
     *
     * No citation, no claim: a sentence goes out only when it has text and
     * every id it cites resolves here to a citation (contracts/sentence.schema.json).
     * The Verifier guarantees that when it ran against these same facts and
     * chunks; this holds it even when a caller passes a different evidence
     * set. A sentence that fails is dropped, never shown half-cited, and
     * counted with the stripped ones.
     *
     * @param list<Sentence> $sentences
     * @param list<EvidenceChunk> $chunks
     * @return array{list<array{text: string, fact_ids: non-empty-list<string>, citations: non-empty-list<array<string, mixed>>}>, int}
     *         the sentences to show, and how many were dropped for a missing citation
     */
    private static function sentences(array $sentences, FactSet $facts, array $chunks): array
    {
        $byId = [];
        foreach ($chunks as $c) {
            $byId[$c->chunkId] = $c;
        }
        $kept = [];
        $uncited = 0;
        foreach ($sentences as $s) {
            $citations = [];
            foreach ($s->factIds as $id) {
                if ($facts->has($id)) {
                    $citations[] = $facts->get($id)->citationOrChart()->toArray();
                } elseif (isset($byId[$id])) {
                    $citations[] = $byId[$id]->citation()->toArray();
                }
            }
            if ($s->text === '' || $s->factIds === [] || $citations === [] || count($citations) !== count($s->factIds)) {
                $uncited++;
                continue;
            }
            $kept[] = ['text' => $s->text, 'fact_ids' => $s->factIds, 'citations' => $citations];
        }
        return [$kept, $uncited];
    }
}
