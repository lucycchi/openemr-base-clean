<?php

/**
 * A clock pinned to one instant, so a pre-warm assembles history as of the
 * scheduled day rather than the moment the job happens to run.
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
use DateTimeZone;
use Psr\Clock\ClockInterface;

final readonly class FixedClock implements ClockInterface
{
    public function __construct(private DateTimeImmutable $now)
    {
    }

    /** @param string $ymd calendar date, e.g. 2026-09-18 */
    public static function startOfDay(string $ymd, DateTimeZone $tz): self
    {
        return new self(new DateTimeImmutable($ymd . ' 00:00:00', $tz));
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}
