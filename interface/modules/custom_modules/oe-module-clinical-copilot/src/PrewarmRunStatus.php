<?php

/**
 * The last pre-warm run as the status endpoint reports it: counts only, no
 * patient references.
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
 * Last-run summary as read back from the run-log table, for the
 * prewarm.php status endpoint and the dashboard. toArray() gives the
 * snake_case JSON shape documented in contracts/prewarm.response.schema.json.
 */
final readonly class PrewarmRunStatus
{
    public function __construct(
        public string $runId,
        public string $targetDate,
        public string $finishedAt,
        public int $scheduled,
        public int $warmed,
        public int $alreadyCached,
        public int $skipped,
        public int $errored,
    ) {
    }

    /** @return array<string, string|int> */
    public function toArray(): array
    {
        return [
            'run_id' => $this->runId,
            'target_date' => $this->targetDate,
            'finished_at' => $this->finishedAt,
            'scheduled' => $this->scheduled,
            'warmed' => $this->warmed,
            'already_cached' => $this->alreadyCached,
            'skipped' => $this->skipped,
            'errored' => $this->errored,
        ];
    }
}
