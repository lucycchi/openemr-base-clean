<?php

/**
 * Parses what the language model returned into a typed Narration.
 *
 * "Parse, don't validate": the provider's strict schema should guarantee the
 * shape, but every value is narrowed here anyway and anything malformed is
 * dropped, so one bad item costs one sentence rather than the whole answer.
 * Two repairs happen here because models do them whatever the instructions
 * say: a citation written inline in the text ("... [a1b2c3d4]") is recovered
 * into the citation list, and that echo, plus any leaked JSON scaffolding, is
 * scrubbed out of the sentence text.
 *
 * This is NOT the safety gate: the Verifier is. Parsing only tidies what the
 * Verifier then judges. It lives in its own class so the eval harness runs
 * exactly the parser production runs (eval case 45).
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
 * Static helpers only ("static" means called on the class, no instance):
 *
 *   model JSON -> narration(): keep well-formed sentences, recover inline
 *                 citations -> scrub(): tidy each sentence -> Narration
 */
final class ModelOutput
{
    /**
     * Turns the model's {sentences: [{text, fact_ids}]} into a Narration.
     * A sentence without text is skipped; an id that is not a string is
     * skipped; the sentence itself is kept. Nothing here decides whether a
     * citation is valid; that is the Verifier's job, one step later.
     *
     * @param array<string, mixed> $data decoded model output (llm.briefing.output / llm.followup.output)
     */
    public static function narration(array $data): Narration
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
                // The pattern: square brackets holding one or more hex ids of 8
                // (fact) or 12 (chunk) characters, comma-separated.
                if (preg_match_all('/\[([0-9a-f]{8,12}(?:\s*,\s*[0-9a-f]{8,12})*)\]/', $item['text'], $m)) {
                    foreach ($m[1] as $group) {
                        foreach (preg_split('/\s*,\s*/', $group) ?: [] as $id) {
                            if (!in_array($id, $ids, true)) {
                                $ids[] = $id;
                            }
                        }
                    }
                }
                $sentences[] = new Sentence(self::scrub($item['text'], $ids), $ids);
            }
        }
        return new Narration($sentences);
    }

    /**
     * Tidies one sentence: drop the inline citation echo and any leaked JSON
     * scaffolding, fix spacing before punctuation, end with a full stop.
     *
     * @param list<string> $ids  The ids this sentence cites.
     */
    private static function scrub(string $text, array $ids): string
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
}
