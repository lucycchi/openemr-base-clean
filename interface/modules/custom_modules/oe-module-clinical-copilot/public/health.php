<?php

/**
 * Liveness endpoint.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

use OpenEMR\Modules\ClinicalCopilot\Ops\DeployedCommit;

// Liveness only: proves PHP is serving this module. Dependencies are ready.php.
// No OpenEMR bootstrap here, so the module class is loaded directly.
require_once __DIR__ . '/../src/Ops/DeployedCommit.php';

header("Content-Type: application/json");
header("Cache-Control: no-store");
echo json_encode(["status" => "ok", "service" => "clinical-copilot", "time" => gmdate("c"), "commit" => DeployedCommit::fromEnvironment()], JSON_THROW_ON_ERROR);
