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

/**
 * Builds the JSON bodies chat.php returns to panel.js. Three shapes share a
 * common base (correlation id, prior visit date, facts hash, the full fact
 * list) and add a 'narration', an 'answer', or a 'chart_changed' flag. The
 * exact shapes are pinned by the schemas in ../contracts/ and by ContractsTest.
 */
final class PanelPayload
{
    /** Response to action=brief. @return array<string, mixed> */
    public static function briefing(AssembledFacts $assembled, BriefingResult $briefing, string $correlationId): array
    {
        return self::base($assembled, $correlationId) + [
            'narration' => [
                'sentences' => self::sentences($briefing->sentences),
                'stripped' => $briefing->strippedCount,
                'omitted_fact_ids' => array_map(fn(Fact $f) => $f->id, $briefing->omitted),
                'status' => $briefing->status,
                'from_cache' => $briefing->fromCache,
                'total_failure' => $briefing->totalFailure,
                'tokens' => ['prompt' => $briefing->promptTokens, 'completion' => $briefing->completionTokens],
                'generated_at' => $briefing->generatedAt,
            ],
        ];
    }

    /** Response to action=ask when the chart is unchanged. @return array<string, mixed> */
    public static function answer(AssembledFacts $assembled, AnswerResult $answer, string $correlationId): array
    {
        return self::base($assembled, $correlationId) + [
            'chart_changed' => false,
            'answer' => [
                'type' => $answer->answerType,
                'sentences' => self::sentences($answer->sentences),
                'stripped' => $answer->strippedCount,
                'status' => $answer->status,
                'tokens' => ['prompt' => $answer->promptTokens, 'completion' => $answer->completionTokens],
                // Week 2: guideline passages the kept sentences cite, rendered apart from the patient's facts.
                'guidelines' => array_map(static fn(EvidenceChunk $c): array => $c->toArray(), $answer->evidence),
            ],
        ];
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
     * when the narration is empty. @return array<string, mixed>
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
        ];
    }

    /**
     * @param list<Sentence> $sentences
     * @return list<array{text: string, fact_ids: list<string>}>
     */
    private static function sentences(array $sentences): array
    {
        return array_map(fn(Sentence $s) => ['text' => $s->text, 'fact_ids' => $s->factIds], $sentences);
    }
}
