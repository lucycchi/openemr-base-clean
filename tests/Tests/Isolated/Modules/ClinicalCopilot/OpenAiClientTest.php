<?php

/**
 * OpenAiClient: structured-output calls with typed failures and one bounded retry.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Modules\ClinicalCopilot;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use OpenEMR\Modules\ClinicalCopilot\Llm\LlmRateLimited;
use OpenEMR\Modules\ClinicalCopilot\Llm\LlmRefusal;
use OpenEMR\Modules\ClinicalCopilot\Llm\LlmSchemaMismatch;
use OpenEMR\Modules\ClinicalCopilot\Llm\LlmTimeout;
use OpenEMR\Modules\ClinicalCopilot\Llm\LlmUpstreamError;
use OpenEMR\Modules\ClinicalCopilot\Llm\OpenAiClient;
use OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Support\ModuleAutoload;
use PHPUnit\Framework\TestCase;

final class OpenAiClientTest extends TestCase
{
    /**
     * @codeCoverageIgnore Fixture wiring; runs before coverage attribution.
     */
    public static function setUpBeforeClass(): void
    {
        ModuleAutoload::register();
    }

    private MockHandler $mock;
    /** @var \ArrayObject<int, array<string, mixed>> */
    private \ArrayObject $history;

    private function requestAt(int $i): Request
    {
        $request = $this->history[$i]['request'] ?? null;
        self::assertInstanceOf(Request::class, $request);
        return $request;
    }

    /** @return array<string, mixed> */
    private function bodyOf(Request $request): array
    {
        $body = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        /** @var array<string, mixed> $body */
        return $body;
    }

    private function client(): OpenAiClient
    {
        $this->history = new \ArrayObject();
        // Guzzle takes the container by reference; hand it the object via a local
        // so the property keeps its narrow type.
        $container = $this->history;
        $stack = HandlerStack::create($this->mock);
        $stack->push(Middleware::history($container));
        return new OpenAiClient(new Client(['handler' => $stack]), 'sk-test', 'gpt-4o-mini', retrySleepMs: 0, correlationId: self::CORRELATION_ID);
    }

    private const CORRELATION_ID = '763e45ddfc57b76bccc793509358ad89';

    /** @return array<string, mixed> */
    private function schema(): array
    {
        return ['type' => 'object', 'properties' => ['sentences' => ['type' => 'array', 'items' => ['type' => 'string']]], 'required' => ['sentences'], 'additionalProperties' => false];
    }

    private function completion(string $content): Response
    {
        return new Response(200, [], json_encode([
            'choices' => [['message' => ['content' => $content]]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
        ], JSON_THROW_ON_ERROR));
    }

    public function testReturnsDecodedStructuredOutputAndUsage(): void
    {
        $this->mock = new MockHandler([$this->completion('{"sentences":["one"]}')]);

        $result = $this->client()->complete('sys', 'user', 'narration', $this->schema());

        self::assertSame(['sentences' => ['one']], $result->data);
        self::assertSame(10, $result->promptTokens);
        self::assertSame(5, $result->completionTokens);
    }

    public function testSendsStrictJsonSchemaAndBearerAuth(): void
    {
        $this->mock = new MockHandler([$this->completion('{"sentences":[]}')]);

        $this->client()->complete('sys', 'user', 'narration', $this->schema());

        $request = $this->requestAt(0);
        $body = $this->bodyOf($request);
        self::assertSame('Bearer sk-test', $request->getHeaderLine('Authorization'));
        self::assertSame('gpt-4o-mini', $body['model']);
        self::assertIsArray($body['response_format']);
        self::assertIsArray($body['response_format']['json_schema']);
        self::assertTrue($body['response_format']['json_schema']['strict']);
        self::assertSame('narration', $body['response_format']['json_schema']['name']);
        self::assertIsArray($body['messages']);
        self::assertIsArray($body['messages'][0]);
        self::assertIsArray($body['messages'][1]);
        self::assertSame('sys', $body['messages'][0]['content']);
        self::assertSame('user', $body['messages'][1]['content']);
    }

    public function testCorrelationIdRidesOnTheRequestAsUserFieldAndHeader(): void
    {
        $this->mock = new MockHandler([$this->completion('{"sentences":[]}')]);

        $this->client()->complete('sys', 'user', 'narration', $this->schema());

        $request = $this->requestAt(0);
        self::assertSame(self::CORRELATION_ID, $this->bodyOf($request)['user']);
        self::assertSame(self::CORRELATION_ID, $request->getHeaderLine('X-Correlation-Id'));
    }

    public function testRateLimitIsRetriedOnceThenThrownWithTheAttemptCount(): void
    {
        $this->mock = new MockHandler([new Response(429, [], '{}'), new Response(429, [], '{}')]);

        try {
            $this->client()->complete('sys', 'user', 'narration', $this->schema());
            self::fail('expected LlmRateLimited');
        } catch (LlmRateLimited $e) {
            self::assertCount(2, $this->history);
            self::assertSame(2, $e->attempts());
        }
    }

    public function testRateLimitThenSuccessSucceedsAndReportsTwoAttempts(): void
    {
        $this->mock = new MockHandler([new Response(429, [], '{}'), $this->completion('{"sentences":["ok"]}')]);

        $result = $this->client()->complete('sys', 'user', 'narration', $this->schema());

        self::assertSame(['sentences' => ['ok']], $result->data);
        self::assertSame(2, $result->attempts);
    }

    public function testAFirstTrySuccessIsOneAttempt(): void
    {
        $this->mock = new MockHandler([$this->completion('{"sentences":["ok"]}')]);

        self::assertSame(1, $this->client()->complete('sys', 'user', 'narration', $this->schema())->attempts);
    }

    public function testServerErrorIsRetriedOnceThenThrown(): void
    {
        $this->mock = new MockHandler([new Response(503, [], ''), new Response(500, [], '')]);

        $this->expectException(LlmUpstreamError::class);
        $this->client()->complete('sys', 'user', 'narration', $this->schema());
    }

    public function testClientErrorIsNotRetried(): void
    {
        $this->mock = new MockHandler([new Response(400, [], '{"error":{"message":"bad"}}')]);

        try {
            $this->client()->complete('sys', 'user', 'narration', $this->schema());
            self::fail('expected LlmUpstreamError');
        } catch (LlmUpstreamError) {
            self::assertCount(1, $this->history);
        }
    }

    public function testTimeoutTwiceIsTyped(): void
    {
        $this->mock = new MockHandler([
            new ConnectException('timed out', new Request('POST', '/')),
            new ConnectException('timed out', new Request('POST', '/')),
        ]);

        $this->expectException(LlmTimeout::class);
        $this->client()->complete('sys', 'user', 'narration', $this->schema());
    }

    public function testTimeoutThenSuccessSucceeds(): void
    {
        $this->mock = new MockHandler([
            new ConnectException('timed out', new Request('POST', '/')),
            $this->completion('{"sentences":["ok"]}'),
        ]);

        $result = $this->client()->complete('sys', 'user', 'narration', $this->schema());

        self::assertSame(['sentences' => ['ok']], $result->data);
        self::assertCount(2, $this->history);
    }

    public function testRefusalIsTyped(): void
    {
        $this->mock = new MockHandler([new Response(200, [], json_encode([
            'choices' => [['message' => ['content' => null, 'refusal' => 'I cannot help with that.']]],
            'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1],
        ], JSON_THROW_ON_ERROR))]);

        $this->expectException(LlmRefusal::class);
        $this->client()->complete('sys', 'user', 'narration', $this->schema());
    }

    public function testUnparseableContentIsSchemaMismatch(): void
    {
        $this->mock = new MockHandler([$this->completion('not json')]);

        $this->expectException(LlmSchemaMismatch::class);
        $this->client()->complete('sys', 'user', 'narration', $this->schema());
    }
}
