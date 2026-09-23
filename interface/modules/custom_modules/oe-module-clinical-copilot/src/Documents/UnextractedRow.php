<?php

/**
 * A table row that looked like a result but no extracted result anchored to (contracts/lab-report.schema.json unextracted[]).
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
 * A row of the lab table that looks like a result (a number and a unit)
 * but that the model never proposed, even after the sidecar's one retry.
 * It is kept so an omission is visible to the clinician as an unverified
 * row instead of silently disappearing.
 *
 * JSON field -> property: page -> page, text -> text (the row as printed),
 * row_bbox -> rowBbox (where the row is, for the viewer to outline).
 */
final readonly class UnextractedRow
{
    public function __construct(public int $page, public string $text, public BBox $rowBbox)
    {
    }

    /**
     * Builds a row from decoded JSON; all three fields are required.
     *
     * @param array<mixed> $a  decoded JSON; every value is narrowed here
     */
    public static function fromArray(array $a): self
    {
        if (!is_int($a['page'] ?? null) || !is_string($a['text'] ?? null) || !is_array($a['row_bbox'] ?? null)) {
            throw new SidecarException('schema_mismatch');
        }
        return new self($a['page'], $a['text'], BBox::fromArray($a['row_bbox']));
    }
}
