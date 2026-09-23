<?php

/**
 * Document upload, extraction and listing for the Clinical Co-Pilot (week 2).
 * See Controller/DocumentController.php; the request/response contracts are
 * contracts/documents.request.schema.json and documents.response.schema.json.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

// globals.php starts the session, enforces login, connects the database and
// autoloads modules; every server-side entry point requires it.
require_once __DIR__ . "/../../../../globals.php";

use OpenEMR\Modules\ClinicalCopilot\Controller\DocumentController;

// This file is deliberately two lines of logic: the web server maps the URL
// here, and everything (parsing, CSRF, ACL, the work, the reply) lives in the
// controller, which the eval harness can also drive in-process without HTTP.
(new DocumentController())->handleRequest();
