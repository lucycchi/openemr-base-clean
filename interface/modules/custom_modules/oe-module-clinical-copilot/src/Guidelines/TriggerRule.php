<?php

/**
 * One rule from contracts/guideline_triggers.json, parsed once. A rule fires
 * when any `any` matcher matches (or every `all` matcher does) and no
 * `exclude` matcher does. Matchers are plain arrays from the file; each is
 * evaluated against the facts, the active problem titles and the age.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Guidelines;

use OpenEMR\Modules\ClinicalCopilot\Fact;

final readonly class TriggerRule
{
    /**
     * @param list<array<string, mixed>> $any
     * @param list<array<string, mixed>> $all
     * @param list<array<string, mixed>> $exclude
     */
    public function __construct(
        public string $id,
        public string $label,
        public string $query,
        public string $source,
        public array $any,
        public array $all,
        public array $exclude,
    ) {
    }

    /**
     * @param list<Fact> $facts
     * @param list<string> $activeProblems
     */
    public function fire(array $facts, array $activeProblems, ?int $age): ?FiredTrigger
    {
        foreach ($this->exclude as $matcher) {
            if (self::evaluate($matcher, $facts, $activeProblems, $age, forExclusion: true)->matched) {
                return null;
            }
        }
        $factIds = [];
        $reasons = [];
        $fired = false;
        if ($this->all !== []) {
            $fired = true;
            foreach ($this->all as $matcher) {
                $hit = self::evaluate($matcher, $facts, $activeProblems, $age, forExclusion: false);
                if (!$hit->matched) {
                    $fired = false;
                    break;
                }
                $factIds = [...$factIds, ...$hit->factIds];
                $reasons = [...$reasons, ...$hit->reasons];
            }
        }
        foreach ($this->any as $matcher) {
            $hit = self::evaluate($matcher, $facts, $activeProblems, $age, forExclusion: false);
            if ($hit->matched) {
                $fired = true;
                $factIds = [...$factIds, ...$hit->factIds];
                $reasons = [...$reasons, ...$hit->reasons];
            }
        }
        if (!$fired) {
            return null;
        }
        return new FiredTrigger($this->id, $this->label, $this->query, $this->source, array_values(array_unique($factIds)), array_values(array_unique($reasons)));
    }

    /**
     * @param array<string, mixed> $m
     * @param list<Fact> $facts
     * @param list<string> $activeProblems
     */
    private static function evaluate(array $m, array $facts, array $activeProblems, ?int $age, bool $forExclusion): MatchResult
    {
        // Age matchers: an unknown age can neither satisfy a requirement nor prove an exclusion.
        if (isset($m['age_below']) || isset($m['age_above']) || isset($m['age_between'])) {
            if ($age === null) {
                return MatchResult::none();
            }
            if (isset($m['age_below']) && is_int($m['age_below'])) {
                return MatchResult::of($age < $m['age_below']);
            }
            if (isset($m['age_above']) && is_int($m['age_above'])) {
                return MatchResult::of($age > $m['age_above']);
            }
            $between = $m['age_between'];
            if (is_array($between) && count($between) === 2 && is_int($between[0]) && is_int($between[1])) {
                return MatchResult::of($age >= $between[0] && $age <= $between[1]);
            }
            return MatchResult::none();
        }
        if (isset($m['problem_regex']) && is_string($m['problem_regex'])) {
            $reasons = [];
            foreach ($activeProblems as $title) {
                if (preg_match('/' . $m['problem_regex'] . '/i', $title) === 1) {
                    $reasons[] = "on the problem list: $title";
                }
            }
            return new MatchResult($reasons !== [], [], $forExclusion ? [] : $reasons);
        }
        if (isset($m['category_present']) && is_array($m['category_present'])) {
            $loincs = is_array($m['loinc'] ?? null) ? $m['loinc'] : [];
            foreach ($facts as $f) {
                if (in_array($f->category->value, $m['category_present'], true) && in_array($f->attributes['loinc'] ?? '', $loincs, true)) {
                    return MatchResult::of(true);
                }
            }
            return MatchResult::none();
        }
        if (!isset($m['category']) || !is_string($m['category'])) {
            return MatchResult::none();
        }
        $ids = [];
        foreach ($facts as $f) {
            if ($f->category->value !== $m['category']) {
                continue;
            }
            if (isset($m['loinc']) && is_array($m['loinc']) && !in_array($f->attributes['loinc'] ?? '', $m['loinc'], true)) {
                continue;
            }
            if (isset($m['direction']) && ($f->attributes['direction'] ?? null) !== $m['direction']) {
                continue;
            }
            if (isset($m['drug_regex']) && is_string($m['drug_regex']) && preg_match('/' . $m['drug_regex'] . '/i', $f->attributes['drug'] ?? '') !== 1) {
                continue;
            }
            if (isset($m['title_regex']) && is_string($m['title_regex']) && preg_match('/' . $m['title_regex'] . '/i', $f->attributes['title'] ?? '') !== 1) {
                continue;
            }
            if (isset($m['vital']) && ($f->attributes['vital'] ?? null) !== $m['vital']) {
                continue;
            }
            $ids[] = $f->id;
        }
        return new MatchResult($ids !== [], $ids, []);
    }
}
