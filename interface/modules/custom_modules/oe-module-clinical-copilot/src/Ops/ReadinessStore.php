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

/**
 * Where the last readiness check result is remembered between requests, so
 * ready.php does not hit the database and two external APIs on every poll.
 * FileReadinessStore is used in production; ReadinessCache (in-memory) in tests.
 */
interface ReadinessStore
{
    /** The last stored result: unix time it was taken plus name => "ok"/reason. Null if none. @return array{at: int, dependencies: array<string, string>}|null */
    public function get(): ?array;

    /** @param array<string, string> $dependencies */
    public function put(int $at, array $dependencies): void;
}
