<?php

/**
 * public/documents.php: upload a lab PDF or intake form for the chart's
 * patient, run the sidecar extraction for one document, or list the
 * patient's documents with their extraction status.
 *
 * Order of checks, every action: parse body (contract) -> CSRF -> logged
 * in -> patient in session -> ACL patients/docs (write|addonly for upload
 * and extract, view for list) -> act -> log + audit + trace -> respond.
 * The patient id comes from the session only; a document id in the body
 * is looked up scoped to that patient, so a forged id from another chart
 * is a 404, never a read.
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
use OpenEMR\Common\Acl\AclMain;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Http\HttpRestRequest;
use OpenEMR\Common\Logging\EventAuditLogger;
use OpenEMR\Common\Session\EncounterSessionUtil;
use OpenEMR\Common\Session\PatientSessionUtil;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Modules\ClinicalCopilot\Config;
use OpenEMR\Modules\ClinicalCopilot\CorrelationId;
use OpenEMR\Modules\ClinicalCopilot\Documents\DocumentAction;
use OpenEMR\Modules\ClinicalCopilot\Documents\DocumentIngestService;
use OpenEMR\Modules\ClinicalCopilot\Documents\DocumentRequest;
use OpenEMR\Modules\ClinicalCopilot\Documents\DocumentStatus;
use OpenEMR\Modules\ClinicalCopilot\Documents\DocumentStore;
use OpenEMR\Modules\ClinicalCopilot\Documents\Handoff;
use OpenEMR\Modules\ClinicalCopilot\Documents\SidecarClient;
use OpenEMR\Modules\ClinicalCopilot\Documents\SidecarException;
use OpenEMR\Modules\ClinicalCopilot\Documents\UploadRejected;
use OpenEMR\Modules\ClinicalCopilot\InvalidRequest;
use OpenEMR\Modules\ClinicalCopilot\Ops\CorrelatedLogger;
use OpenEMR\Modules\ClinicalCopilot\Ops\LangfuseTracer;
use OpenEMR\Modules\ClinicalCopilot\Ops\NullTracer;
use OpenEMR\Modules\ClinicalCopilot\Ops\RequestTrace;
use OpenEMR\Modules\ClinicalCopilot\Ops\StepRecorder;
use OpenEMR\Modules\ClinicalCopilot\Ops\Tracer;
use OpenEMR\Modules\ClinicalCopilot\PatientId;
use OpenEMR\Modules\ClinicalCopilot\Pricing;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

final class DocumentController
{
    private readonly string $correlationId;
    private readonly CorrelatedLogger $logger;
    private readonly Request $request;
    private readonly Config $config;
    private readonly Tracer $tracer;
    private readonly StepRecorder $steps;
    private readonly DocumentStore $store;
    private readonly DocumentIngestService $ingest;
    private readonly SidecarClient $sidecar;

    public function __construct(?LoggerInterface $logger = null, ?Request $request = null, ?Config $config = null, ?Tracer $tracer = null, ?DocumentStore $store = null, ?DocumentIngestService $ingest = null, ?SidecarClient $sidecar = null)
    {
        $this->correlationId = CorrelationId::generate();
        $this->logger = new CorrelatedLogger($logger ?? ServiceContainer::getLogger(), $this->correlationId);
        $this->request = $request ?? HttpRestRequest::createFromGlobals();
        $this->config = $config ?? Config::fromEnvironment();
        $this->steps = new StepRecorder();
        $this->tracer = $tracer ?? ($this->config->hasLangfuse()
            ? new LangfuseTracer(new Client(), $this->config->langfuseHost, $this->config->langfusePublicKey, $this->config->langfuseSecretKey)
            : new NullTracer());
        $this->store = $store ?? new DocumentStore();
        $this->ingest = $ingest ?? new DocumentIngestService();
        $this->sidecar = $sidecar ?? SidecarClient::fromConfig($this->config);
    }

    public function handleRequest(): void
    {
        $started = hrtime(true);
        $startedAtMs = (int) round(microtime(true) * 1000);
        try {
            $this->handle($started, $startedAtMs);
        } catch (\Throwable $e) {
            $this->logger->error('copilot document request failed', ['exception_class' => $e::class, 'steps' => $this->steps->all()]);
            $this->tracer->record(new RequestTrace($this->correlationId, 'copilot.documents.' . $this->request->request->getString('action'), '', $startedAtMs, (int) round((hrtime(true) - $started) / 1e6), ['http_status' => 500], null, 0, 0, 0, StepRecorder::describe($e), $this->steps->all()));
            $this->respond(['error' => 'The Co-Pilot hit an internal error', 'correlation_id' => $this->correlationId], 500);
            throw $e;
        }
    }

    private function handle(int|float $started, int $startedAtMs): void
    {
        $session = SessionWrapperFactory::getInstance()->getActiveSession();
        $user = $session->get('authUser');
        $user = is_string($user) ? $user : '';
        $userId = $session->get('authUserID');
        $userId = is_numeric($userId) ? (int) $userId : 0;

        $file = $this->request->files->get('file');
        try {
            $req = DocumentRequest::fromBag($this->request->request, $file instanceof UploadedFile ? $file : null);
        } catch (InvalidRequest $e) {
            $this->respond(['error' => $e->getMessage(), 'correlation_id' => $this->correlationId], $e->httpStatus);
            return;
        }
        if (!CsrfUtils::verifyCsrfToken($req->csrfToken, session: $session)) {
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
        $action = $req->action->value;
        $this->logger->notice('copilot document request', ['action' => $action, 'pid' => $pid->value, 'user' => $user, 'document_id' => $req->documentId]);

        // Documents ACL: reading the list needs view; storing or deriving records needs write or addonly.
        $allowed = $req->action === DocumentAction::List
            ? AclMain::aclCheckCore('patients', 'docs', $user)
            : AclMain::aclCheckCore('patients', 'docs', $user, ['write', 'addonly']);
        if (!$allowed) {
            $this->audit($user, $session, $pid, "action=$action denied=acl");
            $this->respond(['error' => 'You are not authorized to manage documents for this chart', 'correlation_id' => $this->correlationId], 403);
            return;
        }

        $payload = match ($req->action) {
            DocumentAction::List => $this->list($pid),
            DocumentAction::Upload => $this->upload($req, $pid, $user, $userId),
            DocumentAction::Extract => $this->extract($req, $pid, $user, $startedAtMs, $started),
        };
        $status = is_int($payload['http_status'] ?? null) ? $payload['http_status'] : 200;
        unset($payload['http_status']);
        $this->audit($user, $session, $pid, "action=$action correlation_id=" . $this->correlationId . ' http_status=' . $status . (isset($payload['document_id']) ? ' document_id=' . json_encode($payload['document_id']) : ''));
        $this->respond($payload + ['correlation_id' => $this->correlationId], $status);
    }

    /** @return array<string, mixed> */
    private function list(PatientId $pid): array
    {
        $docs = [];
        foreach ($this->store->list($pid) as $d) {
            $docs[] = [
                'document_id' => $d['document_id'],
                'doc_type' => $d['doc_type']->value,
                'status' => $d['status']->value,
                'failure_reason' => $d['failure_reason'],
                'confidence' => $d['confidence'],
                'filename' => $d['filename'],
                'uploaded_at' => $d['created_at'],
            ];
        }
        return ['documents' => $docs];
    }

    /** @return array<string, mixed> */
    private function upload(DocumentRequest $req, PatientId $pid, string $user, int $userId): array
    {
        if ($req->file === null || $req->docType === null) {
            return ['error' => 'A PDF file is required', 'http_status' => 400];
        }
        $bytes = (string) file_get_contents($req->file->getPathname());
        try {
            $stored = $this->steps->measure(
                'store_document',
                fn() => $this->store->store($pid, $req->docType, $req->file->getClientOriginalName(), $bytes, $user, $userId),
                static fn(array $s) => ['existing' => $s['existing']],
            );
        } catch (UploadRejected $e) {
            return ['error' => match ($e->reason) {
                'too_large' => 'The file is larger than 20 MB',
                'not_a_pdf' => 'Only PDF files are accepted',
                default => 'The file was rejected',
            }, 'reason' => $e->reason, 'http_status' => 400];
        }
        return [
            'document_id' => $stored['document_id'],
            'doc_type' => $req->docType->value,
            'status' => $stored['status']->value,
            'existing' => $stored['existing'],
            'http_status' => $stored['existing'] ? 200 : 201,
        ];
    }

    /** @return array<string, mixed> */
    private function extract(DocumentRequest $req, PatientId $pid, string $user, int $startedAtMs, int|float $started): array
    {
        $doc = $this->store->find($pid, (int) $req->documentId);
        if ($doc === null) {
            return ['error' => 'Document not found', 'http_status' => 404];
        }
        if ($doc['status'] === DocumentStatus::Extracted) {
            return ['document_id' => $doc['document_id'], 'status' => 'extracted', 'confidence' => $doc['confidence'], 'already' => true];
        }
        if ($doc['status'] === DocumentStatus::Failed) {
            $this->store->markStored($doc['document_id']); // a retry sends the bytes again
        }
        $bytes = $this->store->bytes($doc['document_id']);
        $encounter = EncounterSessionUtil::getEncounter();
        try {
            $run = $this->steps->measure(
                'sidecar_extract',
                fn() => $this->sidecar->extract($this->correlationId, hash('sha256', $doc['hash']), [['document_id' => $doc['document_id'], 'doc_type' => $doc['doc_type'], 'sha3_512' => $doc['hash'], 'bytes' => $bytes]]),
                static fn($r) => ['handoffs' => count($r->handoffs), 'calls' => $r->chatTokens()['calls']],
            );
        } catch (SidecarException $e) {
            $this->logger->warning('copilot sidecar failed', ['code' => $e->errorCode, 'document_id' => $doc['document_id'], 'steps' => $this->steps->all()]);
            $this->tracer->record(new RequestTrace($this->correlationId, 'copilot.documents.extract', $user, $startedAtMs, (int) round((hrtime(true) - $started) / 1e6), ['http_status' => 502, 'sidecar_error' => $e->errorCode, 'document_id' => $doc['document_id']], null, 0, 0, 0, 'sidecar ' . $e->errorCode, $this->steps->all()));
            return ['error' => 'The document service is unavailable; the file is stored and can be retried', 'reason' => $e->errorCode, 'document_id' => $doc['document_id'], 'status' => 'stored', 'http_status' => 502];
        }
        $extraction = null;
        foreach ($run->extractions as $x) {
            if ($x->documentId === $doc['document_id']) {
                $extraction = $x;
            }
        }
        if ($extraction === null) {
            throw new SidecarException('schema_mismatch');
        }
        $persisted = $this->steps->measure('persist', fn() => $this->ingest->persist($pid, $extraction, $this->correlationId), static fn(array $p) => $p);
        $tokens = $run->chatTokens();
        $llmMs = array_sum(array_map(static fn(Handoff $h): int => $h->from === 'intake_extractor' ? $h->ms : 0, $run->handoffs));
        $cost = Pricing::fromConfig($this->config)->costUsd($this->config->openAiModel, $tokens['prompt'], $tokens['completion']);
        $this->logger->notice('copilot document extracted', [
            'document_id' => $doc['document_id'],
            'doc_type' => $doc['doc_type']->value,
            'status' => $persisted['status']->value,
            'failure_reason' => $extraction->failureReason,
            'confidence' => $extraction->confidence,
            'results_persisted' => $persisted['results_persisted'],
            'unverified' => $persisted['unverified'],
            'unextracted' => $persisted['unextracted'],
            'model_calls' => $tokens['calls'],
            'prompt_tokens' => $tokens['prompt'],
            'completion_tokens' => $tokens['completion'],
            'cost_usd' => $cost,
            'encounter' => $encounter,
            'steps' => $this->steps->all(),
        ]);
        $this->tracer->record(new RequestTrace(
            $this->correlationId,
            'copilot.documents.extract',
            $user,
            $startedAtMs,
            (int) round((hrtime(true) - $started) / 1e6),
            [
                'http_status' => 200,
                'document_id' => $doc['document_id'],
                'doc_type' => $doc['doc_type']->value,
                'status' => $persisted['status']->value,
                'failure_reason' => $extraction->failureReason,
                'confidence' => $extraction->confidence,
                'results_persisted' => $persisted['results_persisted'],
                'unverified' => $persisted['unverified'],
                'unextracted' => $persisted['unextracted'],
                'handoffs' => array_map(static fn(Handoff $h): array => $h->toArray(), $run->handoffs),
                'model_calls' => $tokens['calls'],
            ],
            $this->config->openAiModel,
            $tokens['prompt'],
            $tokens['completion'],
            $llmMs,
            $extraction->failureReason,
            $this->steps->all(),
            $cost,
        ));
        return [
            'document_id' => $doc['document_id'],
            'doc_type' => $doc['doc_type']->value,
            'status' => $persisted['status']->value,
            'failure_reason' => $extraction->failureReason,
            'confidence' => $extraction->confidence,
            'results_persisted' => $persisted['results_persisted'],
            'unverified' => $persisted['unverified'],
            'unextracted' => $persisted['unextracted'],
            'handoffs' => array_map(static fn(Handoff $h): array => $h->toArray(), $run->handoffs),
        ];
    }

    private function audit(string $user, SessionInterface $session, PatientId $pid, string $comment): void
    {
        $provider = $session->get('authProvider');
        EventAuditLogger::getInstance()->newEvent('clinical-copilot', $user, is_string($provider) ? $provider : '', 1, $comment, $pid->value);
    }

    /** @param array<string, mixed> $payload */
    private function respond(array $payload, int $status): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        header('X-Correlation-Id: ' . $this->correlationId);
        echo json_encode($payload, JSON_THROW_ON_ERROR);
    }
}
