<?php

/**
 * A field the document extractor could not anchor to its page, or a table
 * row it did not extract at all (week 2). Surfaces as an
 * extraction_unverified fact so an omission or an unverifiable value is
 * visible to the physician instead of silently dropped.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot;

use OpenEMR\Modules\ClinicalCopilot\Documents\Citation;

final readonly class UnverifiedExtraction
{
    public function __construct(
        public int $id,
        public int $documentId,
        public string $kind,
        public ?string $analyte,
        public string $value,
        public ?string $unit,
        public \DateTimeImmutable $uploadedAt,
        public Citation $citation,
    ) {
    }

    public function describe(): string
    {
        return match ($this->kind) {
            'unextracted_row' => sprintf('Row on page %s of the uploaded document was not extracted: "%s"', $this->citation->pageOrSection ?: '?', $this->value),
            'collection_date' => sprintf('Collection date %s on the uploaded document could not be verified against the page', $this->value),
            'reported_date' => sprintf('Report date %s on the uploaded document could not be verified against the page', $this->value),
            default => sprintf('%s %s%s read from the uploaded document could not be verified against the page', $this->analyte ?? 'A value', $this->value, $this->unit !== null ? ' ' . $this->unit : ''),
        };
    }
}
