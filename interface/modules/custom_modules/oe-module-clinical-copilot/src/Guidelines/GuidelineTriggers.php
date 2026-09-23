<?php

/**
 * Derives, from the assembled chart facts and the patient's demographics,
 * which guideline topics apply to this chart. Deterministic: no model
 * decides what applies, so the route is explainable and replayable, and no
 * chart text leaves the server for this step. The rules live in
 * contracts/guideline_triggers.json.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Guidelines;

use DateTimeImmutable;
use OpenEMR\Modules\ClinicalCopilot\AssembledFacts;
use OpenEMR\Modules\ClinicalCopilot\Demographics;

final class GuidelineTriggers
{
    /** Must equal the "version" in the JSON file; GuidelineTriggersTest pins it. Bump when a rule changes: it is part of the briefing cache key. */
    public const VERSION = '2026-09-23.1';

    private const FILE = __DIR__ . '/../../contracts/guideline_triggers.json';

    /** @var list<TriggerRule> */
    private readonly array $rules;

    public function __construct(?string $path = null)
    {
        $this->rules = self::load($path ?? self::FILE);
    }

    /** @return list<TriggerRule> in file order */
    public function rules(): array
    {
        return $this->rules;
    }

    /** @return list<FiredTrigger> in rule order */
    public function fire(AssembledFacts $assembled, Demographics $who, DateTimeImmutable $today): array
    {
        $facts = $assembled->facts()->all();
        $problems = $assembled->activeProblemTitles();
        $age = $who->ageOn($today);
        $fired = [];
        foreach ($this->rules as $rule) {
            $t = $rule->fire($facts, $problems, $age);
            if ($t !== null) {
                $fired[] = $t;
            }
        }
        return $fired;
    }

    /** @return list<TriggerRule> */
    private static function load(string $path): array
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException('guideline_triggers.json is missing');
        }
        $doc = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($doc) || ($doc['version'] ?? null) !== self::VERSION || !is_array($doc['rules'] ?? null)) {
            throw new \RuntimeException('guideline_triggers.json version does not match GuidelineTriggers::VERSION');
        }
        $rules = [];
        foreach ($doc['rules'] as $r) {
            if (!is_array($r) || !is_string($r['id'] ?? null) || !is_string($r['label'] ?? null) || !is_string($r['query'] ?? null) || !is_string($r['source'] ?? null)) {
                throw new \RuntimeException('guideline_triggers.json: a rule needs id, label, query and source');
            }
            $rules[] = new TriggerRule($r['id'], $r['label'], $r['query'], $r['source'], self::matchers($r['any'] ?? []), self::matchers($r['all'] ?? []), self::matchers($r['exclude'] ?? []));
        }
        return $rules;
    }

    /** @return list<array<string, mixed>> */
    private static function matchers(mixed $list): array
    {
        if (!is_array($list)) {
            throw new \RuntimeException('guideline_triggers.json: matcher lists must be arrays');
        }
        $out = [];
        foreach ($list as $m) {
            if (!is_array($m)) {
                throw new \RuntimeException('guideline_triggers.json: a matcher must be an object');
            }
            $typed = [];
            foreach ($m as $k => $v) {
                if (!is_string($k)) {
                    throw new \RuntimeException('guideline_triggers.json: matcher keys must be strings');
                }
                $typed[$k] = $v;
            }
            $out[] = $typed;
        }
        return $out;
    }
}
