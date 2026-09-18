<?php

/**
 * Panel endpoint. Session pid only; CSRF required.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

// globals.php is OpenEMR's front controller: it starts the session, enforces
// login (no $ignoreAuth here, so an anonymous request is bounced), connects
// the database and autoloads modules. Every server-side entry point requires it.
require_once __DIR__ . "/../../../../globals.php";

use OpenEMR\Modules\ClinicalCopilot\Controller\ChatController;

// The whole endpoint is one line; all logic lives in the controller so it
// can be unit-tested with an injected request instead of real superglobals.
(new ChatController())->handleRequest();
