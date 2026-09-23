<?php

/**
 * In-memory authorization for tests.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Support;

use OpenEMR\Modules\ClinicalCopilot\Authorization;

/**
 * Test Authorization: allows everything unless a test explicitly deny()s a
 * section/value pair. Lets FactAssemblerTest check that a denied
 * 'sensitivities/high' hides that encounter (and its labs) without touching
 * OpenEMR's real ACL tables.
 */
final class FakeAuthorization implements Authorization
{
    /** @var array<string, true> */
    private array $denied = [];

    public function deny(string $section, string $value): void
    {
        $this->denied["$section/$value"] = true;
    }

    public function canView(string $section, string $value): bool
    {
        return !isset($this->denied["$section/$value"]);
    }
}
