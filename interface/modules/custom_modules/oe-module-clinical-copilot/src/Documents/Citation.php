<?php

/**
 * Machine-readable provenance for one claim or extracted field (contracts/citation.schema.json).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Documents;

final readonly class Citation
{
    public function __construct(
        public string $sourceType,
        public string $sourceId,
        public string $pageOrSection,
        public string $fieldOrChunkId,
        public string $quoteOrValue,
        public bool $anchored,
        public ?BBox $bbox = null,
        public ?BBox $rowBbox = null,
    ) {
        if (!in_array($sourceType, ['chart', 'document', 'guideline'], true) || $sourceId === '' || $fieldOrChunkId === '') {
            throw new SidecarException('schema_mismatch');
        }
        if ($sourceType === 'document' && $anchored && $bbox === null) {
            throw new SidecarException('schema_mismatch');
        }
    }

    /** @param array<string, mixed> $a */
    public static function fromArray(array $a): self
    {
        $str = static fn(string $k): string => is_string($a[$k] ?? null) ? $a[$k] : throw new SidecarException('schema_mismatch');
        return new self(
            $str('source_type'),
            $str('source_id'),
            $str('page_or_section'),
            $str('field_or_chunk_id'),
            $str('quote_or_value'),
            ($a['anchored'] ?? null) === true,
            is_array($a['bbox'] ?? null) ? BBox::fromArray($a['bbox']) : null,
            is_array($a['row_bbox'] ?? null) ? BBox::fromArray($a['row_bbox']) : null,
        );
    }

    public static function chart(string $service, int $recordId, string $field, string $factId, string $value): self
    {
        return new self('chart', sprintf('%s#%d', $service, $recordId), $field, $factId, $value, true);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $out = [
            'source_type' => $this->sourceType,
            'source_id' => $this->sourceId,
            'page_or_section' => $this->pageOrSection,
            'field_or_chunk_id' => $this->fieldOrChunkId,
            'quote_or_value' => $this->quoteOrValue,
            'anchored' => $this->anchored,
        ];
        if ($this->bbox !== null) {
            $out['bbox'] = $this->bbox->toArray();
        }
        if ($this->rowBbox !== null) {
            $out['row_bbox'] = $this->rowBbox->toArray();
        }
        return $out;
    }
}
