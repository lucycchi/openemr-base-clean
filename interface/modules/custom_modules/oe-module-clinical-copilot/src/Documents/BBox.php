<?php

/**
 * A bounding box on a PDF page: points, top-left origin (contracts/citation.schema.json).
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
 * Where on a PDF page a cited value sits, so the viewer can draw a highlight
 * over the exact words the extraction came from. The sidecar measures in PDF
 * points (1/72 inch) from the page's top-left corner and sends the page size
 * too, so the box can be scaled to whatever zoom the viewer uses.
 *
 * "final readonly" means every field is set once in the constructor, never
 * changed afterwards, and the class cannot be subclassed.
 *
 * JSON field -> property: page -> page; x0, y0 -> left and top edge;
 * x1, y1 -> right and bottom edge; page_w, page_h -> pageW, pageH.
 */
final readonly class BBox
{
    public function __construct(
        public int $page,
        public float $x0,
        public float $y0,
        public float $x1,
        public float $y1,
        public float $pageW,
        public float $pageH,
    ) {
    }

    /**
     * Builds a box from decoded JSON. All seven numbers must be present and
     * numeric; a missing or non-numeric one is a contract breach, reported as
     * schema_mismatch rather than defaulted to zero, because a wrong highlight
     * would point the clinician at the wrong words.
     *
     * @param array<mixed> $a  decoded JSON; every value is narrowed here
     */
    public static function fromArray(array $a): self
    {
        foreach (['page', 'x0', 'y0', 'x1', 'y1', 'page_w', 'page_h'] as $k) {
            if (!isset($a[$k]) || !is_numeric($a[$k])) {
                throw new SidecarException('schema_mismatch');
            }
        }
        return new self((int) $a['page'], (float) $a['x0'], (float) $a['y0'], (float) $a['x1'], (float) $a['y1'], (float) $a['page_w'], (float) $a['page_h']);
    }

    /**
     * The box as the panel and the database see it. The fixed origin and
     * units keys state the coordinate convention explicitly so a reader of
     * the stored JSON does not have to know it.
     *
     * @return array{page: int, x0: float, y0: float, x1: float, y1: float, origin: string, units: string, page_w: float, page_h: float}
     */
    public function toArray(): array
    {
        return ['page' => $this->page, 'x0' => $this->x0, 'y0' => $this->y0, 'x1' => $this->x1, 'y1' => $this->y1, 'origin' => 'top-left', 'units' => 'pt', 'page_w' => $this->pageW, 'page_h' => $this->pageH];
    }
}
