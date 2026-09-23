<?php

/**
 * Outcome of the readiness check.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Ops;

/**
 * Result of a readiness check, ready to serialise for ready.php. Three
 * states: 'ready' (all deps ok), 'degraded' (only optional deps like
 * Langfuse are down — still HTTP 200), 'not_ready' (a required dep such as
 * the database or OpenAI is down — HTTP 503, which a load balancer treats
 * as "take me out of rotation").
 */
final readonly class ReadinessReport
{
    /**
     * @param array<string, string> $dependencies name => "ok" or the failure reason
     * @param list<string> $degraded optional dependencies that are down
     */
    public function __construct(
        public bool $ready,
        public array $dependencies,
        public array $degraded,
        public bool $fromCache,
        public int $ageSeconds,
        public string $checkedAt,
    ) {
    }

    public function httpStatus(): int
    {
        return $this->ready ? 200 : 503;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->ready ? ($this->degraded === [] ? 'ready' : 'degraded') : 'not_ready',
            'dependencies' => $this->dependencies,
            'degraded' => $this->degraded,
            'checked_at' => $this->checkedAt,
            'age_seconds' => $this->ageSeconds,
            'from_cache' => $this->fromCache,
        ];
    }
}
