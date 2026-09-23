<?php

/**
 * One prescription row as the assembler needs it.
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
 * One medication from the chart. $active mirrors OpenEMR's active flag;
 * inactive meds are still listed but narrated as such.
 */
final readonly class MedicationRecord
{
    public function __construct(
        public int $id,
        public string $drug,
        public \DateTimeImmutable $startDate,
        public bool $active,
        public DateProvenance $startDateProvenance = DateProvenance::Recorded,
        public ?\DateTimeImmutable $endDate = null,
        public string $dosage = '',
        public string $interval = '',
        public ?\DateTimeImmutable $modifiedDate = null,
    ) {
    }

    /** When the medication was stopped: the recorded end date, else (for an inactive row) when it was last changed; null when unknown or still active. */
    public function stoppedOn(): ?\DateTimeImmutable
    {
        if ($this->endDate !== null) {
            return $this->endDate;
        }
        return $this->active ? null : $this->modifiedDate;
    }

    /** The drug's first word, lowercased: the coarse key that pairs a stopped row with its replacement. */
    public function nameKey(): string
    {
        return strtolower(strtok(trim($this->drug), ' ') ?: $this->drug);
    }

    /** "10 MG Oral Tablet daily": the drug string after its name, plus dose and interval when recorded. */
    public function describeDose(): string
    {
        $rest = trim((string) preg_replace('/^\S+\s*/', '', trim($this->drug)));
        $parts = array_filter([$rest, $this->dosage, $this->interval], static fn(string $p): bool => $p !== '');
        return $parts === [] ? $this->drug : implode(' ', $parts);
    }
}
