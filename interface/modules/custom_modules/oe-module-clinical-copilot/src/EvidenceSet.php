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

/**
 * The guideline passages one answer may draw on, looked up by chunk id.
 * The Verifier asks "does this id exist?" and "what is its text?" exactly
 * as it does of a FactSet, so a sentence citing a guideline is checked the
 * same way as one citing the chart: any number or date in it must appear
 * in the cited quote.
 *
 * "readonly" on the class: the map is built once in the constructor and
 * cannot change, so an answer is always verified against the same evidence
 * the model was shown.
 */
final readonly class EvidenceSet
{
    /** @var array<string, EvidenceChunk> */
    private array $byId;

    /**
     * Indexes the chunks by id. A later chunk with the same id replaces an
     * earlier one.
     *
     * @param list<EvidenceChunk> $chunks
     */
    public function __construct(array $chunks = [])
    {
        $byId = [];
        foreach ($chunks as $c) {
            $byId[$c->chunkId] = $c;
        }
        $this->byId = $byId;
    }

    /** The empty set, for a briefing or a chart-only answer where no guideline was retrieved. */
    public static function none(): self
    {
        return new self([]);
    }

    /** True when a chunk with this id was retrieved, so a citation to it is legitimate. */
    public function has(string $id): bool
    {
        return isset($this->byId[$id]);
    }

    /** The chunk for an id; callers check has() first, since an unknown id is a programming error. */
    public function get(string $id): EvidenceChunk
    {
        return $this->byId[$id] ?? throw new \OutOfBoundsException('Unknown chunk');
    }

    /**
     * Every chunk, in retrieval order.
     *
     * @return list<EvidenceChunk>
     */
    public function all(): array
    {
        return array_values($this->byId);
    }

    /** True when nothing was retrieved for the question. */
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
