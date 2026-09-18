<?php

/**
 * Readiness endpoint: database, OpenAI, Langfuse (degraded-only).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

// No login required: readiness is for load balancers and graders. It reads
// no patient data. Probes are cached 60s so this URL cannot be used to spend
// OpenAI quota.
$ignoreAuth = true;
require_once __DIR__ . "/../../../../globals.php";

use OpenEMR\Modules\ClinicalCopilot\Ops\ReadinessProbes;

// check() returns a cached report when one is <60s old, else runs the probes.
// 200 = ready or degraded, 503 = a required dependency is down.
$report = ReadinessProbes::readiness()->check();
http_response_code($report->httpStatus());
header("Content-Type: application/json");
header("Cache-Control: no-store");
echo json_encode($report->toArray(), JSON_THROW_ON_ERROR);
