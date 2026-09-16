<?php

/**
 * BriefingCache over the copilot_briefing_cache table.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot;

use OpenEMR\Common\Database\QueryUtils;

final readonly class DbBriefingCache implements BriefingCache
{
    public function __construct(
        private PatientId $pid,
        private string $factsHash,
        private string $model,
    ) {
    }

    public function get(string $key): ?array
    {
        $row = QueryUtils::querySingleRow(
            "SELECT narration_json FROM copilot_briefing_cache WHERE cache_key = ?",
            [$key]
        );
        if ($row === false) {
            return null;
        }
        $json = Row::str($row, 'narration_json');
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        /** @var array<string, mixed>|null */
        return is_array($data) ? $data : null;
    }

    public function put(string $key, array $narration): void
    {
        QueryUtils::sqlInsert(
            "INSERT INTO copilot_briefing_cache (cache_key, pid, facts_hash, prompt_version, model, narration_json)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE narration_json = VALUES(narration_json), created_at = CURRENT_TIMESTAMP",
            [$key, $this->pid->value, $this->factsHash, Prompt::VERSION, $this->model, json_encode($narration, JSON_THROW_ON_ERROR)]
        );
    }
}
