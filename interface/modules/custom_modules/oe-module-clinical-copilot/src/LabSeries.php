<?php

/**
 * One lab test's trend line (contracts/trends.schema.json series[]).
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
 * Every numeric result for one test (one LOINC code in one unit), oldest
 * first. The name and unit shown are the latest point's, so a chart row
 * named "LDL Cholesterol Calc" and a PDF row printed "LDL-C" share one line
 * labelled with whichever was drawn most recently. LabTrends builds these.
 */
final readonly class LabSeries
{
    /** @param non-empty-list<TrendPoint> $points oldest first, at least two */
    public function __construct(
        public string $loinc,
        public string $name,
        public string $unit,
        public array $points,
    ) {
    }

    public function latest(): TrendPoint
    {
        return $this->points[array_key_last($this->points)];
    }

    /** @return array{loinc: string, name: string, unit: string, points: list<array{date: string, value: float, citation: array<string, mixed>|null}>} */
    public function toArray(): array
    {
        return [
            'loinc' => $this->loinc,
            'name' => $this->name,
            'unit' => $this->unit,
            'points' => array_map(static fn(TrendPoint $p): array => $p->toArray(), $this->points),
        ];
    }
}
