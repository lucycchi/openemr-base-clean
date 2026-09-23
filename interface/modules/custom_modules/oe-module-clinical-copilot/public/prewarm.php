<?php

/**
 * Pre-warm status: enabled on this site, and the last run's counts.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

// No login required, like ready.php: it reads run counts, never patient data,
// so an alerting rule can tell "the 06:00 sweep did not run" from "it ran and failed".
$ignoreAuth = true;
require_once __DIR__ . "/../../../../globals.php";

use OpenEMR\Modules\ClinicalCopilot\Config;
use OpenEMR\Modules\ClinicalCopilot\DbPrewarmRunLog;
use OpenEMR\Modules\ClinicalCopilot\PrewarmStatusPayload;

header("Content-Type: application/json");
header("Cache-Control: no-store");
echo json_encode(
    PrewarmStatusPayload::build(Config::fromEnvironment()->prewarmEnabled, (new DbPrewarmRunLog())->lastRun(), gmdate("c")),
    JSON_THROW_ON_ERROR
);
