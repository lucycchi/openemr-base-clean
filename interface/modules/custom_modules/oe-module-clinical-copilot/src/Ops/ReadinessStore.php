<?php

/**
 * Where the last readiness result lives.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Ops;

interface ReadinessStore
{
    /** @return array{at: int, dependencies: array<string, string>}|null */
    public function get(): ?array;

    /** @param array<string, string> $dependencies */
    public function put(int $at, array $dependencies): void;
}
