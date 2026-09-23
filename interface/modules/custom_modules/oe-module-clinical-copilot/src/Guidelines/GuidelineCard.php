<?php

/**
 * One card in the "what the guidelines say about this chart" section: the
 * trigger that fired, why (fact ids and plain reasons), the passages the
 * retriever found, and the critic's verdict. A card whose verdict is false
 * never reaches the panel; GuidelineSection drops it and counts it.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Guidelines;

use OpenEMR\Modules\ClinicalCopilot\EvidenceChunk;

final readonly class GuidelineCard
{
    /**
     * @param list<string> $becauseFactIds
     * @param list<string> $reasons
     * @param list<EvidenceChunk> $chunks
     */
    public function __construct(
        public string $triggerId,
        public string $label,
        public array $becauseFactIds,
        public array $reasons,
        public array $chunks,
        public ?bool $applicable,
        public ?string $reason,
    ) {
    }

    public function checkedLabel(): string
    {
        return match ($this->applicable) {
            true => 'Checked against age, sex and the problem list',
            false => 'Not applicable to this patient',
            null => 'Guideline text; applicability not assessed',
        };
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'trigger_id' => $this->triggerId,
            'label' => $this->label,
            'because_fact_ids' => $this->becauseFactIds,
            'reasons' => $this->reasons,
            'chunks' => array_map(static fn(EvidenceChunk $c): array => $c->toArray(), $this->chunks),
            'applicable' => $this->applicable,
            'reason' => $this->reason,
            'checked_label' => $this->checkedLabel(),
        ];
    }

    /** @param array<string, mixed> $a the array form, as cached */
    public static function fromArray(array $a): self
    {
        $strings = static function (mixed $v): array {
            if (!is_array($v)) {
                throw new \RuntimeException('guideline card: expected a list of strings');
            }
            return array_values(array_map(static fn(mixed $s): string => is_string($s) ? $s : throw new \RuntimeException('guideline card: expected a string'), $v));
        };
        if (!is_string($a['trigger_id'] ?? null) || !is_string($a['label'] ?? null) || !is_array($a['chunks'] ?? null)) {
            throw new \RuntimeException('guideline card: malformed');
        }
        $chunks = [];
        foreach ($a['chunks'] as $c) {
            if (!is_array($c) || !is_string($c['chunk_id'] ?? null) || !is_string($c['source_id'] ?? null) || !is_string($c['section'] ?? null) || !is_string($c['quote'] ?? null) || !is_numeric($c['score'] ?? null)) {
                throw new \RuntimeException('guideline card: malformed chunk');
            }
            $chunks[] = new EvidenceChunk($c['chunk_id'], $c['source_id'], $c['section'], $c['quote'], (float) $c['score'], is_string($c['title'] ?? null) ? $c['title'] : '', is_string($c['url'] ?? null) ? $c['url'] : '');
        }
        $applicable = $a['applicable'] ?? null;
        $reason = $a['reason'] ?? null;
        return new self($a['trigger_id'], $a['label'], $strings($a['because_fact_ids'] ?? []), $strings($a['reasons'] ?? []), $chunks, is_bool($applicable) ? $applicable : null, is_string($reason) ? $reason : null);
    }
}
