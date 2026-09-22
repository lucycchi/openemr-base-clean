<?php

/**
 * PHI-in-logs runner for the eval harness (week 2): drives the real
 * DocumentController (upload + extract) and ChatController (ask) in-process
 * with a capturing logger and a capturing tracer, and returns every log
 * record and trace payload so run.php can scan them. Needs the full
 * OpenEMR runtime (run.php --live boots it). The upload is removed
 * afterwards so the seed data stays as seeded. run.php pairs each case with
 * the sidecar's own log lines for the same fixture (POST /eval/phi) and also
 * requires every one of those lines to carry the request's correlation id.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

use Monolog\Handler\TestHandler;
use Monolog\Logger;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Session\PatientSessionUtil;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Modules\ClinicalCopilot\Controller\ChatController;
use OpenEMR\Modules\ClinicalCopilot\Controller\DocumentController;
use OpenEMR\Modules\ClinicalCopilot\Ops\RequestTrace;
use OpenEMR\Modules\ClinicalCopilot\Ops\Tracer;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

/**
 * @param array<string, mixed> $case  keys: fixture, doc_type, question, pid (optional)
 * @return array{logs: list<string>, traces: list<string>, log_keys: list<string>, status: ?string, answer_type: ?string, document_id: ?int, bodies: array<string, mixed>}
 */
function runPhiCase(array $case): array
{
    $session = SessionWrapperFactory::getInstance()->getActiveSession();
    $session->set('authUser', 'admin');
    $session->set('authUserID', 1);
    $session->set('authProvider', 'Default');
    $pid = (int) ($case['pid'] ?? (QueryUtils::querySingleRow("SELECT pid FROM patient_data ORDER BY pid DESC LIMIT 1")['pid'] ?? 0));
    PatientSessionUtil::setPid($pid);
    CsrfUtils::setupCsrfKey($session);
    $csrf = CsrfUtils::collectCsrfToken($session);

    $handler = new TestHandler();
    $logger = new Logger('phi', [$handler]);
    $traces = [];
    $tracer = new class ($traces) implements Tracer {
        /** @param list<string> $sink */
        public function __construct(private array &$sink)
        {
        }

        public function record(RequestTrace $trace): void
        {
            $this->sink[] = json_encode($trace, JSON_THROW_ON_ERROR);
        }
    };

    $fixture = __DIR__ . '/fixtures/docs/' . (string) $case['fixture'];
    $tmp = tempnam(sys_get_temp_dir(), 'phi-') ?: throw new RuntimeException('tempnam');
    copy($fixture, $tmp);
    $documentId = null;
    $status = null;
    $answerType = null;
    $bodies = [];
    try {
        // Upload through the real controller.
        $req = Request::create('/documents.php', 'POST', ['csrf_token_form' => $csrf, 'action' => 'upload', 'doc_type' => (string) $case['doc_type']], [], ['file' => new UploadedFile($tmp, basename($fixture), 'application/pdf', null, true)]);
        ob_start();
        (new DocumentController($logger, $req, null, $tracer))->handleRequest();
        $body = json_decode((string) ob_get_clean(), true);
        $bodies['upload'] = $body;
        $documentId = is_array($body) && is_int($body['document_id'] ?? null) ? $body['document_id'] : null;
        if ($documentId !== null) {
            $req = Request::create('/documents.php', 'POST', ['csrf_token_form' => $csrf, 'action' => 'extract', 'document_id' => (string) $documentId]);
            ob_start();
            (new DocumentController($logger, $req, null, $tracer))->handleRequest();
            $body = json_decode((string) ob_get_clean(), true);
            $bodies['extract'] = $body;
            $status = is_array($body) && is_string($body['status'] ?? null) ? $body['status'] : null;
            ob_start();
            (new DocumentController($logger, Request::create('/documents.php', 'POST', ['csrf_token_form' => $csrf, 'action' => 'list']), null, $tracer))->handleRequest();
            $bodies['list'] = json_decode((string) ob_get_clean(), true);
        }
        if (is_string($case['question'] ?? null)) {
            // Brief first (the ask needs the facts hash), then ask through the real controller.
            ob_start();
            (new ChatController($logger, Request::create('/chat.php', 'POST', ['csrf_token_form' => $csrf, 'action' => 'brief']), null, $tracer))->handleRequest();
            $brief = json_decode((string) ob_get_clean(), true);
            $hash = is_array($brief) && is_string($brief['facts_hash'] ?? null) ? $brief['facts_hash'] : '';
            ob_start();
            (new ChatController($logger, Request::create('/chat.php', 'POST', ['csrf_token_form' => $csrf, 'action' => 'ask', 'question' => $case['question'], 'facts_hash' => $hash, 'transcript' => '[]']), null, $tracer))->handleRequest();
            $ans = json_decode((string) ob_get_clean(), true);
            $answerType = is_array($ans) && is_string($ans['answer']['type'] ?? null) ? $ans['answer']['type'] : null;
        }
    } finally {
        @unlink($tmp);
        if ($documentId !== null) {
            removeDocument($documentId);
        }
        QueryUtils::sqlStatementThrowException("DELETE FROM copilot_briefing_cache WHERE pid = ?", [$pid]);
    }
    $logs = [];
    $keys = [];
    foreach ($handler->getRecords() as $r) {
        $logs[] = $r->message . ' ' . json_encode($r->context, JSON_THROW_ON_ERROR);
        foreach (array_keys($r->context) as $k) {
            $keys[$k] = true;
        }
    }
    return ['logs' => $logs, 'traces' => $traces, 'log_keys' => array_keys($keys), 'status' => $status, 'answer_type' => $answerType, 'document_id' => $documentId, 'bodies' => $bodies];
}

function removeDocument(int $id): void
{
    foreach (QueryUtils::fetchRecords("SELECT DISTINCT po.procedure_order_id FROM procedure_order po JOIN procedure_report prp ON prp.procedure_order_id = po.procedure_order_id JOIN procedure_result pr ON pr.procedure_report_id = prp.procedure_report_id WHERE pr.document_id = ?", [$id]) as $o) {
        $oid = (int) $o['procedure_order_id'];
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
