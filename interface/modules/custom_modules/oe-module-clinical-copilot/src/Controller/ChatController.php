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
use OpenEMR\Common\Session\EncounterSessionUtil;
use OpenEMR\Common\Session\PatientSessionUtil;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Modules\ClinicalCopilot\AccessDeniedException;
use OpenEMR\Modules\ClinicalCopilot\AclAuthorization;
use OpenEMR\Modules\ClinicalCopilot\AssembledFacts;
use OpenEMR\Modules\ClinicalCopilot\BriefingResult;
use OpenEMR\Modules\ClinicalCopilot\Config;
use OpenEMR\Modules\ClinicalCopilot\CorrelationId;
use OpenEMR\Modules\ClinicalCopilot\DbBriefingCache;
use OpenEMR\Modules\ClinicalCopilot\FactAssembler;
use OpenEMR\Modules\ClinicalCopilot\Llm\OpenAiClient;
use OpenEMR\Modules\ClinicalCopilot\NarrationPipeline;
use OpenEMR\Modules\ClinicalCopilot\OmissionGuard;
use OpenEMR\Modules\ClinicalCopilot\OpenEmrChartSource;
use OpenEMR\Modules\ClinicalCopilot\PanelPayload;
use OpenEMR\Modules\ClinicalCopilot\PatientId;
use OpenEMR\Modules\ClinicalCopilot\VerificationResult;
use OpenEMR\Modules\ClinicalCopilot\Verifier;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

final class ChatController
{
    private readonly string $correlationId;
    private readonly LoggerInterface $logger;
    private readonly Request $request;

    public function __construct(?LoggerInterface $logger = null, ?Request $request = null)
    {
        $this->correlationId = CorrelationId::generate();
        $this->logger = $logger ?? ServiceContainer::getLogger();
        $this->request = $request ?? HttpRestRequest::createFromGlobals();
    }

    public function handleRequest(): void
    {
        $started = hrtime(true);
        $session = SessionWrapperFactory::getInstance()->getActiveSession();
        $user = $session->get('authUser');
        $user = is_string($user) ? $user : '';

        if (!CsrfUtils::verifyCsrfToken($this->request->request->getString('csrf_token_form'), session: $session)) {
            $this->respond(['error' => 'CSRF verification failed'], 403);
            return;
        }
        if ($user === '') {
            $this->respond(['error' => 'Not authenticated'], 401);
            return;
        }
        $pidValue = PatientSessionUtil::getPid();
        if ($pidValue <= 0) {
            $this->respond(['error' => 'No patient selected'], 400);
            return;
        }
        $pid = new PatientId($pidValue);
        $encounter = EncounterSessionUtil::getEncounter();
        $action = $this->request->request->getString('action');

        $this->logger->info('copilot request', [
            'correlation_id' => $this->correlationId,
            'action' => $action,
            'pid' => $pid->value,
            'encounter' => $encounter,
            'user' => $user,
        ]);

        try {
            $assembler = new FactAssembler(new OpenEmrChartSource(), new AclAuthorization($user), ServiceContainer::getClock());
            $assembled = $assembler->assemble($pid, $encounter > 0 ? $encounter : null);
        } catch (AccessDeniedException) {
            $this->logger->warning('copilot access denied', ['correlation_id' => $this->correlationId, 'user' => $user]);
            $this->respond(['error' => 'You are not authorized to view this chart', 'correlation_id' => $this->correlationId], 403);
            return;
        }

        $config = Config::fromEnvironment();
        $payload = match ($action) {
            'brief' => $this->brief($assembled, $config, $pid),
            'ask' => $this->ask($assembled, $config, $pid),
            default => ['error' => 'Unknown action', 'correlation_id' => $this->correlationId],
        };

        $outcome = $payload['narration'] ?? $payload['answer'] ?? null;
        $outcome = is_array($outcome) ? $outcome : [];
        $this->logger->info('copilot response', [
            'correlation_id' => $this->correlationId,
            'action' => $action,
            'facts' => count($assembled->facts()->all()),
            'ms' => round((hrtime(true) - $started) / 1e6),
            'status' => $outcome['status'] ?? null,
            'stripped' => $outcome['stripped'] ?? null,
            'from_cache' => $outcome['from_cache'] ?? null,
        ]);
        $this->respond($payload, isset($payload['error']) ? 400 : 200);
    }

    /** @return array<string, mixed> */
    private function brief(AssembledFacts $assembled, Config $config, PatientId $pid): array
    {
        if (!$config->hasOpenAi()) {
            return PanelPayload::briefing($assembled, $this->unconfigured($assembled), $this->correlationId);
        }
        return PanelPayload::briefing($assembled, $this->pipeline($config, $assembled, $pid)->brief($assembled), $this->correlationId);
    }

    /** @return array<string, mixed> */
    private function ask(AssembledFacts $assembled, Config $config, PatientId $pid): array
    {
        if ($this->request->request->getString('facts_hash') !== $assembled->facts()->hash()) {
            return PanelPayload::chartChanged($assembled, $this->correlationId);
        }
        $question = trim(mb_substr($this->request->request->getString('question'), 0, 500));
        if ($question === '') {
            return ['error' => 'Question is required', 'correlation_id' => $this->correlationId];
        }
        if (!$config->hasOpenAi()) {
            return ['error' => 'AI is not configured on this server', 'correlation_id' => $this->correlationId];
        }
        $answer = $this->pipeline($config, $assembled, $pid)->answer($assembled, $question, $this->transcript());
        return PanelPayload::answer($assembled, $answer, $this->correlationId);
    }

    private function pipeline(Config $config, AssembledFacts $assembled, PatientId $pid): NarrationPipeline
    {
        $llm = new OpenAiClient(new Client(), $config->openAiApiKey, $config->openAiModel);
        $cache = new DbBriefingCache($pid, $assembled->facts()->hash(), $config->openAiModel);
        return new NarrationPipeline($llm, new Verifier(), new OmissionGuard(), $cache);
    }

    private function unconfigured(AssembledFacts $assembled): BriefingResult
    {
        $omitted = (new OmissionGuard())->omitted(new VerificationResult([], []), $assembled->facts());
        return new BriefingResult([], 0, $omitted, 'AI summary unavailable: not configured on this server', false, false, 0, 0);
    }

    /** @return list<array{role: string, text: string}> */
    private function transcript(): array
    {
        $raw = $this->request->request->getString('transcript', '[]');
        try {
            $decoded = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        $turns = [];
        foreach (is_array($decoded) ? array_slice($decoded, -10) : [] as $turn) {
            if (!is_array($turn)) {
                continue;
            }
            $role = $turn['role'] ?? '';
            $text = $turn['text'] ?? '';
            if (is_string($role) && is_string($text) && in_array($role, ['user', 'assistant'], true) && $text !== '') {
                $turns[] = ['role' => $role, 'text' => mb_substr($text, 0, 1000)];
            }
        }
        return $turns;
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
