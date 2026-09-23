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

// OpenEMR's Module Manager reads $v_major.$v_minor.$v_patch and compares it
// with the sql_version it recorded at install; when a sql/<old>-to-<new>_upgrade.sql
// file names a version at or above the recorded one, "Upgrade" applies it.
// Bump the patch version whenever sql/ gains a new upgrade file.
//   0.1.0  install.sql (copilot_briefing_cache, copilot_prewarm on fresh installs)
//   0.1.1  0_1_0-to-0_1_1_upgrade.sql (copilot_prewarm for sites installed at 0.1.0)
//   0.1.2  0_1_1-to-0_1_2_upgrade.sql (copilot_document, copilot_document_fact, copilot_intake: week 2 ingestion)
//   0.1.3  0_1_2-to-0_1_3_upgrade.sql (mismatch kinds on copilot_intake and copilot_document_fact)
$v_major = '0';
$v_minor = '1';
$v_patch = '3';
$v_tag   = '';
$v_database = 0;
