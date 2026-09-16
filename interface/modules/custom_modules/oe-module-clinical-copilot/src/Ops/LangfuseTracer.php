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
                'metadata' => $t->metadata + ['duration_ms' => $t->durationMs],
                'tags' => ['clinical-copilot'],
            ],
        ]];
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
                    'usage' => ['input' => $t->promptTokens, 'output' => $t->completionTokens],
                    'level' => $t->status === null ? 'DEFAULT' : 'ERROR',
                    'statusMessage' => $t->status,
                    'metadata' => ['llm_duration_ms' => $t->llmDurationMs],
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

    private static function iso(int $ms): string
    {
        return gmdate('Y-m-d\TH:i:s', intdiv($ms, 1000)) . sprintf('.%03dZ', $ms % 1000);
    }
}
