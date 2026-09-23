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

use OpenEMR\Modules\ClinicalCopilot\Documents\Citation;

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
    /**
     * @param ?Citation $citation Week 2: where the value can be seen in its
     *   source document (page and bounding box) when the fact came from an
     *   uploaded PDF; null for facts that come straight from chart rows.
     */
    public function __construct(
        public string $id,
        public string $service,
        public int $recordId,
        public string $field,
        public string $value,
        public FactCategory $category,
        public ?Citation $citation = null,
        /** @var array<string, string> structured hints for the guideline trigger rules (loinc, direction, drug, title, vital); never hashed, sent or shown */
        public array $attributes = [],
    ) {
    }

    /** The citation the panel renders: the document citation when there is one, else the chart row. */
    public function citationOrChart(): Citation
    {
        return $this->citation ?? Citation::chart($this->service, $this->recordId, $this->field, $this->id, $this->value);
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
