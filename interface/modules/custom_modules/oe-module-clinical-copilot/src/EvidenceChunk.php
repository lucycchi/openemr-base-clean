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

    /** @return array{chunk_id: string, source_id: string, title: string, section: string, quote: string, url: string, score: float} */
    public function toArray(): array
    {
        return ['chunk_id' => $this->chunkId, 'source_id' => $this->sourceId, 'title' => $this->title, 'section' => $this->section, 'quote' => $this->quote, 'url' => $this->url, 'score' => $this->score];
    }
}
