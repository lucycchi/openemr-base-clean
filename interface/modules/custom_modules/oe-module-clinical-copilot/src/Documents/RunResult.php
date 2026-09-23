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

/**
 * Everything the sidecar returns for one request, typed. A run in extract
 * mode fills extractions; a run in answer mode fills chunks (guideline
 * passages for the question); every run reports its handoffs (the routing
 * trail) and usage (the paid calls).
 *
 * JSON field -> property: correlation_id -> correlationId (the id PHP sent,
 * echoed back), extractions -> extractions, chunks -> chunks (kept as plain
 * arrays; EvidenceSet::fromRun types them once the manifest is at hand),
 * handoffs -> handoffs, usage -> usage.
 */
final readonly class RunResult
{
    /**
     * @param list<ExtractionResult> $extractions
     * @param list<array{chunk_id: string, source_id: string, section: string, quote: string, score: float}> $chunks
     * @param list<Handoff> $handoffs
     * @param list<UsageEntry> $usage
     * @param list<array{trigger_id: string, chunks: list<array{chunk_id: string, source_id: string, section: string, quote: string, score: float}>, applicable: bool|null, reason: string|null}> $evidence brief mode: per-trigger passages and the critic's verdict
     */
    public function __construct(public string $correlationId, public array $extractions, public array $chunks, public array $handoffs, public array $usage, public array $evidence = [])
    {
    }

    /**
     * Builds the run from decoded JSON. All four lists must be present (an
     * empty list is fine) and the correlation id must be a string. Each
     * list element goes through its own typed parser.
     *
     * @param array<mixed> $a  decoded JSON; every value is narrowed here
     */
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
        // Applies parser $f to every element, refusing any element that is not itself an object.
        $list = static fn(array $items, callable $f): array => array_values(array_map(static fn(mixed $i): mixed => is_array($i) ? $f($i) : throw new SidecarException('schema_mismatch'), $items));
        // Chunks stay as arrays with their five fields checked; the score is forced to a float.
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
        // Brief mode only; absent from extract and answer runs, so it is optional.
        $evidence = [];
        $rawEvidence = $a['evidence'] ?? [];
        if (!is_array($rawEvidence)) {
            throw new SidecarException('schema_mismatch');
        }
        foreach ($rawEvidence as $e) {
            if (!is_array($e) || !is_string($e['trigger_id'] ?? null) || !is_array($e['chunks'] ?? null)) {
                throw new SidecarException('schema_mismatch');
            }
            $applicable = $e['applicable'] ?? null;
            $reason = $e['reason'] ?? null;
            if (($applicable !== null && !is_bool($applicable)) || ($reason !== null && !is_string($reason))) {
                throw new SidecarException('schema_mismatch');
            }
            $ec = [];
            foreach ($e['chunks'] as $c) {
                if (!is_array($c) || !is_string($c['chunk_id'] ?? null) || !is_string($c['source_id'] ?? null) || !is_string($c['section'] ?? null) || !is_string($c['quote'] ?? null) || !is_numeric($c['score'] ?? null)) {
                    throw new SidecarException('schema_mismatch');
                }
                $ec[] = ['chunk_id' => $c['chunk_id'], 'source_id' => $c['source_id'], 'section' => $c['section'], 'quote' => $c['quote'], 'score' => (float) $c['score']];
            }
            $evidence[] = ['trigger_id' => $e['trigger_id'], 'chunks' => $ec, 'applicable' => $applicable, 'reason' => $reason];
        }
        return new self($a['correlation_id'], $extractions, $chunks, $handoffs, $usage, $evidence);
    }

    /**
     * Sums the language-model calls only: how many there were and how many
     * tokens went in and out. The logs and the trace report these three
     * numbers; embedding and rerank calls are priced but not counted here.
     *
     * @return array{prompt: int, completion: int, calls: int} chat tokens only; embeddings and reranks are costed separately
     */
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
