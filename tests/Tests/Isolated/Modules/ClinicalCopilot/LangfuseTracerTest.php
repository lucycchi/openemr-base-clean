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

/**
 * LangfuseTracer against a Guzzle MockHandler: inspects the ingestion batch
 * it POSTs. Pins the basic-auth header, the trace/span/generation event
 * structure (one span per step, in order; generation only when a model was
 * called, marked ERROR on failure, carrying cost), and the boolean score
 * rules that the alert thresholds depend on — request_ok, tool_ok,
 * verification_pass, warm_hit — including that a 403 denial is "ok" and
 * that a site without pre-warm emits no warm_hit score. A transport
 * failure must be swallowed, never thrown into the clinical request.
 */
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
            metadata: ['facts' => 14, 'stripped' => 0, 'omitted' => 0, 'from_cache' => false, 'http_status' => 200, 'verification_pass' => true],
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

        $batch = $this->types('trace-create', 'generation-create');
        self::assertCount(2, $batch);
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

    /**
     * Filters the captured batch to events of the given types (e.g. 'span-create').
     *
     * @return list<array<string, mixed>> batch events of the given types, in order
     */
    private function types(string ...$types): array
    {
        $batch = $this->sentBody()['batch'];
        self::assertIsArray($batch);
        $out = [];
        foreach ($batch as $event) {
            self::assertIsArray($event);
            if (in_array($event['type'], $types, true)) {
                /** @var array<string, mixed> $event */
                $out[] = $event;
            }
        }
        return $out;
    }

    /** @return array<string, bool> score name => value, from the batch */
    private function scores(): array
    {
        $batch = $this->sentBody()['batch'];
        self::assertIsArray($batch);
        $out = [];
        foreach ($batch as $event) {
            self::assertIsArray($event);
            if ($event['type'] !== 'score-create') {
                continue;
            }
            self::assertIsArray($event['body']);
            self::assertSame('corr-abc', $event['body']['traceId']);
            self::assertSame('BOOLEAN', $event['body']['dataType']);
            self::assertIsString($event['body']['name']);
            self::assertIsInt($event['body']['value']);
            $out[$event['body']['name']] = $event['body']['value'] === 1;
        }
        return $out;
    }

    public function testAHealthyRequestScoresAllThreeBooleansTrue(): void
    {
        $this->tracer(new MockHandler([new Response(207, [], '{}')]))->record($this->trace());

        self::assertSame(['request_ok' => true, 'verification_pass' => true, 'tool_ok' => true], $this->scores());
    }

    public function testAProviderFailureScoresRequestOkFalseAndToolOkFalse(): void
    {
        $failed = new Step('llm.briefing', 1_700_000_000_500, 12000, 'LlmTimeout: Connection failed or timed out', []);
        $trace = new RequestTrace('corr-abc', 'copilot.brief', 'physician', 1_700_000_000_000, 12100, ['http_status' => 200, 'verification_pass' => false], 'gpt-4o-mini', 0, 0, 12000, 'AI summary unavailable: timed out', [$failed]);

        $this->tracer(new MockHandler([new Response(207, [], '{}')]))->record($trace);

        self::assertSame(['request_ok' => false, 'verification_pass' => false, 'tool_ok' => false], $this->scores());
    }

    public function testATotalVerificationFailureScoresVerificationFalseButRequestOk(): void
    {
        $trace = new RequestTrace('corr-abc', 'copilot.brief', 'physician', 1_700_000_000_000, 3000, ['http_status' => 200, 'verification_pass' => false, 'total_failure' => true], 'gpt-4o-mini', 500, 40, 2900, null, []);

        $this->tracer(new MockHandler([new Response(207, [], '{}')]))->record($trace);

        self::assertSame(['request_ok' => true, 'verification_pass' => false, 'tool_ok' => true], $this->scores());
    }

    public function testAWarmResultInMetadataBecomesAWarmHitScore(): void
    {
        $trace = new RequestTrace('corr-abc', 'copilot.brief', 'physician', 1_700_000_000_000, 400, ['http_status' => 200, 'verification_pass' => true, 'warm_result' => 'hit'], 'gpt-4o-mini', 0, 0, 0, null, []);
        $this->tracer(new MockHandler([new Response(207, [], '{}')]))->record($trace);

        self::assertSame(['request_ok' => true, 'verification_pass' => true, 'tool_ok' => true, 'warm_hit' => true], $this->scores());
    }

    public function testAWarmMissScoresFalseAndNoWarmResultScoresNothing(): void
    {
        $trace = new RequestTrace('corr-abc', 'copilot.brief', 'physician', 1_700_000_000_000, 400, ['http_status' => 200, 'verification_pass' => true, 'warm_result' => 'miss'], 'gpt-4o-mini', 0, 0, 0, null, []);
        $this->tracer(new MockHandler([new Response(207, [], '{}')]))->record($trace);

        self::assertFalse($this->scores()['warm_hit']);
    }

    public function testAnAccessDenialScoresRequestOkTrueWithNoVerificationScore(): void
    {
        $trace = new RequestTrace('corr-abc', 'copilot.brief', 'receptionist', 1_700_000_000_000, 5, ['http_status' => 403, 'denied' => true], null, 0, 0, 0, 'access denied', []);

        $this->tracer(new MockHandler([new Response(207, [], '{}')]))->record($trace);

        self::assertSame(['request_ok' => true, 'tool_ok' => true], $this->scores());
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

        $batch = $this->types('trace-create', 'span-create', 'generation-create');
        self::assertSame(['trace-create', 'span-create', 'span-create', 'generation-create'], array_column($batch, 'type'));
        self::assertIsArray($batch[0]['body']);
        self::assertIsArray($batch[0]['body']['metadata']);
        self::assertSame(0.000268, $batch[0]['body']['metadata']['cost_usd']);
        self::assertIsArray($batch[1]['body']);
        self::assertSame('corr-steps', $batch[1]['body']['traceId']);
        self::assertSame('authorize_and_assemble_facts', $batch[1]['body']['name']);
        self::assertSame('2023-11-14T22:13:20.000Z', $batch[1]['body']['startTime']);
        self::assertSame('2023-11-14T22:13:20.040Z', $batch[1]['body']['endTime']);
        self::assertSame('DEFAULT', $batch[1]['body']['level']);
        self::assertIsArray($batch[2]['body']);
        self::assertSame('ERROR', $batch[2]['body']['level']);
        self::assertSame('LlmUpstreamError: Upstream HTTP 503', $batch[2]['body']['statusMessage']);
        self::assertIsArray($batch[3]['body']);
        self::assertSame(['input' => 606, 'output' => 295, 'totalCost' => 0.000268], $batch[3]['body']['usage']);
    }

    // ---- Week 2: the sidecar in the trace ----------------------------------

    /** @return list<array{from: string, to: string, reason: string, state_keys_changed: list<string>, ms: int}> */
    private static function handoffs(string $outcome = 'worker_finished'): array
    {
        return [
            ['from' => 'supervisor', 'to' => 'intake_extractor', 'reason' => 'stored_document', 'state_keys_changed' => [], 'ms' => 2],
            ['from' => 'intake_extractor', 'to' => 'supervisor', 'reason' => $outcome, 'state_keys_changed' => ['extractions', 'usage'], 'ms' => 4100],
            ['from' => 'supervisor', 'to' => 'done', 'reason' => 'no_question', 'state_keys_changed' => [], 'ms' => 1],
        ];
    }

    private function extractionTrace(string $outcome = 'worker_finished', int $unverified = 0): RequestTrace
    {
        return new RequestTrace(
            correlationId: 'corr-abc',
            name: 'copilot.documents.extract',
            user: 'physician',
            startedAtMs: 1_700_000_000_000,
            durationMs: 4300,
            metadata: ['http_status' => 200, 'doc_type' => 'lab_pdf', 'status' => $outcome === 'worker_finished' ? 'extracted' : 'failed', 'unverified' => $unverified, 'unextracted' => 0, 'handoffs' => self::handoffs($outcome), 'model_calls' => 3, 'sidecar_retries' => 1],
            model: null,
            promptTokens: 0,
            completionTokens: 0,
            llmDurationMs: 4100,
            status: null,
            steps: [new Step('sidecar_extract_and_persist', 1_700_000_000_050, 4200, null, ['handoffs' => 3])],
            costUsd: 0.0015,
            sidecarUsage: [
                ['model' => 'gpt-4o-mini', 'kind' => 'chat', 'input' => 1200, 'output' => 300, 'cost_usd' => 0.00036],
                ['model' => 'gpt-4o-mini', 'kind' => 'chat', 'input' => 900, 'output' => 200, 'cost_usd' => 0.000255],
                ['model' => 'gpt-4o-mini', 'kind' => 'chat', 'input' => 400, 'output' => 80, 'cost_usd' => 0.000108],
            ],
        );
    }

    public function testEachWorkerHandoffBecomesASpanInsideTheSidecarStep(): void
    {
        $this->tracer(new MockHandler([new Response(207, [], '{}')]))->record($this->extractionTrace());

        $spans = $this->types('span-create');
        self::assertSame(['sidecar_extract_and_persist', 'sidecar.intake_extractor'], array_map(static fn(array $e) => is_array($e['body']) ? $e['body']['name'] : null, $spans));
        $worker = $spans[1]['body'];
        self::assertIsArray($worker);
        // Opens 2 ms after the PHP step that called the sidecar, lasts the worker's own 4,100 ms.
        self::assertSame('2023-11-14T22:13:20.052Z', $worker['startTime']);
        self::assertSame('2023-11-14T22:13:24.152Z', $worker['endTime']);
        self::assertSame('DEFAULT', $worker['level']);
        self::assertSame(['routed_because' => 'stored_document', 'outcome' => 'worker_finished', 'duration_ms' => 4100], $worker['metadata']);
    }

    public function testAFailedWorkerIsAnErrorSpanAndRoutingScoresFalse(): void
    {
        $this->tracer(new MockHandler([new Response(207, [], '{}')]))->record($this->extractionTrace('worker_failed'));

        $worker = $this->types('span-create')[1]['body'];
        self::assertIsArray($worker);
        self::assertSame('ERROR', $worker['level']);
        self::assertSame('worker_failed', $worker['statusMessage']);
        $scores = $this->scores();
        self::assertFalse($scores['routing_ok']);
        self::assertFalse($scores['extraction_ok']);
        self::assertFalse($scores['extraction_verified']);
    }

    public function testEverySidecarModelCallIsItsOwnGenerationAndNoAggregateIsSent(): void
    {
        $this->tracer(new MockHandler([new Response(207, [], '{}')]))->record($this->extractionTrace());

        $gens = $this->types('generation-create');
        self::assertCount(3, $gens, 'one generation per sidecar call, no aggregate');
        foreach ($gens as $g) {
            self::assertIsArray($g['body']);
            self::assertSame('sidecar.chat', $g['body']['name']);
            self::assertSame('gpt-4o-mini', $g['body']['model']);
            self::assertSame('corr-abc', $g['body']['traceId']);
        }
        $first = $gens[0]['body'];
        self::assertIsArray($first);
        self::assertSame(['input' => 1200, 'output' => 300, 'totalCost' => 0.00036], $first['usage']);
        $trace = $this->types('trace-create')[0]['body'];
        self::assertIsArray($trace);
        self::assertIsArray($trace['metadata']);
        self::assertSame(0.0015, $trace['metadata']['cost_usd']);
        self::assertSame(1, $trace['metadata']['sidecar_retries']);
    }

    public function testAFailedWorkerAlsoFailsTheToolRate(): void
    {
        // The tool-failure alert reads tool_ok; the agent's workers must count as tools.
        $this->tracer(new MockHandler([new Response(207, [], '{}')]))->record($this->extractionTrace('worker_failed'));
        self::assertFalse($this->scores()['tool_ok']);
    }

    public function testAnUnreadableUploadIsNotAServiceError(): void
    {
        // encrypted / unreadable / too_many_pages describe the physician's document:
        // extraction_ok says it failed, request_ok (the error-rate alert) stays true.
        $userFault = new RequestTrace('corr-abc', 'copilot.documents.extract', 'physician', 1_700_000_000_000, 900, ['http_status' => 200, 'doc_type' => 'lab_pdf', 'status' => 'failed', 'failure_reason' => 'encrypted', 'unverified' => 0, 'unextracted' => 0, 'handoffs' => self::handoffs('worker_failed')], null, 0, 0, 0, 'encrypted');
        $this->tracer(new MockHandler([new Response(207, [], '{}')]))->record($userFault);
        $scores = $this->scores();
        self::assertTrue($scores['request_ok']);
        self::assertFalse($scores['extraction_ok']);

        $serviceFault = new RequestTrace('corr-abc', 'copilot.documents.extract', 'physician', 1_700_000_000_000, 900, ['http_status' => 200, 'doc_type' => 'lab_pdf', 'status' => 'failed', 'failure_reason' => 'model_error', 'unverified' => 0, 'unextracted' => 0, 'handoffs' => self::handoffs('worker_failed')], null, 0, 0, 0, 'model_error');
        $this->tracer(new MockHandler([new Response(207, [], '{}')]))->record($serviceFault);
        self::assertFalse($this->scores()['request_ok'], 'a model error during extraction is a service error');
    }

    public function testExtractionScoresVerifiedOnlyWhenNothingWasUnverified(): void
    {
        $this->tracer(new MockHandler([new Response(207, [], '{}')]))->record($this->extractionTrace('worker_finished', 2));

        $scores = $this->scores();
        self::assertTrue($scores['extraction_ok']);
        self::assertFalse($scores['extraction_verified']);
        self::assertTrue($scores['routing_ok']);
        self::assertArrayNotHasKey('verification_pass', $scores, 'an extraction is not a narration');
        self::assertArrayNotHasKey('retrieval_hit', $scores);
    }

    public function testAFollowUpScoresRetrievalHitFromTheChunkCount(): void
    {
        $ask = new RequestTrace('corr-abc', 'copilot.ask', 'physician', 1_700_000_000_000, 900, ['http_status' => 200, 'guideline_chunks' => 0, 'handoffs' => [['from' => 'supervisor', 'to' => 'evidence_retriever', 'reason' => 'question_present', 'state_keys_changed' => [], 'ms' => 1], ['from' => 'evidence_retriever', 'to' => 'supervisor', 'reason' => 'worker_finished', 'state_keys_changed' => ['chunks'], 'ms' => 300], ['from' => 'supervisor', 'to' => 'done', 'reason' => 'worker_finished', 'state_keys_changed' => [], 'ms' => 0]], 'verification_pass' => true], 'gpt-4o-mini', 500, 40, 600, null, [], 0.0001, [['model' => 'text-embedding-3-small', 'kind' => 'embedding', 'input' => 12, 'output' => 0, 'cost_usd' => 0.00000024]]);
        $this->tracer(new MockHandler([new Response(207, [], '{}')]))->record($ask);

        $scores = $this->scores();
        self::assertFalse($scores['retrieval_hit']);
        self::assertTrue($scores['routing_ok']);
        self::assertTrue($scores['verification_pass']);
        // The sidecar's embedding call (which ran first) and the PHP model call are separate generations.
        self::assertSame(['sidecar.embedding', 'copilot.ask.llm'], array_map(static fn(array $e) => is_array($e['body']) ? $e['body']['name'] : null, $this->types('generation-create')));
        $retriever = $this->types('span-create')[0]['body'] ?? null;
        self::assertIsArray($retriever);
        self::assertSame('sidecar.evidence_retriever', $retriever['name']);
    }

    public function testAPrewarmSweepScoresOkWhenNoPatientErrored(): void
    {
        $sweep = new RequestTrace('corr-abc', 'copilot.prewarm', 'cron', 1_700_000_000_000, 12000, ['scheduled' => 8, 'warmed' => 6, 'already_cached' => 2, 'skipped' => 0, 'errored' => 0, 'queue_depth_after' => 0], null, 0, 0, 0, null);
        $this->tracer(new MockHandler([new Response(207, [], '{}')]))->record($sweep);
        self::assertSame(['request_ok' => true, 'tool_ok' => true, 'prewarm_ok' => true], $this->scores());
    }

    public function testAPrewarmSweepWithAnErroredPatientScoresFalse(): void
    {
        $failed = new RequestTrace('corr-abc', 'copilot.prewarm', 'cron', 1_700_000_000_000, 12000, ['scheduled' => 8, 'warmed' => 5, 'already_cached' => 2, 'skipped' => 0, 'errored' => 1, 'queue_depth_after' => 1], null, 0, 0, 0, '1 of 8 scheduled patients errored');
        $this->tracer(new MockHandler([new Response(207, [], '{}')]))->record($failed);
        $scores = $this->scores();
        self::assertFalse($scores['prewarm_ok']);
        self::assertFalse($scores['request_ok'], 'a sweep with errors is a failed request');
    }

    public function testNoGenerationEventWhenTheModelWasNotCalled(): void
    {
        $trace = new RequestTrace('corr-2', 'copilot.brief', 'admin', 1_700_000_000_000, 5, ['from_cache' => true], null, 0, 0, 0, null);

        $this->tracer(new MockHandler([new Response(207, [], '{}')]))->record($trace);

        self::assertCount(0, $this->types('generation-create'));
        self::assertCount(1, $this->types('trace-create'));
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
