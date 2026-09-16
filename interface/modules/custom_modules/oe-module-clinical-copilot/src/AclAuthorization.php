<?php

/**
 * Authorization over OpenEMR's ACL for an explicitly named user.
 *
 * The user is passed explicitly rather than read from the session inside
 * AclMain: the CLI spike showed the implicit session lookup is not reliable
 * outside a web request, and explicit is safer anyway.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot;

use OpenEMR\Common\Acl\AclMain;

final readonly class AclAuthorization implements Authorization
{
    public function __construct(private string $username)
    {
        if ($username === '') {
            throw new \DomainException('Username must not be empty');
        }
    }

    public function canView(string $section, string $value): bool
    {
        return AclMain::aclCheckCore($section, $value, $this->username);
    }
}
