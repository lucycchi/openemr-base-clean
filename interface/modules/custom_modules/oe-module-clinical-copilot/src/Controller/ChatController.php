<?php

/**
 * Panel endpoint: session-bound patient, CSRF, ACL, then brief or answer.
 *
 * The patient id comes from the OpenEMR session only; a pid in the request
 * is ignored. Every response carries the correlation id.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot\Controller;

use GuzzleHttp\Client;
use OpenEMR\BC\ServiceContainer;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Http\HttpRestRequest;
use OpenEMR\Common\Logging\EventAuditLogger;
use OpenEMR\Common\Session\EncounterSessionUtil;
use OpenEMR\Common\Session\PatientSessionUtil;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Modules\ClinicalCopilot\AccessDeniedException;
use OpenEMR\Modules\ClinicalCopilot\AclAuthorization;
use OpenEMR\Modules\ClinicalCopilot\AssembledFacts;
use OpenEMR\Modules\ClinicalCopilot\BriefingResult;
use OpenEMR\Modules\ClinicalCopilot\ChatAction;
use OpenEMR\Modules\ClinicalCopilot\ChatRequest;
use OpenEMR\Modules\ClinicalCopilot\Config;
use OpenEMR\Modules\ClinicalCopilot\CorrelationId;
use OpenEMR\Modules\ClinicalCopilot\DbBriefingCache;
use OpenEMR\Modules\ClinicalCopilot\FactAssembler;
use OpenEMR\Modules\ClinicalCopilot\InvalidRequest;
use OpenEMR\Modules\ClinicalCopilot\Llm\OpenAiClient;
use OpenEMR\Modules\ClinicalCopilot\NarrationPipeline;
use OpenEMR\Modules\ClinicalCopilot\OmissionGuard;
use OpenEMR\Modules\ClinicalCopilot\OpenEmrChartSource;
use OpenEMR\Modules\ClinicalCopilot\Ops\CorrelatedLogger;
use OpenEMR\Modules\ClinicalCopilot\Ops\LangfuseTracer;
use OpenEMR\Modules\ClinicalCopilot\Ops\NullTracer;
use OpenEMR\Modules\ClinicalCopilot\Ops\RequestTrace;
use OpenEMR\Modules\ClinicalCopilot\Ops\StepRecorder;
use OpenEMR\Modules\ClinicalCopilot\Ops\Tracer;
use OpenEMR\Modules\ClinicalCopilot\PanelPayload;
use OpenEMR\Modules\ClinicalCopilot\PatientId;
use OpenEMR\Modules\ClinicalCopilot\Pricing;
use OpenEMR\Modules\ClinicalCopilot\VerificationResult;
use OpenEMR\Modules\ClinicalCopilot\Verifier;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

final class ChatController
{
    private readonly string $correlationId;
    private readonly LoggerInterface $logger;
    private readonly Request $request;
    private readonly Config $config;
    private readonly Tracer $tracer;
    private readonly StepRecorder $steps;
    private int $llmMs = 0;
    private bool $llmCalled = false;
    private int $llmAttempts = 0;

    public function __construct(?LoggerInterface $logger = null, ?Request $request = null, ?Config $config = null, ?Tracer $tracer = null)
    {
        $this->correlationId = CorrelationId::generate();
        $this->logger = new CorrelatedLogger($logger ?? ServiceContainer::getLogger(), $this->correlationId);
        $this->request = $request ?? HttpRestRequest::createFromGlobals();
        $this->config = $config ?? Config::fromEnvironment();
        $this->steps = new StepRecorder();
        $this->tracer = $tracer ?? ($this->config->hasLangfuse()
            ? new LangfuseTracer(new Client(), $this->config->langfuseHost, $this->config->langfusePublicKey, $this->config->langfuseSecretKey)
            : new NullTracer());
    }

    public function handleRequest(): void
    {
        $started = hrtime(true);
        $startedAtMs = (int) round(microtime(true) * 1000);
        try {
            $this->handle($started, $startedAtMs);
        } catch (\Throwable $e) {
            // Anything unexpected still gets a correlation id, a log line with
            // the exception, a trace with the failed step, and a JSON body;
            // then it propagates so the failure is never swallowed.
            $this->logger->error('copilot request failed', ['exception' => $e, 'steps' => $this->stepSummary()]);
            $this->tracer->record(new RequestTrace(
                $this->correlationId,
                'copilot.' . $this->request->request->getString('action'),
                '',
                $startedAtMs,
                (int) round((hrtime(true) - $started) / 1e6),
                ['http_status' => 500],
                null,
                0,
                0,
                0,
                StepRecorder::describe($e),
                $this->steps->all(),
            ));
            $this->respond(['error' => 'The Co-Pilot hit an internal error', 'correlation_id' => $this->correlationId], 500);
            throw $e;
        }
    }

    private function handle(int|float $started, int $startedAtMs): void
    {
        $session = SessionWrapperFactory::getInstance()->getActiveSession();
        $user = $session->get('authUser');
        $user = is_string($user) ? $user : '';

        // Parse the body against contracts/chat.request.schema.json before
        // anything else; a body that violates it never reaches the chart.
        try {
            $chat = ChatRequest::fromBag($this->request->request);
        } catch (InvalidRequest $e) {
            $this->respond(['error' => $e->getMessage(), 'correlation_id' => $this->correlationId], $e->httpStatus);
            return;
        }
        if (!CsrfUtils::verifyCsrfToken($chat->csrfToken, session: $session)) {
            $this->respond(['error' => 'CSRF verification failed', 'correlation_id' => $this->correlationId], 403);
            return;
        }
        if ($user === '') {
            $this->respond(['error' => 'Not authenticated', 'correlation_id' => $this->correlationId], 401);
            return;
        }
        $pidValue = PatientSessionUtil::getPid();
        if ($pidValue <= 0) {
            $this->respond(['error' => 'No patient selected', 'correlation_id' => $this->correlationId], 400);
            return;
        }
        $pid = new PatientId($pidValue);
        $encounter = EncounterSessionUtil::getEncounter();
        $action = $chat->action->value;

        $this->logger->notice('copilot request', [
            'action' => $action,
            'pid' => $pid->value,
            'encounter' => $encounter,
            'user' => $user,
        ]);

        try {
            $assembled = $this->steps->measure(
                'authorize_and_assemble_facts',
                fn() => (new FactAssembler(new OpenEmrChartSource(), new AclAuthorization($user), ServiceContainer::getClock()))->assemble($pid, $encounter > 0 ? $encounter : null),
                static fn(AssembledFacts $a) => ['facts' => count($a->facts()->all()), 'prior_visit' => $a->priorEncounter()?->date->format('Y-m-d')],
            );
        } catch (AccessDeniedException) {
            $this->logger->warning('copilot access denied', ['user' => $user, 'steps' => $this->stepSummary()]);
            EventAuditLogger::getInstance()->newEvent('clinical-copilot', $user, is_string($session->get('authProvider')) ? $session->get('authProvider') : '', 0, 'action=' . $action . ' correlation_id=' . $this->correlationId . ' denied=acl', $pid->value);
            $this->tracer->record(new RequestTrace($this->correlationId, "copilot.$action", $user, $startedAtMs, (int) round((hrtime(true) - $started) / 1e6), ['http_status' => 403, 'denied' => true], null, 0, 0, 0, 'access denied', $this->steps->all()));
            $this->respond(['error' => 'You are not authorized to view this chart', 'correlation_id' => $this->correlationId], 403);
            return;
        }

        $config = $this->config;
        $payload = match ($chat->action) {
            ChatAction::Brief => $this->brief($assembled, $config, $pid),
            ChatAction::Ask => $this->ask($chat, $assembled, $config, $pid),
        };

        $outcome = $payload['narration'] ?? $payload['answer'] ?? null;
        $outcome = is_array($outcome) ? $outcome : [];
        $httpStatus = isset($payload['error']) ? 400 : 200;
        $totalMs = (int) round((hrtime(true) - $started) / 1e6);
        $tokens = is_array($outcome['tokens'] ?? null) ? $outcome['tokens'] : [];
        $promptTokens = is_int($tokens['prompt'] ?? null) ? $tokens['prompt'] : 0;
        $completionTokens = is_int($tokens['completion'] ?? null) ? $tokens['completion'] : 0;
        $costUsd = $this->llmCalled ? Pricing::fromConfig($config)->costUsd($config->openAiModel, $promptTokens, $completionTokens) : 0.0;
        $status = is_string($outcome['status'] ?? null) ? $outcome['status'] : null;
        $metadata = [
            'action' => $action,
            'http_status' => $httpStatus,
            'facts' => count($assembled->facts()->all()),
            'stripped' => is_int($outcome['stripped'] ?? null) ? $outcome['stripped'] : null,
            'omitted' => is_array($outcome['omitted_fact_ids'] ?? null) ? count($outcome['omitted_fact_ids']) : null,
            'from_cache' => is_bool($outcome['from_cache'] ?? null) ? $outcome['from_cache'] : null,
            'total_failure' => is_bool($outcome['total_failure'] ?? null) ? $outcome['total_failure'] : null,
            'answer_type' => is_string($outcome['type'] ?? null) ? $outcome['type'] : null,
            'chart_changed' => is_bool($payload['chart_changed'] ?? null) ? $payload['chart_changed'] : null,
            'verification_pass' => $status === null && ($outcome['total_failure'] ?? false) !== true,
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'cost_usd' => $costUsd,
            'llm_attempts' => $this->llmAttempts,
            'llm_retried' => $this->llmAttempts > 1,
        ];
        // One line per failed tool with the real reason (the user-facing
        // status label above is deliberately vague), then one line per request
        // with the ordered steps and their timings.
        foreach ($this->steps->failed() as $failed) {
            $this->logger->warning('copilot tool failed', $failed->toLogContext());
        }
        $this->logger->notice('copilot response', $metadata + ['ms' => $totalMs, 'llm_ms' => $this->llmMs, 'status' => $status, 'steps' => $this->stepSummary()]);
        // OpenEMR's audit log is the always-on, HIPAA-facing record of who ran
        // the agent on which patient. Comment holds no PHI: ids and counts only.
        EventAuditLogger::getInstance()->newEvent(
            'clinical-copilot',
            $user,
            is_string($session->get('authProvider')) ? $session->get('authProvider') : '',
            $httpStatus === 200 ? 1 : 0,
            sprintf('action=%s correlation_id=%s facts=%d stripped=%s from_cache=%s tokens=%d cost_usd=%s llm_attempts=%d status=%s', $action, $this->correlationId, $metadata['facts'], var_export($metadata['stripped'], true), var_export($metadata['from_cache'], true), $promptTokens + $completionTokens, $costUsd === null ? 'unknown' : number_format($costUsd, 6, '.', ''), $this->llmAttempts, $status ?? 'ok'),
            $pid->value
        );
        $this->tracer->record(new RequestTrace(
            $this->correlationId,
            "copilot.$action",
            $user,
            $startedAtMs,
            $totalMs,
            $metadata,
            $this->llmCalled ? $config->openAiModel : null,
            $promptTokens,
            $completionTokens,
            $this->llmMs,
            $status,
            $this->steps->all(),
            $costUsd,
        ));
        $this->respond($payload, $httpStatus);
    }

    /** @return array<string, mixed> */
    private function brief(AssembledFacts $assembled, Config $config, PatientId $pid): array
    {
        if (!$config->hasOpenAi()) {
            return PanelPayload::briefing($assembled, $this->unconfigured($assembled), $this->correlationId);
        }
        $t = hrtime(true);
        $pipeline = $this->pipeline($config, $assembled, $pid);
        $result = $pipeline->brief($assembled);
        $this->llmMs = (int) round((hrtime(true) - $t) / 1e6);
        $this->llmCalled = !$result->fromCache;
        $this->llmAttempts = $pipeline->llmAttempts();
        return PanelPayload::briefing($assembled, $result, $this->correlationId);
    }

    /** @return array<string, mixed> */
    private function ask(ChatRequest $chat, AssembledFacts $assembled, Config $config, PatientId $pid): array
    {
        if ($chat->factsHash !== $assembled->facts()->hash()) {
            return PanelPayload::chartChanged($assembled, $this->correlationId);
        }
        if (!$config->hasOpenAi()) {
            return ['error' => 'AI is not configured on this server', 'correlation_id' => $this->correlationId];
        }
        $t = hrtime(true);
        $pipeline = $this->pipeline($config, $assembled, $pid);
        $answer = $pipeline->answer($assembled, (string) $chat->question, $chat->transcript, $pid);
        $this->llmMs = (int) round((hrtime(true) - $t) / 1e6);
        $this->llmCalled = true;
        $this->llmAttempts = $pipeline->llmAttempts();
        return PanelPayload::answer($assembled, $answer, $this->correlationId);
    }

    private function pipeline(Config $config, AssembledFacts $assembled, PatientId $pid): NarrationPipeline
    {
        $llm = new OpenAiClient(new Client(), $config->openAiApiKey, $config->openAiModel, correlationId: $this->correlationId);
        $cache = new DbBriefingCache($pid, $assembled->facts()->hash(), $config->openAiModel);
        return new NarrationPipeline($llm, new Verifier(), new OmissionGuard(), $cache, steps: $this->steps);
    }

    /** @return list<array<string, scalar|null>> */
    private function stepSummary(): array
    {
        return array_map(static fn($s) => $s->toLogContext(), $this->steps->all());
    }

    private function unconfigured(AssembledFacts $assembled): BriefingResult
    {
        $omitted = (new OmissionGuard())->omitted(new VerificationResult([], []), $assembled->facts());
        return new BriefingResult([], 0, $omitted, 'AI summary unavailable: not configured on this server', false, false, 0, 0);
    }

    /** @param array<string, mixed> $payload */
    private function respond(array $payload, int $status): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        header('X-Correlation-Id: ' . $this->correlationId);
        echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
