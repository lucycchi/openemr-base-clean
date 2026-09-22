<?php

/**
 * A parsed run.response: what the sidecar's graph produced for one request.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Documents;

final readonly class RunResult
{
    /**
     * @param list<ExtractionResult> $extractions
     * @param list<array{chunk_id: string, source_id: string, section: string, quote: string, score: float}> $chunks
     * @param list<Handoff> $handoffs
     * @param list<UsageEntry> $usage
     */
    public function __construct(public string $correlationId, public array $extractions, public array $chunks, public array $handoffs, public array $usage)
    {
    }

    /** @param array<string, mixed> $a */
    public static function fromArray(array $a): self
    {
        foreach (['extractions', 'chunks', 'handoffs', 'usage'] as $k) {
            if (!is_array($a[$k] ?? null)) {
                throw new SidecarException('schema_mismatch');
            }
        }
        if (!is_string($a['correlation_id'] ?? null)) {
            throw new SidecarException('schema_mismatch');
        }
        $list = static fn(array $items, callable $f): array => array_values(array_map(static fn(mixed $i): mixed => is_array($i) ? $f($i) : throw new SidecarException('schema_mismatch'), $items));
        $chunks = [];
        foreach ($a['chunks'] as $c) {
            if (!is_array($c) || !is_string($c['chunk_id'] ?? null) || !is_string($c['source_id'] ?? null) || !is_string($c['section'] ?? null) || !is_string($c['quote'] ?? null) || !is_numeric($c['score'] ?? null)) {
                throw new SidecarException('schema_mismatch');
            }
            $chunks[] = ['chunk_id' => $c['chunk_id'], 'source_id' => $c['source_id'], 'section' => $c['section'], 'quote' => $c['quote'], 'score' => (float) $c['score']];
        }
        /** @var list<ExtractionResult> $extractions */
        $extractions = $list($a['extractions'], ExtractionResult::fromArray(...));
        /** @var list<Handoff> $handoffs */
        $handoffs = $list($a['handoffs'], Handoff::fromArray(...));
        /** @var list<UsageEntry> $usage */
        $usage = $list($a['usage'], UsageEntry::fromArray(...));
        return new self($a['correlation_id'], $extractions, $chunks, $handoffs, $usage);
    }

    /** @return array{prompt: int, completion: int, calls: int} chat tokens only; embeddings and reranks are costed separately */
    public function chatTokens(): array
    {
        $p = $c = $n = 0;
        foreach ($this->usage as $u) {
            if ($u->kind === 'chat') {
                $p += $u->input;
                $c += $u->output;
                $n++;
            }
        }
        return ['prompt' => $p, 'completion' => $c, 'calls' => $n];
    }
}
