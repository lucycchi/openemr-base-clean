<?php

/**
 * Typed patient identifier.
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
 * A patient id wrapped in its own type. A bare int could be confused with an
 * encounter id or user id in a call like brief(5, 7); this class makes such a
 * mix-up a type error. Also guarantees the value is positive, so nothing
 * downstream has to re-check.
 */
final readonly class PatientId
{
    public function __construct(public int $value)
    {
        if ($value <= 0) {
            throw new \DomainException('Patient id must be positive');
        }
    }
}
