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

/**
 * One atomic, citable statement about the chart, e.g.
 *   id=3f9a1c2e service=labs recordId=812 field=value value="A1c 7.9 % (high) on 2026-08-02"
 *
 * $value is the exact text the model sees; $id is what the model must cite.
 * $service/$recordId/$field say which database row the fact came from, so a
 * citation can be traced back to the source record.
 */
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

    // Content-derived so ids stay stable across turns even when the set changes:
    // the same lab row always gets the same id, whether or not other facts were
    // added, so a follow-up question can cite a fact from the original briefing.
    // First 8 hex chars of sha256 — short enough for the model to echo reliably.
    public static function idFor(string $service, int $recordId, string $field): string
    {
        return substr(hash('sha256', "$service|$recordId|$field"), 0, 8);
    }
}
