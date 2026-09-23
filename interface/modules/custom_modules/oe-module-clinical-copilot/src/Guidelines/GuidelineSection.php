<?php

/**
 * The "what the guidelines say about this chart" section of a briefing:
 * a status and the cards that survived the critic. Built from the fired
 * triggers and the sidecar's brief run, cached in its array form, and
 * rendered by the panel. Never a source of patient sentences: the cards
 * quote guideline text and say why the topic came up.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Guidelines;

use OpenEMR\Modules\ClinicalCopilot\Documents\RunResult;
use OpenEMR\Modules\ClinicalCopilot\EvidenceChunk;
use OpenEMR\Modules\ClinicalCopilot\GuidelineManifest;

final readonly class GuidelineSection
{
    public const STATUSES = ['ok', 'unavailable', 'no_triggers', 'not_configured'];

    /**
     * @param list<GuidelineCard> $cards
     * @param bool $cacheable false when the run did not finish (a worker failed, or the critic ran but left a verdict unknown); such a section is shown but never cached
     */
    public function __construct(
        public string $status,
        public array $cards,
        public int $dropped = 0,
        public string $triggersVersion = GuidelineTriggers::VERSION,
        public bool $cacheable = true,
    ) {
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException("unknown guideline section status: $status");
        }
    }

    /** A section with no cards: the sidecar was unreachable, nothing fired, or the feature is not configured. */
    public static function none(string $status): self
    {
        return new self($status, []);
    }

    /** @param list<FiredTrigger> $triggers the triggers the request was built from, in order */
    public static function fromRun(array $triggers, RunResult $run, GuidelineManifest $manifest): self
    {
        $byTrigger = [];
        foreach ($run->evidence as $e) {
            $byTrigger[$e['trigger_id']] = $e;
        }
        $cards = [];
        $dropped = 0;
        foreach ($triggers as $t) {
            $e = $byTrigger[$t->id] ?? null;
            if ($e === null || $e['chunks'] === []) {
                continue;
            }
            if ($e['applicable'] === false) {
                $dropped++;
                continue;
            }
            $chunks = [];
            foreach ($e['chunks'] as $c) {
                $doc = $manifest->document($c['source_id']);
                $chunks[] = new EvidenceChunk($c['chunk_id'], $c['source_id'], $c['section'], $c['quote'], $c['score'], $doc['title'] ?? $c['source_id'], $doc['url'] ?? '');
            }
            $cards[] = new GuidelineCard($t->id, $t->label, $t->factIds, $t->reasons, $chunks, $e['applicable'], $e['reason']);
        }
        $failed = false;
        $criticRan = false;
        foreach ($run->handoffs as $h) {
            $failed = $failed || $h->reason === 'worker_failed';
            $criticRan = $criticRan || $h->to === 'critic';
        }
        $unknown = $criticRan && array_filter($cards, static fn(GuidelineCard $c): bool => $c->applicable === null) !== [];
        return new self('ok', $cards, $dropped, GuidelineTriggers::VERSION, !$failed && !$unknown);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'triggers_version' => $this->triggersVersion,
            'dropped' => $this->dropped,
            'cards' => array_map(static fn(GuidelineCard $c): array => $c->toArray(), $this->cards),
        ];
    }

    /** @param array<string, mixed> $a the array form, as cached */
    public static function fromArray(array $a): self
    {
        if (!is_string($a['status'] ?? null) || !is_array($a['cards'] ?? null)) {
            throw new \RuntimeException('guideline section: malformed');
        }
        $cards = [];
        foreach ($a['cards'] as $c) {
            if (!is_array($c)) {
                throw new \RuntimeException('guideline section: malformed card');
            }
            $typed = [];
            foreach ($c as $k => $v) {
                if (!is_string($k)) {
                    throw new \RuntimeException('guideline section: malformed card');
                }
                $typed[$k] = $v;
            }
            $cards[] = GuidelineCard::fromArray($typed);
        }
        $dropped = $a['dropped'] ?? 0;
        $version = $a['triggers_version'] ?? GuidelineTriggers::VERSION;
        return new self($a['status'], $cards, is_int($dropped) ? $dropped : 0, is_string($version) ? $version : GuidelineTriggers::VERSION);
    }
}
