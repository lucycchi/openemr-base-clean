<?php

/**
 * Minimal OpenAI chat-completions client with strict JSON-schema output.
 *
 * One retry with jitter on 429/5xx inside a fixed total budget; every other
 * failure is thrown as a typed LlmException so the panel can show a precise
 * status line above the intact fact table. Uses Guzzle (already a project
 * dependency); no SDK.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Llm;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;

final class OpenAiClient implements LanguageModel
{
    private const ENDPOINT = 'https://api.openai.com/v1/chat/completions';
    // Narration loads asynchronously beside an already-rendered fact table, so
    // this bounds a background call, not the physician's wait for the chart.
    private const TOTAL_BUDGET_SECONDS = 25.0;
    private const PER_ATTEMPT_SECONDS = 12.0;

    public function __construct(
        private readonly ClientInterface $http,
        private readonly string $apiKey,
        private readonly string $model,
        private readonly int $retrySleepMs = 300,
        // Sent as OpenAI's per-request `user` field and as X-Correlation-Id so
        // the provider's own logs can be joined to ours.
        private readonly ?string $correlationId = null,
    ) {
    }

    public function model(): string
    {
        return $this->model;
    }

    /** @param array<string, mixed> $schema JSON schema for the strict response */
    public function complete(string $system, string $user, string $schemaName, array $schema): LlmCompletion
    {
        $body = [
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => ['name' => $schemaName, 'strict' => true, 'schema' => $schema],
            ],
            'temperature' => 0,
        ];
        $headers = ['Authorization' => 'Bearer ' . $this->apiKey, 'Content-Type' => 'application/json'];
        if ($this->correlationId !== null) {
            $body['user'] = $this->correlationId;
            $headers['X-Correlation-Id'] = $this->correlationId;
        }

        $started = microtime(true);
        $attempt = 0;
        while (true) {
            $attempt++;
            $remaining = self::TOTAL_BUDGET_SECONDS - (microtime(true) - $started);
            if ($remaining <= 0.5) {
                throw (new LlmTimeout('Time budget exhausted before request'))->withAttempts($attempt - 1);
            }
            try {
                $response = $this->http->request('POST', self::ENDPOINT, [
                    'headers' => $headers,
                    'json' => $body,
                    'timeout' => min(self::PER_ATTEMPT_SECONDS, $remaining),
                    'connect_timeout' => min(3.0, $remaining),
                    'http_errors' => true,
                ]);
                try {
                    return $this->parse($response, $attempt);
                } catch (LlmException $e) {
                    throw $e->withAttempts($attempt);
                }
            } catch (ConnectException $e) {
                // Guzzle reports read timeouts here too; one retry if budget remains.
                if ($attempt === 1 && self::TOTAL_BUDGET_SECONDS - (microtime(true) - $started) > 2.0) {
                    continue;
                }
                throw (new LlmTimeout('Connection failed or timed out', 0, $e))->withAttempts($attempt);
            } catch (BadResponseException $e) {
                $status = $e->getResponse()->getStatusCode();
                $retryable = $status === 429 || $status >= 500;
                if ($retryable && $attempt === 1) {
                    usleep(($this->retrySleepMs + random_int(0, $this->retrySleepMs)) * 1000);
                    continue;
                }
                if ($status === 429) {
                    throw (new LlmRateLimited('Rate limited', $status, $e))->withAttempts($attempt);
                }
                throw (new LlmUpstreamError("Upstream HTTP $status", $status, $e))->withAttempts($attempt);
            } catch (GuzzleException $e) {
                throw (new LlmUpstreamError('Transport failure', 0, $e))->withAttempts($attempt);
            }
        }
    }

    private function parse(ResponseInterface $response, int $attempts): LlmCompletion
    {
        try {
            $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new LlmSchemaMismatch('Response body is not JSON', 0, $e);
        }
        if (!is_array($payload)) {
            throw new LlmSchemaMismatch('Response body is not an object');
        }
        $choices = $payload['choices'] ?? null;
        $first = is_array($choices) ? ($choices[0] ?? null) : null;
        $message = is_array($first) ? ($first['message'] ?? null) : null;
        if (!is_array($message)) {
            throw new LlmSchemaMismatch('Response has no message');
        }
        if (isset($message['refusal']) && is_string($message['refusal']) && $message['refusal'] !== '') {
            throw new LlmRefusal('Model refused');
        }
        $content = $message['content'] ?? null;
        if (!is_string($content)) {
            throw new LlmSchemaMismatch('Message content missing');
        }
        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new LlmSchemaMismatch('Structured output is not JSON', 0, $e);
        }
        if (!is_array($data)) {
            throw new LlmSchemaMismatch('Structured output is not an object');
        }
        $usage = is_array($payload['usage'] ?? null) ? $payload['usage'] : [];
        /** @var array<string, mixed> $data */
        return new LlmCompletion(
            $data,
            is_int($usage['prompt_tokens'] ?? null) ? $usage['prompt_tokens'] : 0,
            is_int($usage['completion_tokens'] ?? null) ? $usage['completion_tokens'] : 0,
            $attempts,
        );
    }
}
