<?php

/**
 * Deterministic post-LLM check: a sentence survives only if it cites real facts.
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
 * The grounding gate. A sentence survives only if all of these hold:
 *   1. it cites at least one fact id,
 *   2. every id it cites exists in the FactSet,
 *   3. it does not cite a chart fact and a guideline passage together,
 *   4. if it suggests an action, it cites a guideline passage (the critic's
 *      rule half; see suggestsActionWithoutGuideline()),
 *   5. every number or ISO date in its text appears verbatim in one of the
 *      facts it cites (so the model cannot cite a real fact and then invent
 *      a value).
 * Anything failing is stripped, never rewritten. Deterministic, no model call.
 */
final class Verifier
{
    /**
     * Week 2: $evidence lets a sentence cite guideline chunk ids as well as
     * fact ids. The rule is the same for both: every cited id must exist,
     * and every number or date must appear verbatim in something cited.
     */
    public function verify(Narration $narration, FactSet $facts, ?EvidenceSet $evidence = null): VerificationResult
    {
        $evidence ??= EvidenceSet::none();
        $kept = [];
        $stripped = [];
        foreach ($narration->sentences as $sentence) {
            // Order matters: allKnown() must pass before literalsGrounded()
            // calls $facts->get() on the cited ids.
            if (
                $sentence->factIds === []
                || !$this->allKnown($sentence, $facts, $evidence)
                || $this->mixesRecordAndGuideline($sentence, $facts, $evidence)
                || $this->suggestsActionWithoutGuideline($sentence, $facts, $evidence)
                || !$this->literalsGrounded($sentence, $facts, $evidence)
            ) {
                $stripped[] = $sentence;
                continue;
            }
            $kept[] = $sentence;
        }
        return new VerificationResult($kept, $stripped);
    }

    // Numbers and ISO dates the model types must appear verbatim in a cited fact.
    private function literalsGrounded(Sentence $sentence, FactSet $facts, EvidenceSet $evidence): bool
    {
        // Models sometimes echo "[id]" or "[id, id]" inline; a cited id is a
        // reference, not a claim.
        $text = preg_replace_callback(
            '/\[([0-9a-f]{8,12}(?:\s*,\s*[0-9a-f]{8,12})*)\]/',
            function (array $m) use ($sentence): string {
                $ids = preg_split('/\s*,\s*/', $m[1]) ?: [];
                return array_diff($ids, $sentence->factIds) === [] ? '' : $m[0];
            },
            $sentence->text
        ) ?? $sentence->text;
        // Pull out standalone numbers ("7.9", "150") and dates ("2026-08-02").
        // The lookarounds stop "A1c" or "10*3/uL" from splitting into digits.
        preg_match_all('/(?<![A-Za-z\d])(?:\d{4}-\d{2}-\d{2}|\d+(?:\.\d+)?)(?![A-Za-z\d])/', $text, $matches);
        if ($matches[0] === []) {
            return true;
        }
        // Concatenate the cited facts' text and require each literal to occur in it.
        // A cited guideline passage is its heading path (guideline title and
        // section, which carry the year) plus its text.
        $cited = implode("\n", array_map(fn(string $id) => $facts->has($id) ? $facts->get($id)->value : $evidence->get($id)->section . "\n" . $evidence->get($id)->quote, $sentence->factIds));
        foreach ($matches[0] as $literal) {
            if (!str_contains($cited, $literal)) {
                return false;
            }
        }
        return true;
    }

    /** Every cited id must be a real fact in this set (guards against invented ids). */
    /**
     * A sentence that cites a chart fact and a guideline passage together
     * attaches guideline text to the patient ("LDL 165 means a statin is
     * indicated"); it is stripped, whatever its numbers.
     */
    private function mixesRecordAndGuideline(Sentence $sentence, FactSet $facts, EvidenceSet $evidence): bool
    {
        $record = false;
        $guideline = false;
        foreach ($sentence->factIds as $id) {
            if ($facts->has($id)) {
                $record = true;
            } elseif ($evidence->has($id)) {
                $guideline = true;
            }
        }
        return $record && $guideline;
    }

    // Words that make a sentence advice rather than a report, anywhere in it.
    private const SUGGESTION = '/\b(consider|recommend(?:s|ed|ation|ations)?|suggest(?:s|ed|ion|ions)?|advis(?:e|es|ed|able)|should|ought|must|warrant(?:s|ed)?|indicated|benefit)\b/i';

    // Instructions: a verb in its base form opening the sentence or a clause ("Start aspirin", "…, increase the dose").
    // Not when a hyphen or a noun-phrase word follows ("Check-in was…", "Increase in A1c…"): there the word is a noun.
    // Words that mostly open report sentences as adjectives (lower, repeat) are left out.
    private const IMPERATIVE = '/(?:^|[,;:]\s*)(start|begin|initiate|stop|hold|discontinue|continue|resume|restart|increase|decrease|reduce|add|switch|order|refer|prescribe|titrate|recheck|schedule|obtain|check|draw|monitor|counsel|avoid|give|administer)\b(?!-|\s+(?:in|of|from|to|was|were|is|are|has|had|on|at)\b)/i';

    /**
     * The critic's rule half: a sentence that suggests an action (advice
     * words, or an instruction) must cite a guideline passage. Advice resting
     * only on chart facts is the model's own recommendation, and is stripped.
     * A suggestion word that also appears in a cited chart fact is the
     * chart's wording ("Recheck A1c in 3 months" from a plan note), so it
     * does not count.
     */
    private function suggestsActionWithoutGuideline(Sentence $sentence, FactSet $facts, EvidenceSet $evidence): bool
    {
        $record = '';
        foreach ($sentence->factIds as $id) {
            if ($evidence->has($id)) {
                return false;
            }
            $record .= "\n" . $facts->get($id)->value;
        }
        preg_match_all(self::SUGGESTION, $sentence->text, $advice);
        preg_match_all(self::IMPERATIVE, $sentence->text, $instructions);
        foreach ([...$advice[1], ...$instructions[1]] as $word) {
            if (preg_match('/\b' . preg_quote($word, '/') . '\b/i', $record) !== 1) {
                return true;
            }
        }
        return false;
    }

    private function allKnown(Sentence $sentence, FactSet $facts, EvidenceSet $evidence): bool
    {
        foreach ($sentence->factIds as $id) {
            if (!$facts->has($id) && !$evidence->has($id)) {
                return false;
            }
        }
        return true;
    }
}
