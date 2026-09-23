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

/**
 * Ships a RequestTrace to Langfuse's batch ingestion API as one trace, one
 * span per step, one generation (the LLM call) and a few boolean scores.
 * Fire-and-forget with a 2s timeout: if Langfuse is slow or down the
 * clinical request still succeeds and the trace is simply lost.
 */
final readonly class LangfuseTracer implements Tracer
{
    /**
     * Extraction failure reasons (contract run.response) that describe the
     * uploaded document rather than the service; see scores().
     */
    private const USER_CAUSED_FAILURES = ['unreadable', 'encrypted', 'too_many_pages'];

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
        // The batch is a list of events; ids are derived from the correlation
        // id so a retried POST is idempotent on Langfuse's side.
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
        // Week 2: the sidecar's workers as spans. The supervisor's handoff log
        // travels in the trace metadata; here each hop into a worker becomes a
        // span (name sidecar.<worker>, ERROR when the worker reported failure)
        // so the dashboard's tool-call and tool-failure widgets, which read
        // observations, count the workers like every PHP-side tool step.
        foreach (self::workerSpans($t) as $i => $span) {
            $batch[] = [
                'id' => $t->correlationId . '-worker-' . $i,
                'type' => 'span-create',
                'timestamp' => $now,
                'body' => ['id' => $t->correlationId . '-worker-' . $i, 'traceId' => $t->correlationId] + $span,
            ];
        }
        // Week 2: one generation per model call the sidecar made (a chat call
        // per page, the embedding, the rerank), each with its model, tokens and
        // cost, so per-model call counts and spend include the sidecar. The
        // sidecar does not time individual calls; the worker span above holds
        // the latency, so these carry a start time only.
        foreach ($t->sidecarUsage as $i => $u) {
            $batch[] = [
                'id' => $t->correlationId . '-sidecar-gen-' . $i,
                'type' => 'generation-create',
                'timestamp' => $now,
                'body' => [
                    'id' => $t->correlationId . '-sidecar-gen-' . $i,
                    'traceId' => $t->correlationId,
                    'name' => 'sidecar.' . $u['kind'],
                    'model' => $u['model'],
                    'startTime' => $start,
                    'usage' => ['input' => $u['input'], 'output' => $u['output']] + ($u['cost_usd'] === null ? [] : ['totalCost' => $u['cost_usd']]),
                    'level' => 'DEFAULT',
                    'metadata' => ['kind' => $u['kind']],
                ],
            ];
        }
        // A "generation" is Langfuse's object for an LLM call; it carries
        // model, token usage and cost so the dashboard can chart spend.
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

    /**
     * The boolean scores attached to every trace; each one becomes an alertable rate.
     *
     * @return array<string, bool>
     */
    private static function scores(RequestTrace $t): array
    {
        $httpStatus = is_int($t->metadata['http_status'] ?? null) ? $t->metadata['http_status'] : 200;
        // A refusal (403) is a correct outcome, not an error; a 5xx or a
        // non-null status on a served request is. Week 2: a document the
        // physician uploaded that cannot be read (encrypted, not a PDF, too
        // long) is the document's fault, not the service's, so it does not
        // count against the error rate; it is still visible as extraction_ok.
        $userCaused = in_array($t->status, self::USER_CAUSED_FAILURES, true) && is_string($t->metadata['doc_type'] ?? null);
        $scores = ['request_ok' => $httpStatus < 500 && ($httpStatus >= 400 || $t->status === null || $userCaused)];
        if (is_bool($t->metadata['verification_pass'] ?? null)) {
            $scores['verification_pass'] = $t->metadata['verification_pass'];
        }
        $toolOk = true;
        foreach ($t->steps as $step) {
            if ($step->error !== null) {
                $toolOk = false;
            }
        }
        // Week 2: the sidecar's workers are tools too. A worker that reported
        // worker_failed fails the tool-failure rate the same as a PHP step.
        foreach (self::hops($t) as $hop) {
            if ($hop['reason'] === 'worker_failed') {
                $toolOk = false;
            }
        }
        $scores['tool_ok'] = $toolOk;
        // Pre-warm hit rate: present only on chart opens that were evaluated
        // against a receipt, so sites without the sweep score nothing here.
        if (is_string($t->metadata['warm_result'] ?? null)) {
            $scores['warm_hit'] = $t->metadata['warm_result'] === 'hit';
        }
        // Week 2 outcomes as rates. Each score exists only on the traces where
        // the outcome was decided, so a chart-open never dilutes an extraction rate.
        if (is_string($t->metadata['doc_type'] ?? null)) {
            // Document extraction: did it succeed, and did every proposed value and every printed row get anchored?
            $extracted = ($t->metadata['status'] ?? null) === 'extracted';
            $scores['extraction_ok'] = $extracted;
            $scores['extraction_verified'] = $extracted && ($t->metadata['unverified'] ?? 1) === 0 && ($t->metadata['unextracted'] ?? 1) === 0;
        }
        if (is_int($t->metadata['guideline_chunks'] ?? null)) {
            // A follow-up question: did the retriever find guideline evidence for it?
            $scores['retrieval_hit'] = $t->metadata['guideline_chunks'] > 0;
        }
        $hops = self::hops($t);
        if ($hops !== []) {
            // Routing: every worker the supervisor invoked finished.
            $ok = true;
            foreach ($hops as $hop) {
                if ($hop['reason'] === 'worker_failed') {
                    $ok = false;
                }
            }
            $scores['routing_ok'] = $ok;
        }
        if (is_int($t->metadata['scheduled'] ?? null)) {
            // The pre-warm sweep (queue): a run is ok when no scheduled patient errored.
            $scores['prewarm_ok'] = ($t->metadata['errored'] ?? 0) === 0;
        }
        return $scores;
    }

    /**
     * The handoff log from the trace metadata, or [] when the request did not
     * touch the sidecar.
     *
     * @return list<array{from: string, to: string, reason: string, state_keys_changed: list<string>, ms: int}>
     */
    private static function hops(RequestTrace $t): array
    {
        $hops = $t->metadata['handoffs'] ?? null;
        return is_array($hops) ? $hops : [];
    }

    /**
     * The sidecar's workers as span bodies, from the handoff log. A hop from
     * the supervisor into a worker opens the span; the worker's hop back
     * closes it and carries its duration and outcome. Timing is anchored on
     * the PHP step that called the sidecar, so the spans sit inside it on the
     * trace timeline.
     *
     * @return list<array{name: string, startTime: string, endTime: string, level: string, statusMessage: ?string, metadata: array<string, scalar|null>}>
     */
    private static function workerSpans(RequestTrace $t): array
    {
        $hops = self::hops($t);
        $anchor = $t->startedAtMs;
        foreach ($t->steps as $step) {
            if (in_array($step->name, ['sidecar_extract_and_persist', 'retrieve_evidence'], true)) {
                $anchor = $step->startedAtMs;
                break;
            }
        }
        $spans = [];
        $clock = $anchor;
        foreach ($hops as $i => $hop) {
            $clock += $hop['ms'];
            if ($hop['from'] !== 'supervisor' || $hop['to'] === 'done') {
                continue;
            }
            $back = $hops[$i + 1] ?? null;
            $workerMs = $back['ms'] ?? 0;
            $outcome = $back['reason'] ?? 'no_return';
            $spans[] = [
                'name' => 'sidecar.' . $hop['to'],
                'startTime' => self::iso($clock),
                'endTime' => self::iso($clock + $workerMs),
                'level' => $outcome === 'worker_finished' ? 'DEFAULT' : 'ERROR',
                'statusMessage' => $outcome === 'worker_finished' ? null : $outcome,
                'metadata' => ['routed_because' => $hop['reason'], 'outcome' => $outcome, 'duration_ms' => $workerMs],
            ];
        }
        return $spans;
    }

    /** Unix milliseconds -> "2026-09-18T14:03:07.123Z". */
    private static function iso(int $ms): string
    {
        return gmdate('Y-m-d\TH:i:s', intdiv($ms, 1000)) . sprintf('.%03dZ', $ms % 1000);
    }
}
