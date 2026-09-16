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

// Liveness only: proves PHP is serving this module. Dependencies are ready.php.
header("Content-Type: application/json");
header("Cache-Control: no-store");
echo json_encode(["status" => "ok", "service" => "clinical-copilot", "time" => gmdate("c")], JSON_THROW_ON_ERROR);
