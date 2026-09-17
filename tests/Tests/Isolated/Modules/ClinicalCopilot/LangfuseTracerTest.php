<?php

/**
 * LangfuseTracer: one trace + one generation per request, keyed by the correlation id.
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
use OpenEMR\Modules\ClinicalCopilot\Ops\LangfuseTracer;
use OpenEMR\Modules\ClinicalCopilot\Ops\RequestTrace;
use OpenEMR\Modules\ClinicalCopilot\Ops\Step;
use OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Support\ModuleAutoload;
use PHPUnit\Framework\TestCase;

final class LangfuseTracerTest extends TestCase
{
    /**
     * @codeCoverageIgnore Fixture wiring; runs before coverage attribution.
     */
    public static function setUpBeforeClass(): void
    {
        ModuleAutoload::register();
    }

    /** @var \ArrayObject<int, array<string, mixed>> */
    private \ArrayObject $history;

    private function tracer(MockHandler $mock): LangfuseTracer
    {
        $this->history = new \ArrayObject();
        $container = $this->history;
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($container));
        return new LangfuseTracer(new Client(['handler' => $stack]), 'https://cloud.langfuse.com', 'pk-test', 'sk-test');
    }

    private function trace(): RequestTrace
    {
        return new RequestTrace(
            correlationId: 'corr-abc',
            name: 'copilot.brief',
            user: 'physician',
            startedAtMs: 1_700_000_000_000,
            durationMs: 3200,
            metadata: ['facts' => 14, 'stripped' => 0, 'omitted' => 0, 'from_cache' => false, 'http_status' => 200],
            model: 'gpt-4o-mini',
            promptTokens: 606,
            completionTokens: 295,
            llmDurationMs: 3100,
            status: null,
        );
    }

    /** @return array<string, mixed> */
    private function sentBody(): array
    {
        $entry = $this->history[0] ?? null;
        $request = is_array($entry) ? ($entry['request'] ?? null) : null;
        self::assertInstanceOf(Request::class, $request);
        $body = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        /** @var array<string, mixed> $body */
        return $body;
    }

    public function testPostsTraceAndGenerationToIngestionWithBasicAuth(): void
    {
        $this->tracer(new MockHandler([new Response(207, [], '{}')]))->record($this->trace());

        $entry = $this->history[0] ?? null;
        $request = is_array($entry) ? ($entry['request'] ?? null) : null;
        self::assertInstanceOf(Request::class, $request);
        self::assertSame('https://cloud.langfuse.com/api/public/ingestion', (string) $request->getUri());
        self::assertSame('Basic ' . base64_encode('pk-test:sk-test'), $request->getHeaderLine('Authorization'));

        $batch = $this->sentBody()['batch'];
        self::assertIsArray($batch);
        self::assertCount(2, $batch);
        self::assertIsArray($batch[0]);
        self::assertIsArray($batch[1]);
        self::assertSame('trace-create', $batch[0]['type']);
        self::assertIsArray($batch[0]['body']);
        self::assertSame('corr-abc', $batch[0]['body']['id']);
        self::assertSame('copilot.brief', $batch[0]['body']['name']);
        self::assertSame('physician', $batch[0]['body']['userId']);
        self::assertSame('generation-create', $batch[1]['type']);
        self::assertIsArray($batch[1]['body']);
        self::assertSame('corr-abc', $batch[1]['body']['traceId']);
        self::assertSame('gpt-4o-mini', $batch[1]['body']['model']);
        self::assertSame(['input' => 606, 'output' => 295], $batch[1]['body']['usage']);
        self::assertSame('DEFAULT', $batch[1]['body']['level']);
    }

    public function testEachStepBecomesAnOrderedSpanAndCostRidesOnTheGeneration(): void
    {
        $trace = new RequestTrace(
            correlationId: 'corr-steps',
            name: 'copilot.brief',
            user: 'physician',
            startedAtMs: 1_700_000_000_000,
            durationMs: 3200,
            metadata: ['facts' => 14],
            model: 'gpt-4o-mini',
            promptTokens: 606,
            completionTokens: 295,
            llmDurationMs: 3100,
            status: 'AI summary unavailable: provider error',
            steps: [
                new Step('authorize_and_assemble_facts', 1_700_000_000_000, 40, null, ['facts' => 14]),
                new Step('llm.briefing', 1_700_000_000_050, 3100, 'LlmUpstreamError: Upstream HTTP 503', []),
            ],
            costUsd: 0.000268,
        );

        $this->tracer(new MockHandler([new Response(207, [], '{}')]))->record($trace);

        $batch = $this->sentBody()['batch'];
        self::assertIsArray($batch);
        self::assertSame(['trace-create', 'span-create', 'span-create', 'generation-create'], array_column($batch, 'type'));
        self::assertIsArray($batch[0]);
        self::assertIsArray($batch[0]['body']);
        self::assertIsArray($batch[0]['body']['metadata']);
        self::assertSame(0.000268, $batch[0]['body']['metadata']['cost_usd']);
        self::assertIsArray($batch[1]);
        self::assertIsArray($batch[1]['body']);
        self::assertSame('corr-steps', $batch[1]['body']['traceId']);
        self::assertSame('authorize_and_assemble_facts', $batch[1]['body']['name']);
        self::assertSame('2023-11-14T22:13:20.000Z', $batch[1]['body']['startTime']);
        self::assertSame('2023-11-14T22:13:20.040Z', $batch[1]['body']['endTime']);
        self::assertSame('DEFAULT', $batch[1]['body']['level']);
        self::assertIsArray($batch[2]);
        self::assertIsArray($batch[2]['body']);
        self::assertSame('ERROR', $batch[2]['body']['level']);
        self::assertSame('LlmUpstreamError: Upstream HTTP 503', $batch[2]['body']['statusMessage']);
        self::assertIsArray($batch[3]);
        self::assertIsArray($batch[3]['body']);
        self::assertSame(['input' => 606, 'output' => 295, 'totalCost' => 0.000268], $batch[3]['body']['usage']);
    }

    public function testNoGenerationEventWhenTheModelWasNotCalled(): void
    {
        $trace = new RequestTrace('corr-2', 'copilot.brief', 'admin', 1_700_000_000_000, 5, ['from_cache' => true], null, 0, 0, 0, null);

        $this->tracer(new MockHandler([new Response(207, [], '{}')]))->record($trace);

        $batch = $this->sentBody()['batch'];
        self::assertIsArray($batch);
        self::assertCount(1, $batch);
    }

    public function testFailedGenerationIsMarkedError(): void
    {
        $trace = new RequestTrace('corr-3', 'copilot.brief', 'admin', 1_700_000_000_000, 10_000, [], 'gpt-4o-mini', 0, 0, 10_000, 'AI summary unavailable: timed out');

        $this->tracer(new MockHandler([new Response(207, [], '{}')]))->record($trace);

        $batch = $this->sentBody()['batch'];
        self::assertIsArray($batch);
        self::assertIsArray($batch[1]);
        self::assertIsArray($batch[1]['body']);
        self::assertSame('ERROR', $batch[1]['body']['level']);
        self::assertSame('AI summary unavailable: timed out', $batch[1]['body']['statusMessage']);
    }

    public function testTransportFailureIsSwallowed(): void
    {
        $tracer = $this->tracer(new MockHandler([new ConnectException('down', new Request('POST', '/'))]));

        $tracer->record($this->trace());

        self::assertCount(1, $this->history);
    }
}
