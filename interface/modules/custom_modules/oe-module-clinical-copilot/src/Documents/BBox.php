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

    /** @param array<string, mixed> $a */
    public static function fromArray(array $a): self
    {
        foreach (['page', 'x0', 'y0', 'x1', 'y1', 'page_w', 'page_h'] as $k) {
            if (!isset($a[$k]) || !is_numeric($a[$k])) {
                throw new SidecarException('schema_mismatch');
            }
        }
        return new self((int) $a['page'], (float) $a['x0'], (float) $a['y0'], (float) $a['x1'], (float) $a['y1'], (float) $a['page_w'], (float) $a['page_h']);
    }

    /** @return array{page: int, x0: float, y0: float, x1: float, y1: float, origin: string, units: string, page_w: float, page_h: float} */
    public function toArray(): array
    {
        return ['page' => $this->page, 'x0' => $this->x0, 'y0' => $this->y0, 'x1' => $this->x1, 'y1' => $this->y1, 'origin' => 'top-left', 'units' => 'pt', 'page_w' => $this->pageW, 'page_h' => $this->pageH];
    }
}
