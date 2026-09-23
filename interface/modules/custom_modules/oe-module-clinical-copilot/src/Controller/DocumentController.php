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
use OpenEMR\Modules\ClinicalCopilot\Documents\ExtractionRunner;
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
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * The HTTP side of document handling. The header above gives the order of
 * checks; this class also owns the three things every request must leave
 * behind: a log line, an event in OpenEMR's own audit log, and a trace
 * (Langfuse when configured, otherwise a no-op tracer), all carrying
 * the same correlation id as the response header.
 *
 * Errors follow one rule: the user sees a fixed sentence and the
 * correlation id; the detail goes to the log under that id.
 */
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
    private readonly ExtractionRunner $runner;

    /**
     * Every collaborator is optional: production passes nothing and gets the
     * real logger, the live request, environment config and a real sidecar
     * client; the eval harness (tests/evals/phi.php) passes a capturing
     * logger, a hand-built request and a capturing tracer so it can inspect
     * every log line and trace for leaked patient data.
     */
    public function __construct(?LoggerInterface $logger = null, ?Request $request = null, ?Config $config = null, ?Tracer $tracer = null, ?DocumentStore $store = null, ?DocumentIngestService $ingest = null, ?SidecarClient $sidecar = null)
    {
        // Minted here, once per request, before anything else can log.
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
        $this->runner = new ExtractionRunner($this->store, $this->sidecar, $this->ingest);
    }

    /**
     * The entry point documents.php calls. Wraps the real work so that an
     * unexpected error still produces a JSON 500 with the correlation id, a
     * log line and a trace, and is then rethrown for PHP's own error log.
     * Only the exception's class is logged, never its message, which could
     * contain a file path or SQL.
     */
    public function handleRequest(): void
    {
        // Two clocks: hrtime for an accurate duration, wall-clock ms for the trace's start time.
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

    /**
     * The checks, in the order the header lists them, then the action.
     * Each refusal returns early with its own status so a caller can tell
     * "bad request" from "not allowed" from "not logged in".
     */
    private function handle(int|float $started, int $startedAtMs): void
    {
        // Who is asking, from the server-side session (never from the request body).
        $session = SessionWrapperFactory::getInstance()->getActiveSession();
        $user = $session->get('authUser');
        $user = is_string($user) ? $user : '';
        $userId = $session->get('authUserID');
        $userId = is_numeric($userId) ? (int) $userId : 0;

        // 1. Parse. A multipart upload puts the PDF under "file" in the files bag, not the form
        //    fields; it is handed to the parser separately. Any parse failure is a 4xx.
        $file = $this->request->files->get('file');
        try {
            $req = DocumentRequest::fromBag($this->request->request, $file instanceof UploadedFile ? $file : null);
        } catch (InvalidRequest $e) {
            $this->respond(['error' => $e->getMessage(), 'correlation_id' => $this->correlationId], $e->httpStatus);
            return;
        }
        // 2. CSRF: the token in the form must match the one OpenEMR issued to this session,
        //    so a page on another site cannot make the browser upload on the user's behalf.
        if (!CsrfUtils::verifyCsrfToken($req->csrfToken, session: $session)) {
            $this->respond(['error' => 'CSRF verification failed', 'correlation_id' => $this->correlationId], 403);
            return;
        }
        // 3. Logged in, and 4. a chart is open. The patient id comes from the session only:
        //    the request cannot name a patient, so it cannot reach another chart.
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

        // 5. Documents ACL: reading the list needs view; storing or deriving records needs write or addonly.
        //    This is OpenEMR's own permission for the patient Documents area, so a user who cannot
        //    file a document by hand cannot file one through the Co-Pilot either.
        $allowed = $req->action === DocumentAction::List
            ? AclMain::aclCheckCore('patients', 'docs', $user)
            : AclMain::aclCheckCore('patients', 'docs', $user, ['write', 'addonly']);
        if (!$allowed) {
            // A refusal is audited too, so an access attempt is visible in OpenEMR's log viewer.
            $this->audit($user, $session, $pid, "action=$action denied=acl");
            $this->respond(['error' => 'You are not authorized to manage documents for this chart', 'correlation_id' => $this->correlationId], 403);
            return;
        }

        // 6. Act. Each handler returns the JSON body, with an optional http_status
        //    key that is lifted out before the body is sent.
        $payload = match ($req->action) {
            DocumentAction::List => $this->list($pid),
            DocumentAction::Upload => $this->upload($req, $pid, $user, $userId),
            DocumentAction::Extract => $this->extract($req, $pid, $user, $startedAtMs, $started),
        };
        $status = is_int($payload['http_status'] ?? null) ? $payload['http_status'] : 200;
        unset($payload['http_status']);
        // 7. Audit and respond. The audit comment carries only ids and codes, never file contents.
        $this->audit($user, $session, $pid, "action=$action correlation_id=" . $this->correlationId . ' http_status=' . $status . (isset($payload['document_id']) ? ' document_id=' . json_encode($payload['document_id']) : ''));
        $this->respond($payload + ['correlation_id' => $this->correlationId], $status);
    }

    /**
     * The patient's documents in the shape of documents.list.response: only
     * the fields the panel shows. The hash and the internal row id stay
     * server-side.
     *
     * @return array<string, mixed>
     */
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

    /**
     * Stores the uploaded PDF and answers with its document id. Nothing is
     * extracted yet: upload and extract are separate requests so the panel
     * can show "stored" at once and start the slower sidecar call second.
     *
     * Status 201 means a new document was created; 200 with existing=true
     * means this patient already had these exact bytes and nothing was
     * stored again.
     *
     * @return array<string, mixed>
     */
    private function upload(DocumentRequest $req, PatientId $pid, string $user, int $userId): array
    {
        // The parser guarantees both for an upload; this guard keeps the types honest.
        if ($req->file === null || $req->docType === null) {
            return ['error' => 'A PDF file is required', 'http_status' => 400];
        }
        // Read the temporary upload file PHP wrote; the store checks the size and the
        // PDF signature on these bytes, not on anything the browser declared.
        $bytes = (string) file_get_contents($req->file->getPathname());
        try {
            $stored = $this->steps->measure(
                'store_document',
                fn() => $this->store->store($pid, $req->docType, $req->file->getClientOriginalName(), $bytes, $user, $userId),
                static fn(array $s) => ['existing' => $s['existing']],
            );
        } catch (UploadRejected $e) {
            // The reason code becomes the user's message here, and travels as "reason" for the panel.
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

    /**
     * Runs the sidecar on one stored document and writes the result. The
     * document is looked up scoped to the session patient, so a document id
     * from another chart is a 404 before anything is read. A sidecar failure
     * is a 502 that leaves the file stored for a retry; the reason code is
     * returned so the panel can say why.
     *
     * @return array<string, mixed>
     */
    private function extract(DocumentRequest $req, PatientId $pid, string $user, int $startedAtMs, int|float $started): array
    {
        $doc = $this->store->find($pid, (int) $req->documentId);
        if ($doc === null) {
            return ['error' => 'Document not found', 'http_status' => 404];
        }
        // Already extracted: answer from the row, no sidecar call, no model cost.
        if ($doc['status'] === DocumentStatus::Extracted) {
            return ['document_id' => $doc['document_id'], 'status' => 'extracted', 'confidence' => $doc['confidence'], 'already' => true];
        }
        $encounter = EncounterSessionUtil::getEncounter();
        try {
            // measure() times the whole sidecar round trip plus the database writes as one step.
            $outcome = $this->steps->measure(
                'sidecar_extract_and_persist',
                fn() => $this->runner->run($pid, $doc, $this->correlationId),
                static fn(array $o) => ['handoffs' => count($o['run']->handoffs ?? []), 'calls' => $o['run']?->chatTokens()['calls'] ?? 0, 'status' => $o['persisted']['status']->value],
            );
        } catch (SidecarException $e) {
            // The sidecar was unreachable, timed out or refused: log the code, trace a 502,
            // and tell the user the file is safe. The code, not the message, is what travels.
            $this->logger->warning('copilot sidecar failed', ['code' => $e->errorCode, 'document_id' => $doc['document_id'], 'steps' => $this->steps->all()]);
            $this->tracer->record(new RequestTrace($this->correlationId, 'copilot.documents.extract', $user, $startedAtMs, (int) round((hrtime(true) - $started) / 1e6), ['http_status' => 502, 'sidecar_error' => $e->errorCode, 'document_id' => $doc['document_id']], null, 0, 0, 0, 'sidecar ' . $e->errorCode, $this->steps->all()));
            return ['error' => 'The document service is unavailable; the file is stored and can be retried', 'reason' => $e->errorCode, 'document_id' => $doc['document_id'], 'status' => 'stored', 'http_status' => 502];
        }
        $run = $outcome['run'];
        $extraction = $outcome['extraction'];
        $persisted = $outcome['persisted'];
        // The runner only returns nulls for an already-extracted document, which was handled above.
        if ($run === null || $extraction === null) {
            throw new SidecarException('schema_mismatch');
        }
        // llmMs: the milliseconds of every handoff leaving the extractor worker, summed;
        // the trace reports it as the model time.
        $tokens = $run->chatTokens();
        $llmMs = array_sum(array_map(static fn(Handoff $h): int => $h->from === 'intake_extractor' ? $h->ms : 0, $run->handoffs));
        // Every model call the sidecar made, priced: the trace gets one generation per call.
        $priced = Pricing::fromConfig($this->config)->priceUsage($run->usage, $this->config->openAiModel);
        $cost = Pricing::totalCost($priced);
        // The log line and the trace carry counts, codes and costs only; the eval harness's
        // phi_logs cases scan both for anything read from the document and refuse it.
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
            'sidecar_retries' => $extraction->retries,
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
                'llm_attempts' => $tokens['calls'],
                'sidecar_retries' => $extraction->retries,
                'llm_retried' => $extraction->retries > 0,
            ],
            // The sidecar's calls are traced one generation each (sidecarUsage), so no aggregate generation here.
            null,
            $tokens['prompt'],
            $tokens['completion'],
            $llmMs,
            $extraction->failureReason,
            $this->steps->all(),
            $cost,
            $priced,
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

    /**
     * One entry in OpenEMR's own audit log, tagged clinical-copilot and
     * tied to the patient, so document activity shows up alongside every
     * other chart access in the standard log viewer.
     */
    private function audit(string $user, SessionInterface $session, PatientId $pid, string $comment): void
    {
        $provider = $session->get('authProvider');
        EventAuditLogger::getInstance()->newEvent('clinical-copilot', $user, is_string($provider) ? $provider : '', 1, $comment, $pid->value);
    }

    /**
     * Sends the JSON reply. The correlation id goes in a header as well as
     * the body, so it is visible in browser tools even when the body is
     * discarded.
     *
     * @param array<string, mixed> $payload
     */
    private function respond(array $payload, int $status): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        header('X-Correlation-Id: ' . $this->correlationId);
        echo json_encode($payload, JSON_THROW_ON_ERROR);
    }
}
