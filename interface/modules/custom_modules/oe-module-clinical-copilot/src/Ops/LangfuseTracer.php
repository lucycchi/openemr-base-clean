<?php

/**
 * Sends one trace (id = correlation id) and, when the model ran, one generation to Langfuse. Best effort: never throws.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Ops;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

final readonly class LangfuseTracer implements Tracer
{
    public function __construct(
        private ClientInterface $http,
        private string $host,
        private string $publicKey,
        private string $secretKey,
    ) {
    }

    public function record(RequestTrace $t): void
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $start = self::iso($t->startedAtMs);
        $batch = [[
            'id' => $t->correlationId . '-trace',
            'type' => 'trace-create',
            'timestamp' => $now,
            'body' => [
                'id' => $t->correlationId,
                'name' => $t->name,
                'userId' => $t->user,
                'timestamp' => $start,
                'metadata' => $t->metadata + ['duration_ms' => $t->durationMs, 'cost_usd' => $t->costUsd],
                'tags' => ['clinical-copilot'],
            ],
        ]];
        // One span per step, in order, so the trace view reads as a timeline
        // and a failed step is red with its reason.
        foreach ($t->steps as $i => $step) {
            $batch[] = [
                'id' => $t->correlationId . '-step-' . $i,
                'type' => 'span-create',
                'timestamp' => $now,
                'body' => [
                    'id' => $t->correlationId . '-step-' . $i,
                    'traceId' => $t->correlationId,
                    'name' => $step->name,
                    'startTime' => self::iso($step->startedAtMs),
                    'endTime' => self::iso($step->startedAtMs + $step->durationMs),
                    'level' => $step->error === null ? 'DEFAULT' : 'ERROR',
                    'statusMessage' => $step->error,
                    'metadata' => $step->detail + ['duration_ms' => $step->durationMs],
                ],
            ];
        }
        if ($t->model !== null) {
            $batch[] = [
                'id' => $t->correlationId . '-gen',
                'type' => 'generation-create',
                'timestamp' => $now,
                'body' => [
                    'id' => $t->correlationId . '-gen',
                    'traceId' => $t->correlationId,
                    'name' => $t->name . '.llm',
                    'model' => $t->model,
                    'startTime' => $start,
                    'endTime' => self::iso($t->startedAtMs + $t->llmDurationMs),
                    'usage' => ['input' => $t->promptTokens, 'output' => $t->completionTokens]
                        + ($t->costUsd === null ? [] : ['totalCost' => $t->costUsd]),
                    'level' => $t->status === null ? 'DEFAULT' : 'ERROR',
                    'statusMessage' => $t->status,
                    'metadata' => ['llm_duration_ms' => $t->llmDurationMs],
                ],
            ];
        }
        // Boolean scores make the rates alertable in Langfuse (alerts can
        // threshold the share of true values on a boolean score, not trace
        // metadata): request_ok → error rate, tool_ok → tool-failure rate,
        // verification_pass → verification pass rate. See ALERTS.md.
        foreach (self::scores($t) as $name => $value) {
            $batch[] = [
                'id' => $t->correlationId . '-score-' . $name,
                'type' => 'score-create',
                'timestamp' => $now,
                'body' => [
                    'id' => $t->correlationId . '-score-' . $name,
                    'traceId' => $t->correlationId,
                    'name' => $name,
                    'value' => $value ? 1 : 0,
                    'dataType' => 'BOOLEAN',
                    'source' => 'API',
                ],
            ];
        }
        try {
            $this->http->request('POST', rtrim($this->host, '/') . '/api/public/ingestion', [
                'headers' => ['Authorization' => 'Basic ' . base64_encode($this->publicKey . ':' . $this->secretKey)],
                'json' => ['batch' => $batch],
                'timeout' => 2.0,
                'connect_timeout' => 1.0,
                'http_errors' => false,
            ]);
        } catch (GuzzleException) {
            // Observability must never fail the clinical request.
        }
    }

    /** @return array<string, bool> */
    private static function scores(RequestTrace $t): array
    {
        $httpStatus = is_int($t->metadata['http_status'] ?? null) ? $t->metadata['http_status'] : 200;
        // A refusal (403) is a correct outcome, not an error; a 5xx or a
        // non-null status on a served request is.
        $scores = ['request_ok' => $httpStatus < 500 && ($httpStatus >= 400 || $t->status === null)];
        if (is_bool($t->metadata['verification_pass'] ?? null)) {
            $scores['verification_pass'] = $t->metadata['verification_pass'];
        }
        $toolOk = true;
        foreach ($t->steps as $step) {
            if ($step->error !== null) {
                $toolOk = false;
            }
        }
        $scores['tool_ok'] = $toolOk;
        // Pre-warm hit rate: present only on chart opens that were evaluated
        // against a receipt, so sites without the sweep score nothing here.
        if (is_string($t->metadata['warm_result'] ?? null)) {
            $scores['warm_hit'] = $t->metadata['warm_result'] === 'hit';
        }
        return $scores;
    }

    private static function iso(int $ms): string
    {
        return gmdate('Y-m-d\TH:i:s', intdiv($ms, 1000)) . sprintf('.%03dZ', $ms % 1000);
    }
}
