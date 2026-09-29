<?php

/**
 * Seeds `tp-frontdesk`, a synthetic Front Office user for AgentForge's
 * restricted-role tests (OWASP A01/A07). Front Office has no patients/med or
 * encounters/notes permission, so the Clinical Co-Pilot must refuse it.
 *
 * Unlike tp-physician, the password is not in the repository: it is read from
 * TP_FRONTDESK_PASSWORD and the script refuses to run without it. Safe to re-run:
 * an existing account keeps its password and group.
 *
 * Run as the apache user inside the openemr container:
 *   docker compose cp seed-frontdesk.php openemr:/tmp/seed-frontdesk.php
 *   docker compose exec -u apache -e TP_FRONTDESK_PASSWORD openemr php /tmp/seed-frontdesk.php
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

$ignoreAuth = true;
$_GET['site'] = 'default';
require_once '/var/www/localhost/htdocs/openemr/interface/globals.php';

use OpenEMR\Common\Acl\AclExtended;
use OpenEMR\Common\Database\QueryUtils;

const USERNAME = 'tp-frontdesk';

$password = getenv('TP_FRONTDESK_PASSWORD');
if (!is_string($password) || strlen($password) < 16) {
    fwrite(STDERR, "seed-frontdesk: TP_FRONTDESK_PASSWORD (16+ characters) is required\n");
    exit(1);
}

$existing = QueryUtils::fetchSingleValue('SELECT id FROM users WHERE username = ?', 'id', [USERNAME]);
if ($existing !== null) {
    echo "seed-frontdesk: " . USERNAME . " already exists (id {$existing}); unchanged\n";
    exit(0);
}

$facility = QueryUtils::fetchSingleValue('SELECT facility_id FROM users WHERE username = ?', 'facility_id', ['tp-physician']);
$id = QueryUtils::sqlInsert(
    "INSERT INTO users (username, password, authorized, active, lname, fname, facility_id, calendar, cal_ui) VALUES (?, 'NoLongerUsed', 0, 1, 'Frontdesk', 'Test', ?, 0, 3)",
    [USERNAME, is_numeric($facility) ? (int) $facility : 0],
);
QueryUtils::sqlStatementThrowException(
    'INSERT INTO users_secure (id, username, password, last_update_password) VALUES (?, ?, ?, NOW())',
    [$id, USERNAME, password_hash($password, PASSWORD_DEFAULT)],
);
QueryUtils::sqlStatementThrowException("INSERT INTO `groups` (name, user) VALUES ('Default', ?)", [USERNAME]);
AclExtended::setUserAro(['Front Office'], USERNAME, 'Test', '', 'Frontdesk');

echo "seed-frontdesk: created " . USERNAME . " (id {$id}) in Front Office\n";
