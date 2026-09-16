<?php

/**
 * Runs bounded dependency probes; optional dependencies degrade rather than fail.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Ops;

use Psr\Clock\ClockInterface;

final class Readiness
{
    public const TTL_SECONDS = 60;
    private const OPTIONAL = ['langfuse'];

    /** @param array<string, callable(): ?string> $probes name => returns null when ok, else a reason */
    public function __construct(
        private readonly array $probes,
        private readonly ReadinessStore $cache,
        private readonly ClockInterface $clock,
    ) {
    }

    public function check(): ReadinessReport
    {
        $now = $this->clock->now()->getTimestamp();
        $cached = $this->cache->get();
        if ($cached !== null && $now - $cached['at'] < self::TTL_SECONDS) {
            return $this->report($cached['dependencies'], true, $now - $cached['at'], $cached['at']);
        }
        $dependencies = [];
        foreach ($this->probes as $name => $probe) {
            try {
                $dependencies[$name] = $probe() ?? 'ok';
            } catch (\RuntimeException) {
                $dependencies[$name] = "$name probe failed";
            }
        }
        $this->cache->put($now, $dependencies);
        return $this->report($dependencies, false, 0, $now);
    }

    /** @param array<string, string> $dependencies */
    private function report(array $dependencies, bool $fromCache, int $age, int $at): ReadinessReport
    {
        $degraded = [];
        $ready = true;
        foreach ($dependencies as $name => $state) {
            if ($state === 'ok') {
                continue;
            }
            if (in_array($name, self::OPTIONAL, true)) {
                $degraded[] = $name;
            } else {
                $ready = false;
            }
        }
        return new ReadinessReport($ready, $dependencies, $degraded, $fromCache, $age, gmdate('c', $at));
    }
}
