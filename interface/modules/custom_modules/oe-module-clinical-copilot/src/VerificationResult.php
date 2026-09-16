<?php

/**
 * Outcome of verifying a narration: sentences kept, sentences stripped, and why.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot;

final readonly class VerificationResult
{
    /**
     * @param list<Sentence> $kept
     * @param list<Sentence> $stripped
     */
    public function __construct(
        private array $kept,
        private array $stripped,
    ) {
    }

    /** @return list<Sentence> */
    public function kept(): array
    {
        return $this->kept;
    }

    /** @return list<Sentence> */
    public function stripped(): array
    {
        return $this->stripped;
    }

    public function strippedCount(): int
    {
        return count($this->stripped);
    }

    public function isTotalFailure(): bool
    {
        return $this->kept === [] && $this->stripped !== [];
    }
}
