<?php

/**
 * One problem-list row as the assembler needs it.
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
 * One entry from the patient's problem list, as read from the chart.
 * Plain immutable data; FactAssembler turns it into a Fact.
 */
final readonly class ProblemRecord
{
    public function __construct(
        public int $id,
        public string $title,
        public \DateTimeImmutable $beginDate,
        public ?\DateTimeImmutable $endDate = null,
        public bool $active = true,
        public ?\DateTimeImmutable $modifiedDate = null,
    ) {
    }

    /** When the problem was resolved: the end date, else (for an inactive row) when it was last changed; null when still active or unknown. */
    public function resolvedOn(): ?\DateTimeImmutable
    {
        if ($this->endDate !== null) {
            return $this->endDate;
        }
        return $this->active ? null : $this->modifiedDate;
    }

    public function isActive(): bool
    {
        return $this->active && $this->endDate === null;
    }
}
