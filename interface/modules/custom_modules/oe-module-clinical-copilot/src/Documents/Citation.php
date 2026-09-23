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

/**
 * The "where did this come from" attached to every value the Co-Pilot
 * shows. Three kinds of source share one shape:
 *
 *   chart     -> an OpenEMR row (Week 1 facts); sourceId is "service#recordId"
 *   document  -> an uploaded PDF; sourceId is the OpenEMR documents.id
 *   guideline -> a passage of the guideline corpus; sourceId is a manifest id
 *
 * JSON field -> property: source_type -> sourceType, source_id -> sourceId,
 * page_or_section -> pageOrSection (a page number, a heading path, or a field
 * name), field_or_chunk_id -> fieldOrChunkId (a JSON pointer such as
 * /results/3/value, a chunk id, or a fact id), quote_or_value ->
 * quoteOrValue (the verbatim text), anchored -> anchored, bbox -> bbox,
 * row_bbox -> rowBbox.
 *
 * "anchored" is the safety flag. For a document it is true only when
 * deterministic code found the value on the page; false means the model
 * proposed a value nobody could place, and the panel shows it as unverified.
 */
final readonly class Citation
{
    /**
     * The constructor enforces the two rules the contract states: the source
     * type is one of the three above with a non-empty id and field, and a
     * document citation that claims to be anchored must say where (a bbox).
     * Breaking either is a schema_mismatch, the same error a bad sidecar
     * reply produces.
     */
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

    /**
     * Builds a citation from decoded JSON. The five text fields must be
     * strings; "anchored" counts as true only when it is literally true (a
     * "yes" or 1 does not count); the two boxes are optional.
     *
     * @param array<mixed> $a  decoded JSON; every value is narrowed here
     */
    public static function fromArray(array $a): self
    {
        // A small helper: read key $k as a string or refuse the whole citation.
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

    /**
     * A citation for a Week 1 chart fact. Chart rows are always anchored (the
     * database row is the proof) and have no page box.
     */
    public static function chart(string $service, int $recordId, string $field, string $factId, string $value): self
    {
        return new self('chart', sprintf('%s#%d', $service, $recordId), $field, $factId, $value, true);
    }

    /**
     * The citation in the wire shape of contracts/citation.schema.json. The
     * two boxes are added only when present, so an unanchored citation has
     * no bbox key at all.
     *
     * @return array<string, mixed>
     */
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
