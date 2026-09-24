<?php

/**
 * action=rate end to end against the database: the physician's thumbs up or
 * down on a briefing's AI summary (KEY_METRICS.md metric 6).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Lucy Chi <lucychi@berkeley.edu>
 * @copyright Copyright (c) 2026 Lucy Chi
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Services\Modules\ClinicalCopilot;

use Monolog\Handler\TestHandler;
use Monolog\Logger;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Session\PatientSessionUtil;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Modules\ClinicalCopilot\Config;
use OpenEMR\Modules\ClinicalCopilot\Contracts;
use OpenEMR\Modules\ClinicalCopilot\Controller\ChatController;
use OpenEMR\Modules\ClinicalCopilot\Ops\RequestTrace;
use OpenEMR\Modules\ClinicalCopilot\Ops\Score;
use OpenEMR\Modules\ClinicalCopilot\Ops\Tracer;
use OpenEMR\Modules\ClinicalCopilot\Row;
use OpenEMR\Tests\Isolated\Modules\ClinicalCopilot\Support\ModuleAutoload;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

class ChatControllerRateTest extends TestCase
{
    private const KEY = 'feedfacefeedfacefeedfacefeedfacefeedfacefeedfacefeedfacefeedface';
    private const OTHER_KEY = 'deadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeef';
    private const BRIEFING_CID = '0123456789abcdef0123456789abcdef';
    // A comment that names someone: it must reach the rating table and nothing else.
    private const COMMENT = 'Mr Zyxwvut also saw cardiology last week; summary missed it.';

    private int $pid = 0;
    private int $otherPid = 0;

    /** @var list<RequestTrace> */
    public array $traces = [];
    /** @var list<Score> */
    public array $scores = [];

    public static function setUpBeforeClass(): void
    {
        ModuleAutoload::register();
        // The dev database may predate module 0.1.4; apply the upgrade the way
        // docker/vps/deploy.sh does (idempotent CREATE TABLE IF NOT EXISTS).
        $sql = (string) file_get_contents(__DIR__ . '/../../../../../interface/modules/custom_modules/oe-module-clinical-copilot/sql/0_1_3-to-0_1_4_upgrade.sql');
        $lines = array_filter(explode("\n", $sql), static fn(string $l): bool => !str_starts_with(ltrim($l), '#') && !str_starts_with(ltrim($l), '--'));
        QueryUtils::sqlStatementThrowException(implode("\n", $lines));
    }

    protected function setUp(): void
    {
        $rows = QueryUtils::fetchRecords("SELECT pid FROM patient_data ORDER BY pid LIMIT 2");
        if (count($rows) < 2) {
            self::markTestSkipped('needs two seeded patients');
        }
        $this->pid = Row::int($rows[0], 'pid');
        $this->otherPid = Row::int($rows[1], 'pid');
        $this->cacheRow(self::KEY, $this->pid);
        $this->cacheRow(self::OTHER_KEY, $this->otherPid);
    }

    protected function tearDown(): void
    {
        QueryUtils::sqlStatementThrowException("DELETE FROM copilot_briefing_rating WHERE briefing_cache_key IN (?, ?)", [self::KEY, self::OTHER_KEY]);
        QueryUtils::sqlStatementThrowException("DELETE FROM copilot_briefing_cache WHERE cache_key IN (?, ?)", [self::KEY, self::OTHER_KEY]);
    }

    private function cacheRow(string $key, int $pid): void
    {
        QueryUtils::sqlStatementThrowException(
            "INSERT INTO copilot_briefing_cache (cache_key, pid, facts_hash, prompt_version, model, narration_json) VALUES (?, ?, ?, '2026-09-24.test', 'gpt-test', '{\"sentences\":[]}')
             ON DUPLICATE KEY UPDATE pid = VALUES(pid)",
            [$key, $pid, str_repeat('0', 64)]
        );
    }

    private function signIn(string $user = 'admin', int $userId = 1): string
    {
        $session = SessionWrapperFactory::getInstance()->getActiveSession();
        $session->set('authUser', $user);
        $session->set('authUserID', $userId);
        $session->set('authProvider', 'Default');
        PatientSessionUtil::setPid($this->pid);
        CsrfUtils::setupCsrfKey($session);
        return CsrfUtils::collectCsrfToken($session);
    }

    /**
     * @param array<string, string> $fields
     * @return array{int, array<mixed>}
     */
    private function post(array $fields, TestHandler $logs): array
    {
        $test = $this;
        $tracer = new class ($test) implements Tracer {
            public function __construct(private readonly ChatControllerRateTest $test)
            {
            }

            public function record(RequestTrace $trace): void
            {
                $this->test->traces[] = $trace;
            }

            public function score(Score $score): void
            {
                $this->test->scores[] = $score;
            }
        };
        $config = new Config('', 'gpt-4o-mini', 'https://cloud.langfuse.com', '', '');
        http_response_code(200);
        ob_start();
        (new ChatController(new Logger('test', [$logs]), Request::create('/chat.php', 'POST', $fields), $config, $tracer))->handleRequest();
        $body = json_decode((string) ob_get_clean(), true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        $status = http_response_code();
        return [is_int($status) ? $status : 0, $body];
    }

    /** @return list<array<mixed>> */
    private function ratings(): array
    {
        return QueryUtils::fetchRecords("SELECT pid, user_id, rating, comment, prompt_version, model, correlation_id FROM copilot_briefing_rating WHERE briefing_cache_key = ?", [self::KEY]);
    }

    private function lastAuditComment(): string
    {
        $row = QueryUtils::querySingleRow("SELECT comments FROM log WHERE event = 'clinical-copilot' ORDER BY id DESC LIMIT 1");
        self::assertIsArray($row);
        return (string) base64_decode(Row::str($row, 'comments'), true);
    }

    public function testARatingIsStoredOnceAndASecondRatingReplacesIt(): void
    {
        $csrf = $this->signIn();
        $logs = new TestHandler();
        [$status, $body] = $this->post(['csrf_token_form' => $csrf, 'action' => 'rate', 'rating' => 'down', 'comment' => self::COMMENT, 'cache_key' => self::KEY, 'briefing_correlation_id' => self::BRIEFING_CID], $logs);

        self::assertSame(200, $status);
        self::assertSame([], Contracts::violations('chat.rate.response', $body));
        self::assertSame('down', $body['rating']);
        self::assertTrue($body['comment_saved']);
        $rows = $this->ratings();
        self::assertCount(1, $rows);
        self::assertSame('down', $rows[0]['rating']);
        self::assertSame(self::COMMENT, $rows[0]['comment']);
        // Version and model come from the cache row, not from the request.
        self::assertSame('2026-09-24.test', $rows[0]['prompt_version']);
        self::assertSame('gpt-test', $rows[0]['model']);
        self::assertSame(self::BRIEFING_CID, $rows[0]['correlation_id']);

        // The score lands on the briefing's trace, carrying no comment text.
        self::assertCount(1, $this->scores);
        self::assertSame(self::BRIEFING_CID, $this->scores[0]->traceId);
        self::assertSame('physician_rating', $this->scores[0]->name);
        self::assertFalse($this->scores[0]->value);
        self::assertStringNotContainsString('Zyxwvut', (string) $this->scores[0]->note);

        // The comment reaches no log line, no audit row, no trace.
        self::assertTrue($logs->hasNoticeThatContains('copilot rating'));
        foreach ($logs->getRecords() as $record) {
            self::assertStringNotContainsString('Zyxwvut', json_encode([$record->message, $record->context], JSON_THROW_ON_ERROR));
        }
        $audit = $this->lastAuditComment();
        self::assertStringStartsWith('action=rate ', $audit);
        self::assertStringContainsString('rating=down comment_chars=' . mb_strlen(self::COMMENT), $audit);
        self::assertStringNotContainsString('Zyxwvut', $audit);
        self::assertStringNotContainsString('Zyxwvut', json_encode($this->traces, JSON_THROW_ON_ERROR));

        // A change of mind with no comment: still one row, now up, comment cleared.
        [$status] = $this->post(['csrf_token_form' => $csrf, 'action' => 'rate', 'rating' => 'up', 'cache_key' => self::KEY, 'briefing_correlation_id' => self::BRIEFING_CID], new TestHandler());
        self::assertSame(200, $status);
        $rows = $this->ratings();
        self::assertCount(1, $rows, 'one physician, one vote per summary');
        self::assertSame('up', $rows[0]['rating']);
        self::assertNull($rows[0]['comment']);
        self::assertSame($this->scores[0]->id, $this->scores[1]->id, 'the Langfuse score is replaced, not added');
    }

    public function testASummaryCachedForAnotherPatientCannotBeRatedFromThisChart(): void
    {
        $csrf = $this->signIn();
        [$status, $body] = $this->post(['csrf_token_form' => $csrf, 'action' => 'rate', 'rating' => 'up', 'cache_key' => self::OTHER_KEY, 'briefing_correlation_id' => self::BRIEFING_CID], new TestHandler());

        self::assertSame(409, $status);
        self::assertArrayHasKey('error', $body);
        self::assertSame([], QueryUtils::fetchRecords("SELECT id FROM copilot_briefing_rating WHERE briefing_cache_key = ?", [self::OTHER_KEY]));
        self::assertSame([], $this->scores);
    }

    public function testAUserWhoCannotSeeTheChartCannotRateIt(): void
    {
        $row = QueryUtils::querySingleRow("SELECT id FROM users WHERE username = 'receptionist'");
        if (!is_array($row)) {
            self::markTestSkipped('needs the seeded receptionist user');
        }
        $csrf = $this->signIn('receptionist', Row::int($row, 'id'));
        [$status] = $this->post(['csrf_token_form' => $csrf, 'action' => 'rate', 'rating' => 'up', 'cache_key' => self::KEY, 'briefing_correlation_id' => self::BRIEFING_CID], new TestHandler());

        self::assertSame(403, $status);
        self::assertSame([], $this->ratings());
        self::assertStringContainsString('action=rate', $this->lastAuditComment());
        self::assertStringContainsString('denied=acl', $this->lastAuditComment());
    }
}
