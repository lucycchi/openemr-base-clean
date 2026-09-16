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

final class Verifier
{
    public function verify(Narration $narration, FactSet $facts): VerificationResult
    {
        $kept = [];
        $stripped = [];
        foreach ($narration->sentences as $sentence) {
            if (
                $sentence->factIds === []
                || !$this->allKnown($sentence, $facts)
                || !$this->literalsGrounded($sentence, $facts)
            ) {
                $stripped[] = $sentence;
                continue;
            }
            $kept[] = $sentence;
        }
        return new VerificationResult($kept, $stripped);
    }

    // Numbers and ISO dates the model types must appear verbatim in a cited fact.
    private function literalsGrounded(Sentence $sentence, FactSet $facts): bool
    {
        preg_match_all('/(?<![A-Za-z\d])(?:\d{4}-\d{2}-\d{2}|\d+(?:\.\d+)?)(?![A-Za-z\d])/', $sentence->text, $matches);
        if ($matches[0] === []) {
            return true;
        }
        $cited = implode("\n", array_map(fn(string $id) => $facts->get($id)->value, $sentence->factIds));
        foreach ($matches[0] as $literal) {
            if (!str_contains($cited, $literal)) {
                return false;
            }
        }
        return true;
    }

    private function allKnown(Sentence $sentence, FactSet $facts): bool
    {
        foreach ($sentence->factIds as $id) {
            if (!$facts->has($id)) {
                return false;
            }
        }
        return true;
    }
}
