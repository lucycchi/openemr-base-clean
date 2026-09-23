<?php

/**
 * The two demographic facts the briefing reads: sex at birth, to choose a
 * sex-specific reference interval, and date of birth, to compute age for
 * adult-only thresholds and age-gated guideline rules. Neither is ever a
 * fact the model sees; both stay on the server.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot;

use DateTimeImmutable;

final readonly class Demographics
{
    /**
     * @param 'M'|'F'|null $sex
     */
    public function __construct(
        public ?string $sex,
        public ?DateTimeImmutable $dob,
    ) {
    }

    public static function unknown(): self
    {
        return new self(null, null);
    }

    /** Completed years on the given day, or null when the date of birth is unknown. */
    public function ageOn(DateTimeImmutable $day): ?int
    {
        if ($this->dob === null || $this->dob > $day) {
            return null;
        }
        return $this->dob->diff($day)->y;
    }

    /** Maps OpenEMR's free-text sex field ("Male", "F", "female") to 'M', 'F' or null. */
    public static function normaliseSex(string $raw): ?string
    {
        return match (strtolower(trim($raw))) {
            'm', 'male' => 'M',
            'f', 'female' => 'F',
            default => null,
        };
    }
}
