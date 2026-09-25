<?php

/**
 * DB-backed pins for the brief action.
 *
 * When the sidecar cannot be reached the facts still arrive, the guideline
 * section says "unavailable" (or "no_triggers" when nothing fired for the
 * seeded patient), and the HTTP status is 200. A 500 here would blank the
 * whole panel for a physician because one optional dependency was down.
 *
 * With a SidecarClient injected (the seam the eval harness uses to replay
 * recorded replies), the brief request goes to that client and its evidence
 * reaches the panel; with none, the controller builds its own from Config,
 * which is what the first test exercises.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Services\Modules\ClinicalCopilot;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Session\EncounterSessionUtil;
use OpenEMR\Common\Session\PatientSessionUtil;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Modules\ClinicalCopilot\Config;
use OpenEMR\Modules\ClinicalCopilot\Controller\ChatController;
use OpenEMR\Modules\ClinicalCopilot\CopilotAuditLog;
use OpenEMR\Modules\ClinicalCopilot\Documents\SidecarClient;
use OpenEMR\Modules\ClinicalCopilot\Ops\NullTracer;
use OpenEMR\Modules\ClinicalCopilot\Row;
use OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Support\ModuleAutoload;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\HttpFoundation\Request;

class ChatControllerBriefTest extends TestCase
{
    private int $pid = 0;

    protected function setUp(): void
    {
        ModuleAutoload::register();
        $row = QueryUtils::querySingleRow("SELECT pid FROM patient_data ORDER BY pid LIMIT 1");
        $this->pid = is_array($row) ? Row::int($row, 'pid') : 0;
        if ($this->pid <= 0) {
            self::markTestSkipped('needs a seeded patient');
        }
    }

    private ?int $problemId = null;

    protected function tearDown(): void
    {
        if ($this->problemId !== null) {
            QueryUtils::sqlStatementThrowException("DELETE FROM lists WHERE id = ?", [$this->problemId]);
        }
        if ($this->pid > 0) {
            QueryUtils::sqlStatementThrowException("DELETE FROM copilot_briefing_cache WHERE pid = ?", [$this->pid]);
        }
    }

    /** Signs in as admin on the seeded patient and returns a CSRF token for the request. */
    private function signIn(): string
    {
        $session = SessionWrapperFactory::getInstance()->getActiveSession();
        $session->set('authUser', 'admin');
        $session->set('authUserID', 1);
        $session->set('authProvider', 'Default');
        PatientSessionUtil::setPid($this->pid);
        CsrfUtils::setupCsrfKey($session);
        return CsrfUtils::collectCsrfToken($session);
    }

    /** An established diagnosis on the problem list fires the diabetes trigger whatever the visit history. */
    private function addDiabetesProblem(): void
    {
        $this->problemId = (int) QueryUtils::sqlInsert("INSERT INTO lists (pid, type, title, begdate, activity, date) VALUES (?, 'medical_problem', 'Type 2 diabetes mellitus (brief test)', '2020-01-01', 1, NOW())", [$this->pid]);
    }

    public function testBriefWithoutSidecarStillReturnsFacts(): void
    {
        $csrf = $this->signIn();
        // The diabetes trigger makes sure the sidecar path is exercised.
        $this->addDiabetesProblem();
        // No chat model, and a sidecar URL nothing listens on.
        $config = new Config('', 'gpt-4o-mini', 'https://cloud.langfuse.com', '', '', sidecarUrl: 'http://127.0.0.1:9');
        $handler = new TestHandler();
        $logger = new Logger('test', [$handler]);
        $request = Request::create('/chat.php', 'POST', ['csrf_token_form' => $csrf, 'action' => 'brief']);

        ob_start();
        (new ChatController($logger, $request, $config, new NullTracer()))->handleRequest();
        $body = json_decode((string) ob_get_clean(), true, 32, JSON_THROW_ON_ERROR);

        self::assertIsArray($body);
        self::assertArrayHasKey('facts', $body);
        self::assertArrayHasKey('guidelines', $body);
        $guidelines = $body['guidelines'];
        self::assertIsArray($guidelines);
        self::assertSame('unavailable', $guidelines['status'], 'an unreachable sidecar must degrade, not fail');
        self::assertSame([], $guidelines['cards']);
        self::assertTrue($handler->hasWarningThatContains('guideline evidence unavailable'), 'the degradation is logged');
        self::assertCount(1, array_filter($handler->getRecords(), static fn($r): bool => str_contains($r->message, 'guideline evidence unavailable')));
        $narration = $body['narration'];
        self::assertIsArray($narration);
        self::assertSame('AI summary unavailable: not configured on this server', $narration['status']);
    }

    public function testAnInjectedSidecarClientReceivesTheBriefRequest(): void
    {
        $csrf = $this->signIn();
        $this->addDiabetesProblem();
        // Recorded reply: evidence for the diabetes trigger. The client never opens a socket.
        $reply = [
            'correlation_id' => 'c0ffee00-brief-0001',
            'extractions' => [],
            'chunks' => [],
            'handoffs' => [
                ['from' => 'supervisor', 'to' => 'evidence_retriever', 'reason' => 'chart_triggers', 'state_keys_changed' => [], 'ms' => 0],
                ['from' => 'evidence_retriever', 'to' => 'supervisor', 'reason' => 'worker_finished', 'state_keys_changed' => ['evidence'], 'ms' => 1],
                ['from' => 'supervisor', 'to' => 'done', 'reason' => 'worker_finished', 'state_keys_changed' => [], 'ms' => 0],
            ],
            'usage' => [],
            'evidence' => [['trigger_id' => 'diabetes', 'chunks' => [['chunk_id' => 'bbbbbbbbbbbb', 'source_id' => 'ada-2025-standards', 'section' => 'Glycemic goals > A1C', 'quote' => 'An A1C goal of less than 7% is appropriate for many adults.', 'score' => 0.8]], 'applicable' => true, 'reason' => 'no restriction stated']],
        ];
        $mock = new MockHandler([new Response(200, ['Content-Type' => 'application/json'], json_encode($reply, JSON_THROW_ON_ERROR))]);
        $sent = [];
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::tap(static function (RequestInterface $request) use (&$sent): void {
            $sent[] = $request;
        }));
        $config = new Config('', 'gpt-4o-mini', 'https://cloud.langfuse.com', '', '', sidecarUrl: 'http://127.0.0.1:9');
        $sidecar = new SidecarClient(new Client(['handler' => $stack]), $config);
        $request = Request::create('/chat.php', 'POST', ['csrf_token_form' => $csrf, 'action' => 'brief']);

        ob_start();
        (new ChatController(new Logger('test', [new TestHandler()]), $request, $config, new NullTracer(), sidecar: $sidecar))->handleRequest();
        $body = json_decode((string) ob_get_clean(), true, 32, JSON_THROW_ON_ERROR);

        self::assertCount(1, $sent, 'the brief request goes to the injected client, once');
        self::assertCount(0, $mock, 'the recorded reply was consumed');
        $sentBody = json_decode((string) $sent[0]->getBody(), true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($sentBody);
        self::assertSame('brief', $sentBody['mode']);
        self::assertIsArray($body);
        $guidelines = $body['guidelines'];
        self::assertIsArray($guidelines);
        self::assertSame('ok', $guidelines['status'], 'the injected client\'s evidence reaches the panel');
    }

    /**
     * KEY_METRICS.md metric 7 counts chat use per encounter from these audit
     * rows, so every request's row must name the encounter the chart was open on.
     */
    public function testTheAuditRowNamesTheOpenEncounter(): void
    {
        $csrf = $this->signIn();
        $encounter = (string) random_int(900000000, 999999999);
        EncounterSessionUtil::setEncounter($encounter);
        $config = new Config('', 'gpt-4o-mini', 'https://cloud.langfuse.com', '', '', sidecarUrl: 'http://127.0.0.1:9');
        $request = Request::create('/chat.php', 'POST', ['csrf_token_form' => $csrf, 'action' => 'brief']);

        try {
            ob_start();
            (new ChatController(new Logger('test', [new TestHandler()]), $request, $config, new NullTracer()))->handleRequest();
            ob_end_clean();
        } finally {
            EncounterSessionUtil::setEncounter('0');
        }

        $today = new \DateTimeImmutable('today');
        $rows = (new CopilotAuditLog(static fn(string $c): string => $c))->successful($today, $today->modify('+1 day'));
        $mine = array_filter($rows, static fn(array $r): bool => str_contains($r['comment'], " encounter_id=$encounter "));
        self::assertCount(1, $mine, 'exactly one audit row names the open encounter');
        self::assertStringStartsWith('action=brief ', array_values($mine)[0]['comment']);
    }
}
