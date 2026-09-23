<?php

/**
 * One guideline chunk retrieved for a question (week 2): what the model may cite by chunk id for a statement about what guidelines recommend. Never a fact about the patient.
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
 * A passage from a clinical guideline, found by the sidecar's retriever for
 * the question being asked. It plays the role a Fact plays for chart data:
 * the model may cite it (by chunkId) and the Verifier checks the sentence
 * against its quote. It says what a guideline recommends; it never says
 * anything about this patient.
 *
 * chunkId is the sidecar's stable id for the passage; sourceId names the
 * guideline; section is the heading path; quote is the verbatim text;
 * score is the retriever's relevance; title and url come from the corpus
 * manifest (the sidecar sends ids only) and default to empty.
 */
final readonly class EvidenceChunk
{
    public function __construct(
        public string $chunkId,
        public string $sourceId,
        public string $section,
        public string $quote,
        public float $score,
        public string $title = '',
        public string $url = '',
    ) {
    }

    /**
     * The chunk as the panel receives it, so it can show the title and link
     * beside a cited sentence.
     *
     * @return array{chunk_id: string, source_id: string, title: string, section: string, quote: string, url: string, score: float}
     */
    public function toArray(): array
    {
        return ['chunk_id' => $this->chunkId, 'source_id' => $this->sourceId, 'title' => $this->title, 'section' => $this->section, 'quote' => $this->quote, 'url' => $this->url, 'score' => $this->score];
    }
}
