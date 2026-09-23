<?php

/**
 * A lab order on the chart with no report yet: what was ordered, when, and
 * its order status. The briefing surfaces these so an ordered-but-missing
 * result is never silently forgotten.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot;

final readonly class PendingOrderRecord
{
    public function __construct(
        public int $id,
        public string $name,
        public \DateTimeImmutable $orderedOn,
        public string $status,
        public int $encounterId = 0,
    ) {
    }
}
