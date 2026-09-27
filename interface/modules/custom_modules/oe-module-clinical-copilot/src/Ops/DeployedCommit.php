<?php

/**
 * The commit a deployment runs, as /health reports it.
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
 * docker/vps/deploy.sh writes the commit it deploys to the droplet's .env as
 * COPILOT_DEPLOYED_COMMIT, and compose passes it to the openemr container
 * (the container clones the branch at start, so the checkout itself carries
 * no reliable record). Locally the variable is unset and the commit is
 * unknown. /health is public, so only a commit hash is ever reported.
 */
final class DeployedCommit
{
    public const ENV = 'COPILOT_DEPLOYED_COMMIT';

    public static function fromEnvironment(): ?string
    {
        $value = $_ENV[self::ENV] ?? getenv(self::ENV);
        return self::parse(is_string($value) ? $value : null);
    }

    /** A 7- to 40-character hex commit hash, lower-cased; null for anything else. */
    public static function parse(?string $value): ?string
    {
        $value = strtolower(trim($value ?? ''));
        return preg_match('/^[0-9a-f]{7,40}$/', $value) === 1 ? $value : null;
    }
}
