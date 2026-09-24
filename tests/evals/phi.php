<?php

/**
 * PHI-in-logs runner for the eval harness (week 2): drives the real
 * DocumentController (upload + extract) and ChatController (ask) in-process
 * with a capturing logger and a capturing tracer, and returns every log
 * record and trace payload so run.php can scan them. Needs the full
 * OpenEMR runtime, which run.php boots whenever a phi_logs case will run.
 * The upload is removed afterwards so the seed data stays as seeded.
 * run.php pairs each case with the sidecar's own log lines for the same
 * fixture (POST /eval/phi) and also requires every one of those lines to
 * carry the request's correlation id.
 *
 * Live cases call the model. Deterministic cases (no "live": true) run in
 * the pre-push gate without an API key: the controller's sidecar call goes
 * to the test-only /eval/run-recorded with the case's recorded model reply,
 * and the case gets a throwaway patient, so it also runs on a fresh
 * clone's empty database.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Evals;

use DateTimeImmutable;
use Document;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Session\PatientSessionUtil;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Modules\ClinicalCopilot\BriefingPipelineFactory;
use OpenEMR\Modules\ClinicalCopilot\Config;
use OpenEMR\Modules\ClinicalCopilot\Controller\ChatController;
use OpenEMR\Modules\ClinicalCopilot\Controller\DocumentController;
use OpenEMR\Modules\ClinicalCopilot\Documents\SidecarClient;
use OpenEMR\Modules\ClinicalCopilot\Ops\RequestTrace;
use OpenEMR\Modules\ClinicalCopilot\Ops\Tracer;
use OpenEMR\Modules\ClinicalCopilot\Row;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

require_once __DIR__ . '/lib.php';

/** Keeps every trace payload the controllers record, as JSON, for the PHI scan. */
final class CapturingTracer implements Tracer
{
    /** @var list<string> */
    public array $payloads = [];

    public function record(RequestTrace $trace): void
    {
        $this->payloads[] = json_encode($trace, JSON_THROW_ON_ERROR);
    }
}

/**
 * Recorded model and sidecar replies for the deterministic brief/ask cases
 * (eng review D1/D2 of docs/designs/golden-set-kill-matrix.md).
 *
 * ChatController's two outbound calls, the OpenAI chat completion and the
 * sidecar's POST /run, are answered from a queue of recorded HTTP bodies by
 * Guzzle's MockHandler, so the real controller runs end to end with no
 * network at all. The Config carries a placeholder key only so the
 * controller's "is AI configured" check passes; it is built here together
 * with the replaying clients and never reaches a real HTTP call.
 *
 * left() counts replies nobody asked for: a case expects 0, which proves the
 * brief and ask actually reached both the model call and the sidecar.
 */
final class ChatReplay
{
    private function __construct(
        public readonly Config $config,
        public readonly BriefingPipelineFactory $pipelines,
        public readonly SidecarClient $sidecar,
        private readonly MockHandler $openAi,
        private readonly MockHandler $sidecarReplies,
    ) {
    }

    /** Reads fixtures/chat/<name>: {"openai": [chat completion bodies], "sidecar": [run.response bodies]}, each in call order. */
    public static function fromFile(string $path): self
    {
        $recorded = jsonFile($path);
        $queue = static fn(string $key): MockHandler => new MockHandler(array_map(
            static fn(mixed $body): Response => new Response(200, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR)),
            lst($recorded, $key),
        ));
        $openAi = $queue('openai');
        $sidecarReplies = $queue('sidecar');
        $config = new Config('eval-replay-no-network', 'gpt-4o-mini', 'https://cloud.langfuse.com', '', '', sidecarUrl: 'http://127.0.0.1:9');
        return new self(
            $config,
            new BriefingPipelineFactory(new Client(['handler' => HandlerStack::create($openAi)])),
            new SidecarClient(new Client(['handler' => HandlerStack::create($sidecarReplies)]), $config),
            $openAi,
            $sidecarReplies,
        );
    }

    public function left(): int
    {
        return $this->openAi->count() + $this->sidecarReplies->count();
    }
}

/**
 * Plays one case through the real controllers as a logged-in user would:
 * upload, extract, list, and optionally brief + ask. Every log record and
 * trace the controllers emit is captured instead of written, and returned
 * together with the response bodies, so the caller can search them.
 *
 * @param array<string, mixed> $case  keys: fixture, doc_type, question, pid (optional), soap_plan,
 *                                    seed_problem (a problem-list title), chat_replay (fixtures/chat/ file)
 * @return array{logs: list<string>, traces: list<string>, log_keys: list<string>, dropped_fields: list<string>, status: ?string, answer_type: ?string, document_id: ?int, bodies: array<string, mixed>, replay_left: ?int, guideline_status: ?string}
 */
function runPhiCase(array $case): array
{
    // Stand in for a browser session: an admin user, a selected patient (the newest seed
    // patient unless the case names one) and a CSRF token the controllers will accept.
    $session = SessionWrapperFactory::getInstance()->getActiveSession();
    $session->set('authUser', 'admin');
    $session->set('authUserID', 1);
    $session->set('authProvider', 'Default');
    // A deterministic case runs in the pre-push gate on any database, a fresh clone's empty
    // one included, so it gets a throwaway patient one above the highest pid (removed below).
    $recorded = ($case['live'] ?? false) !== true;
    $throwawayPid = null;
    if ($recorded) {
        $throwawayPid = intOf(QueryUtils::fetchSingleValue("SELECT MAX(pid) AS m FROM patient_data", 'm')) + 1;
        QueryUtils::sqlInsert("INSERT INTO patient_data (pid, fname, lname, DOB, sex) VALUES (?, 'Eval', 'PhiLogs', '1980-05-05', 'Female')", [$throwawayPid]);
    }
    $newest = QueryUtils::querySingleRow("SELECT pid FROM patient_data ORDER BY pid DESC LIMIT 1");
    $pid = $throwawayPid ?? int($case, 'pid', is_array($newest) ? Row::int($newest, 'pid') : 0);
    PatientSessionUtil::setPid($pid);
    // Brief/ask cases replay recorded model and sidecar replies when they run in the gate.
    $replay = $recorded && is_string($case['chat_replay'] ?? null) ? ChatReplay::fromFile(__DIR__ . '/fixtures/chat/' . $case['chat_replay']) : null;
    // A problem-list entry fires a guideline trigger, so the brief reaches the sidecar.
    $seededProblem = null;
    if (is_string($case['seed_problem'] ?? null) && $case['seed_problem'] !== '') {
        $seededProblem = (int) QueryUtils::sqlInsert("INSERT INTO lists (pid, type, title, begdate, activity, date) VALUES (?, 'medical_problem', ?, '2020-01-01', 1, NOW())", [$pid, $case['seed_problem']]);
    }
    CsrfUtils::setupCsrfKey($session);
    $csrf = CsrfUtils::collectCsrfToken($session);

    // Monolog's TestHandler keeps records in memory; the tracer above does the same for traces.
    $handler = new TestHandler();
    $logger = new Logger('phi', [$handler]);
    $tracer = new CapturingTracer();

    $fixture = __DIR__ . '/fixtures/docs/' . str($case, 'fixture');
    $tmp = tempnam(sys_get_temp_dir(), 'phi-') ?: throw new RuntimeException('tempnam');
    $hasFixture = is_string($case['fixture'] ?? null) && $case['fixture'] !== '';
    if ($hasFixture) {
        copy($fixture, $tmp);
    }
    // Deterministic cases send the controller's sidecar call to the recorded model reply.
    $sidecar = $recorded && is_string($case['model_output'] ?? null) ? recordedSidecar(jsonFile(__DIR__ . '/fixtures/docs/' . $case['model_output'])) : null;
    $documentId = null;
    $status = null;
    $answerType = null;
    $bodies = [];
    // A case may seed a SOAP plan on a fresh encounter dated yesterday, so it is the
    // prior visit's plan when the chart is briefed; identifiers in it must stay out of logs.
    $seededEncounter = null;
    $seededSoap = null;
    $hash = '';
    $guidelineStatus = null;
    if (is_string($case['soap_plan'] ?? null) && $case['soap_plan'] !== '') {
        $yesterday = (new DateTimeImmutable('yesterday'))->format('Y-m-d 10:00:00');
        $encounterNumber = 900000 + $pid;
        $seededEncounter = (int) QueryUtils::sqlInsert("INSERT INTO form_encounter (date, reason, pid, encounter, provider_id, facility_id, sensitivity) VALUES (?, 'Follow-up', ?, ?, 1, 3, '')", [$yesterday, $pid, $encounterNumber]);
        QueryUtils::sqlInsert("INSERT INTO forms (date, encounter, form_name, form_id, pid, user, groupname, authorized, deleted, formdir) VALUES (?, ?, 'New Patient Encounter', ?, ?, 'admin', 'Default', 1, 0, 'newpatient')", [$yesterday, $encounterNumber, $seededEncounter, $pid]);
        $seededSoap = (int) QueryUtils::sqlInsert("INSERT INTO form_soap (date, pid, user, groupname, authorized, activity, subjective, objective, assessment, plan) VALUES (?, ?, 'admin', 'Default', 1, 1, '', '', '', ?)", [$yesterday, $pid, $case['soap_plan']]);
        QueryUtils::sqlInsert("INSERT INTO forms (date, encounter, form_name, form_id, pid, user, groupname, authorized, deleted, formdir) VALUES (?, ?, 'SOAP', ?, ?, 'admin', 'Default', 1, 0, 'soap')", [$yesterday, $encounterNumber, $seededSoap, $pid]);
    }
    // The controllers set HTTP headers and a status code; in this CLI run the harness has
    // already printed progress lines, so PHP warns that headers were already sent. Those
    // warnings (and only those) are silenced while the controllers run; any other
    // warning still goes to the default handler.
    set_error_handler(static fn(int $no, string $msg): bool => $no === E_WARNING && (str_contains($msg, 'headers already sent') || str_contains($msg, 'Cannot set response code')));
    try {
        // Upload through the real controller. The controller echoes its JSON reply, so
        // output buffering (ob_start / ob_get_clean) captures it as a string instead.
        if ($hasFixture) {
            $req = Request::create('/documents.php', 'POST', ['csrf_token_form' => $csrf, 'action' => 'upload', 'doc_type' => str($case, 'doc_type')], [], ['file' => new UploadedFile($tmp, basename($fixture), 'application/pdf', null, true)]);
            ob_start();
            (new DocumentController($logger, $req, null, $tracer, null, null, $sidecar))->handleRequest();
            $body = json_decode((string) ob_get_clean(), true);
            $bodies['upload'] = $body;
            $documentId = is_array($body) && is_int($body['document_id'] ?? null) ? $body['document_id'] : null;
        }
        if ($documentId !== null) {
            $req = Request::create('/documents.php', 'POST', ['csrf_token_form' => $csrf, 'action' => 'extract', 'document_id' => (string) $documentId]);
            ob_start();
            (new DocumentController($logger, $req, null, $tracer, null, null, $sidecar))->handleRequest();
            $body = json_decode((string) ob_get_clean(), true);
            $bodies['extract'] = $body;
            $status = is_array($body) && is_string($body['status'] ?? null) ? $body['status'] : null;
            ob_start();
            (new DocumentController($logger, Request::create('/documents.php', 'POST', ['csrf_token_form' => $csrf, 'action' => 'list']), null, $tracer, null, null, $sidecar))->handleRequest();
            $bodies['list'] = json_decode((string) ob_get_clean(), true);
        }
        if (is_string($case['question'] ?? null) || $seededSoap !== null || $replay !== null) {
            // A cached guideline section would skip the sidecar call the case exists to exercise.
            QueryUtils::sqlStatementThrowException("DELETE FROM copilot_briefing_cache WHERE pid = ?", [$pid]);
            // Brief first (the ask needs the facts hash), then ask through the real controller.
            ob_start();
            (new ChatController($logger, Request::create('/chat.php', 'POST', ['csrf_token_form' => $csrf, 'action' => 'brief']), $replay?->config, $tracer, null, $replay?->pipelines, $replay?->sidecar))->handleRequest();
            $brief = json_decode((string) ob_get_clean(), true);
            $hash = is_array($brief) && is_string($brief['facts_hash'] ?? null) ? $brief['facts_hash'] : '';
            $briefStatus = map(mapOf($brief), 'guidelines')['status'] ?? null;
            $guidelineStatus = is_string($briefStatus) ? $briefStatus : null;
            $bodies['brief'] = $brief;
        }
        if (is_string($case['question'] ?? null)) {
            ob_start();
            (new ChatController($logger, Request::create('/chat.php', 'POST', ['csrf_token_form' => $csrf, 'action' => 'ask', 'question' => $case['question'], 'facts_hash' => $hash, 'transcript' => '[]']), $replay?->config, $tracer, null, $replay?->pipelines, $replay?->sidecar))->handleRequest();
            $ans = json_decode((string) ob_get_clean(), true);
            $type = map(mapOf($ans), 'answer')['type'] ?? null;
            $answerType = is_string($type) ? $type : null;
        }
    } finally {
        restore_error_handler();
        @unlink($tmp);
        if ($documentId !== null) {
            removeDocument($documentId);
        }
        if ($seededProblem !== null) {
            QueryUtils::sqlStatementThrowException("DELETE FROM lists WHERE id = ?", [$seededProblem]);
        }
        if ($seededSoap !== null) {
            QueryUtils::sqlStatementThrowException("DELETE FROM forms WHERE formdir = 'soap' AND form_id = ?", [$seededSoap]);
            QueryUtils::sqlStatementThrowException("DELETE FROM form_soap WHERE id = ?", [$seededSoap]);
        }
        if ($seededEncounter !== null) {
            QueryUtils::sqlStatementThrowException("DELETE FROM forms WHERE formdir = 'newpatient' AND form_id = ?", [$seededEncounter]);
            QueryUtils::sqlStatementThrowException("DELETE FROM form_encounter WHERE id = ?", [$seededEncounter]);
        }
        QueryUtils::sqlStatementThrowException("DELETE FROM copilot_briefing_cache WHERE pid = ?", [$pid]);
        if ($throwawayPid !== null) {
            QueryUtils::sqlStatementThrowException("DELETE FROM patient_data WHERE pid = ?", [$throwawayPid]);
        }
    }
    // Render each log record as "message {context}" for the text scan, and collect the set of
    // context keys for the allowlist check in run.php.
    $logs = [];
    $keys = [];
    $dropped = [];
    foreach ($handler->getRecords() as $r) {
        $logs[] = $r->message . ' ' . json_encode($r->context, JSON_THROW_ON_ERROR);
        foreach (array_keys($r->context) as $k) {
            $keys[(string) $k] = true;
        }
        // Keys the runtime allowlist dropped from this line (CorrelatedLogger / LogFields).
        foreach (is_array($r->context['dropped_fields'] ?? null) ? $r->context['dropped_fields'] : [] as $k) {
            if (is_string($k)) {
                $dropped[$k] = true;
            }
        }
    }
    return ['logs' => $logs, 'traces' => $tracer->payloads, 'log_keys' => array_keys($keys), 'dropped_fields' => array_keys($dropped), 'status' => $status, 'answer_type' => $answerType, 'document_id' => $documentId, 'bodies' => $bodies, 'replay_left' => $replay?->left(), 'guideline_status' => $guidelineStatus];
}

/**
 * A SidecarClient for the deterministic PHI cases. The controller's POST /run
 * is sent to the sidecar's test-only /eval/run-recorded with the case's
 * recorded model reply attached, so extraction runs without a model call
 * while everything the controller logs, traces and persists is the real path.
 *
 * @param array<string, mixed> $proposal
 */
function recordedSidecar(array $proposal): SidecarClient
{
    $stack = HandlerStack::create();
    $stack->push(Middleware::mapRequest(static function (RequestInterface $request) use ($proposal): RequestInterface {
        $run = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        return $request
            ->withUri($request->getUri()->withPath('/eval/run-recorded'))
            ->withoutHeader('Content-Length')
            ->withBody(Utils::streamFor(json_encode(['run' => $run, 'proposal' => $proposal], JSON_THROW_ON_ERROR)));
    }));
    return new SidecarClient(new Client(['handler' => $stack, 'timeout' => 60.0, 'connect_timeout' => 5.0]), Config::fromEnvironment());
}

/**
 * Deletes a document and everything ingestion derived from it, in the
 * order the foreign relationships require: lab results, report, order
 * code and order (found through procedure_result.document_id), then the
 * module's three tables, the file on disk, and OpenEMR's documents row.
 * Shared with run.php's facts mode and the DB-backed PHPUnit tests.
 */
function removeDocument(int $id): void
{
    foreach (QueryUtils::fetchRecords("SELECT DISTINCT po.procedure_order_id FROM procedure_order po JOIN procedure_report prp ON prp.procedure_order_id = po.procedure_order_id JOIN procedure_result pr ON pr.procedure_report_id = prp.procedure_report_id WHERE pr.document_id = ?", [$id]) as $o) {
        $oid = Row::int($o, 'procedure_order_id');
        QueryUtils::sqlStatementThrowException("DELETE pr FROM procedure_result pr JOIN procedure_report prp ON prp.procedure_report_id = pr.procedure_report_id WHERE prp.procedure_order_id = ?", [$oid]);
        QueryUtils::sqlStatementThrowException("DELETE FROM procedure_report WHERE procedure_order_id = ?", [$oid]);
        QueryUtils::sqlStatementThrowException("DELETE FROM procedure_order_code WHERE procedure_order_id = ?", [$oid]);
        QueryUtils::sqlStatementThrowException("DELETE FROM procedure_order WHERE procedure_order_id = ?", [$oid]);
    }
    QueryUtils::sqlStatementThrowException("DELETE FROM copilot_document_fact WHERE document_id = ?", [$id]);
    QueryUtils::sqlStatementThrowException("DELETE FROM copilot_intake WHERE document_id = ?", [$id]);
    QueryUtils::sqlStatementThrowException("DELETE FROM copilot_document WHERE document_id = ?", [$id]);
    $doc = new Document($id);
    $url = $doc->get_url_filepath();
    if (is_string($url) && $url !== '' && file_exists($url)) {
        @unlink($url);
    }
    QueryUtils::sqlStatementThrowException("DELETE FROM documents WHERE id = ?", [$id]);
}
