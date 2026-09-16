<?php

/**
 * One verifiable chart fact: a rendered value with its exact source field.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot;

final readonly class Fact
{
    public function __construct(
        public string $id,
        public string $service,
        public int $recordId,
        public string $field,
        public string $value,
        public FactCategory $category,
    ) {
    }

    // Content-derived so ids stay stable across turns even when the set changes.
    public static function idFor(string $service, int $recordId, string $field): string
    {
        return substr(hash('sha256', "$service|$recordId|$field"), 0, 8);
    }
}
