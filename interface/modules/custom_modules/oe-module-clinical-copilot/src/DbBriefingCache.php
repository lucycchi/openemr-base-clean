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

/**
 * BriefingCache stored in the copilot_briefing_cache table. One instance is
 * built per request with the patient, facts hash and model already known,
 * so put() can record those alongside the narration for auditing — the
 * cache key alone is opaque.
 */
final readonly class DbBriefingCache implements BriefingCache
{
    public function __construct(
        private PatientId $pid,
        private string $factsHash,
        private string $model,
    ) {
    }

    public function get(string $key): ?CachedNarration
    {
        $row = QueryUtils::querySingleRow(
            "SELECT narration_json, created_at FROM copilot_briefing_cache WHERE cache_key = ?",
            [$key]
        );
        if ($row === false) {
            return null;
        }
        // A corrupt or non-object row is treated as a miss, never an error.
        $json = Row::str($row, 'narration_json');
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($data)) {
            return null;
        }
        // created_at is stored in the session zone (OpenEMR syncs it to the
        // site's), so it reads as local time and is stamped with that offset.
        $createdAt = new \DateTimeImmutable(Row::str($row, 'created_at'));
        /** @var array<string, mixed> $data */
        return new CachedNarration($data, $createdAt->format(\DateTimeInterface::ATOM));
    }

    public function put(string $key, array $narration): void
    {
        // Upsert: same key -> replace the JSON and refresh created_at.
        QueryUtils::sqlInsert(
            "INSERT INTO copilot_briefing_cache (cache_key, pid, facts_hash, prompt_version, model, narration_json)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE narration_json = VALUES(narration_json), created_at = CURRENT_TIMESTAMP",
            [$key, $this->pid->value, $this->factsHash, Prompt::VERSION, $this->model, json_encode($narration, JSON_THROW_ON_ERROR)]
        );
    }
}
