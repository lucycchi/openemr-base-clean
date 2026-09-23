<?php

/**
 * Who may see what; implemented over AclMain at runtime.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot;

/**
 * "May the current user see this?" — the one question the copilot asks the
 * permission system. An interface so tests can plug in a fake that says
 * yes/no without OpenEMR's real ACL tables.
 */
interface Authorization
{
    /**
     * @param string $section  ACL section, e.g. 'patients' or 'sensitivities'
     * @param string $value    ACL object within it, e.g. 'med' or 'high'
     */
    public function canView(string $section, string $value): bool;
}
