<?php

/**
 * One encounter row as the assembler needs it.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot;

final readonly class EncounterRecord
{
    public function __construct(
        public int $id,
        public \DateTimeImmutable $date,
        public string $sensitivity,
        public string $reason,
    ) {
    }
}
