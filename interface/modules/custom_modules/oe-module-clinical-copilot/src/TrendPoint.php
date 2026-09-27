<?php

/**
 * One point on a lab trend line (contracts/trends.schema.json points[]).
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

/**
 * One numeric result on a trend line: the day it was drawn, the value, and,
 * when the result was read from an uploaded PDF, the citation to that row.
 * A result entered in the chart has no citation (null); the panel draws the
 * two kinds differently and only a cited point opens the source viewer.
 */
final readonly class TrendPoint
{
    public function __construct(
        public \DateTimeImmutable $date,
        public float $value,
        public ?Citation $citation,
    ) {
    }

    /** @return array{date: string, value: float, citation: array<string, mixed>|null} */
    public function toArray(): array
    {
        return [
            'date' => $this->date->format('Y-m-d'),
            'value' => $this->value,
            'citation' => $this->citation?->toArray(),
        ];
    }
}
