<?php

/**
 * Pre-warm receipts in copilot_prewarm.
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
 * PrewarmReceipts stored in the copilot_prewarm table. record() denormalises
 * everything needed later to explain a hit/miss (prompt version, model, the
 * cache key the warm produced) so the comparison at chart open needs no joins.
 */
final readonly class DbPrewarmReceipts implements PrewarmReceipts
{
    public function __construct(private string $model)
    {
    }

    public function record(string $runId, PrewarmRow $row): void
    {
        $hash = $row->factsHash;
        QueryUtils::sqlInsert(
            "INSERT INTO copilot_prewarm (run_id, target_date, pc_eid, pid, provider_username, facts_hash, cache_key,
                                          prompt_version, model, fact_lines_json, status, duration_ms, model_called, correlation_id, error)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $runId,
                $row->appointment->day->format('Y-m-d'),
                $row->appointment->eventId,
                $row->appointment->pid->value,
                $row->appointment->providerUsername,
                $hash,
                // Same formula as NarrationPipeline::cacheKey(), stored so a
                // later reader can check whether that exact key is still in the cache.
                $hash === null ? null : hash('sha256', $hash . '|' . Prompt::VERSION . '|' . $this->model),
                Prompt::VERSION,
                $this->model,
                json_encode($row->factLines ?? [], JSON_THROW_ON_ERROR),
                $row->status->value,
                $row->durationMs,
                $row->modelCalled ? 1 : 0,
                $row->correlationId,
                $row->error === null ? null : mb_substr($row->error, 0, 500),
            ]
        );
    }

    public function latestFor(string $ymd, PatientId $pid, string $openerUsername): ?PrewarmReceipt
    {
        // The opener's own receipt first (its ACL view is the one that can
        // match), then the most recent for the patient that day.
        $row = QueryUtils::querySingleRow(
            "SELECT run_id, target_date, pc_eid, pid, provider_username, facts_hash, cache_key, prompt_version, model,
                    fact_lines_json, status, duration_ms, model_called, correlation_id, created_at
               FROM copilot_prewarm
              WHERE target_date = ? AND pid = ? AND status IN ('warmed', 'already_cached')
              ORDER BY (provider_username = ?) DESC, id DESC
              LIMIT 1",
            [$ymd, $pid->value, $openerUsername]
        );
        if ($row === false) {
            return null;
        }
        // Narrow the stored JSON and status back into typed values; an
        // unrecognised status (e.g. from a newer schema) is treated as no receipt.
        $lines = json_decode(Row::str($row, 'fact_lines_json'), true);
        $status = PrewarmStatus::tryFrom(Row::str($row, 'status'));
        if ($status === null) {
            return null;
        }
        return new PrewarmReceipt(
            Row::str($row, 'run_id'),
            Row::str($row, 'target_date'),
            Row::int($row, 'pc_eid'),
            Row::int($row, 'pid'),
            Row::str($row, 'provider_username'),
            self::nullableStr($row, 'facts_hash'),
            self::nullableStr($row, 'cache_key'),
            Row::str($row, 'prompt_version'),
            Row::str($row, 'model'),
            is_array($lines) ? array_values(array_filter($lines, 'is_string')) : [],
            $status,
            Row::int($row, 'duration_ms'),
            Row::int($row, 'model_called') === 1,
            Row::str($row, 'correlation_id'),
            Row::str($row, 'created_at'),
        );
    }

    /** @param array<mixed> $row */
    private static function nullableStr(array $row, string $key): ?string
    {
        return isset($row[$key]) && is_string($row[$key]) ? $row[$key] : null;
    }
}
