<?php

/**
 * One guideline topic the chart fired: which rule, the fixed retrieval
 * query for the sidecar, the corpus document it should land in, and why it
 * fired (fact ids and plain-text reasons for the panel's "Because:" line).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Guidelines;

final readonly class FiredTrigger
{
    /**
     * @param list<string> $factIds facts that matched the rule
     * @param list<string> $reasons matches with no fact behind them ("on the problem list: ...")
     */
    public function __construct(
        public string $id,
        public string $label,
        public string $query,
        public string $expectedSource,
        public array $factIds,
        public array $reasons = [],
    ) {
    }

    /** @return array{trigger_id: string, query: string} what the sidecar's brief mode receives */
    public function toQuery(): array
    {
        return ['trigger_id' => $this->id, 'query' => $this->query];
    }
}
