<?php

/**
 * Module version.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

// OpenEMR's module manager reads these globals to display and compare versions.
// $v_database is the module's own schema version (0 = sql/install.sql only, no upgrades yet).
$v_major = '0';
$v_minor = '1';
$v_patch = '0';
$v_tag   = '';
$v_database = 0;
