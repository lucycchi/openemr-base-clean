<?php

/**
 * The guideline chunks available to one answer, keyed by chunk id, with the same has/get shape as FactSet so the Verifier can treat a chunk id as a citation target whose quote is the haystack for numbers and dates.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot;

final readonly class EvidenceSet
{
    /** @var array<string, EvidenceChunk> */
    private array $byId;

    /** @param list<EvidenceChunk> $chunks */
    public function __construct(array $chunks = [])
    {
        $byId = [];
        foreach ($chunks as $c) {
            $byId[$c->chunkId] = $c;
        }
        $this->byId = $byId;
    }

    public static function none(): self
    {
        return new self([]);
    }

    public function has(string $id): bool
    {
        return isset($this->byId[$id]);
    }

    public function get(string $id): EvidenceChunk
    {
        return $this->byId[$id] ?? throw new \OutOfBoundsException('Unknown chunk');
    }

    /** @return list<EvidenceChunk> */
    public function all(): array
    {
        return array_values($this->byId);
    }

    public function isEmpty(): bool
    {
        return $this->byId === [];
    }

    /**
     * Chunks from the sidecar's run.response, decorated with title and URL
     * from the corpus manifest (the sidecar sends ids only).
     *
     * @param list<array{chunk_id: string, source_id: string, section: string, quote: string, score: float}> $chunks
     */
    public static function fromRun(array $chunks, GuidelineManifest $manifest): self
    {
        $out = [];
        foreach ($chunks as $c) {
            $doc = $manifest->document($c['source_id']);
            $out[] = new EvidenceChunk($c['chunk_id'], $c['source_id'], $c['section'], $c['quote'], $c['score'], $doc['title'] ?? $c['source_id'], $doc['url'] ?? '');
        }
        return new self($out);
    }
}
